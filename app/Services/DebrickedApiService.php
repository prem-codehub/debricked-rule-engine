<?php

namespace App\Services;

use App\Models\DependencyFile;
use App\Models\DependencyUpload;
use Exception;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Rules\RuleManager;

class DebrickedApiService
{
    private const BASE_URL = 'https://debricked.com/api/';

    private const ENDPOINTS = [
        'supported_formats' => '1.0/open/files/supported-formats',
        'login' => 'login_check',
        'upload_dependencies' => '1.0/open/uploads/dependencies/files',
        'queue_scan' => '1.0/open/finishes/dependencies/files/uploads',
        'upload_status' => '1.0/open/ci/upload/status',
    ];

    private ?string $token = null;

    /**
     * Constructor that automatically authenticates with the Debricked API.
     *
     * @throws Exception When authentication fails or credentials are not configured
     */
    public function __construct()
    {
        $this->authenticate();
    }

    /**
     * Retrieve supported file formats from the Debricked API.
     *
     * @return array<string, mixed>
     */
    public function getSupportedFileFormats(): array
    {
        try {
            $response = Http::timeout(30)
                ->get($this->buildUrl('supported_formats'));

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $this->logError('getSupportedFileFormats', $response);
        } catch (Exception $e) {
            $this->logException('getSupportedFileFormats', $e);
        }

        return [];
    }

    /**
     * Extract and combine regex patterns for supported file formats.
     *
     * @param array<string, mixed> $patterns
     * @return array<string>
     */
    public function extractRegexPatterns(array $patterns): array
    {
        $regexPatterns = [];

        try {
            foreach ($patterns as $pattern) {
                // Add main regex pattern if exists
                if (!empty($pattern['regex']) && is_string($pattern['regex'])) {
                    $regexPatterns[] = $pattern['regex'];
                }

                // Add lock file regex patterns if they exist
                if (!empty($pattern['lockFileRegexes']) && is_array($pattern['lockFileRegexes'])) {
                    $validLockFileRegexes = array_filter(
                        $pattern['lockFileRegexes'],
                        fn($regex) => !empty($regex) && is_string($regex)
                    );
                    $regexPatterns = array_merge($regexPatterns, $validLockFileRegexes);
                }
            }

            // Remove duplicates and re-index array
            return array_values(array_unique($regexPatterns));
        } catch (Exception $e) {
            $this->logException('extractRegexPatterns', $e);
            return [];
        }
    }

    /**
     * Upload dependency file to the Debricked API.
     *
     * @param DependencyUpload $upload The upload record
     * @param DependencyFile $file The file attachment to upload
     * @return array<string, mixed> Upload response data
     * @throws Exception If the upload fails or token is not available
     */
    public function uploadDependencyFile(
        DependencyUpload $upload,
        DependencyFile $file
    ): array {
        $this->ensureAuthenticated();
        $this->validateUploadParameters($file, $upload->commit_name, $upload->repository_name);

        try {
            $fileContent = Storage::get($file->path);

            if (!$fileContent) {
                throw new Exception("Failed to read file content from: {$file->path}");
            }

            $payload = [
                'commitName' => $upload->commit_name,
                'repositoryName' => $upload->repository_name,
            ];

            if ($upload->ci_upload_id) {
                $payload['ciUploadId'] = $upload->ci_upload_id;
            }

            $response = Http::timeout(60)
                ->withHeaders(['Authorization' => "Bearer {$this->token}"])
                ->attach('fileData', $fileContent, $file->filename)
                ->post($this->buildUrl('upload_dependencies'), $payload);

            if (!$response->successful()) {
                throw new Exception("Upload failed with status {$response->status()}: {$response->body()}");
            }

            $responseData = $response->json();

            // Log the response for debugging
            Log::info('Dependency file uploaded successfully', [
                'ci_upload_id' => $upload->ci_upload_id ?? $responseData['ciUploadId'],
                'file' => $file->filename,
                'response' => $responseData,
            ]);

            // If it's the first file upload, store ci_upload_id on the DependencyUpload model
            if (!$upload->ci_upload_id && !empty($responseData['ciUploadId'])) {
                $upload->update(['ci_upload_id' => $responseData['ciUploadId']]);
            }

            Log::info('Dependency file uploaded successfully', [
                'upload_id' => $upload->id,
                'file_id' => $file->id,
                'ci_upload_id' => $upload->ci_upload_id ?? $responseData['ciUploadId'],
            ]);

            // Queue for scanning
            //$this->queueFileForScan($upload->ci_upload_id ?? $responseData['ciUploadId'], $upload->id);

            return $responseData;
        } catch (Exception $e) {
            $this->logException('uploadDependencyFile', $e);
            throw $e;
        }
    }

    /**
     * Queue a file for scanning in the Debricked system.
     *
     * @param int $ciUploadId The CI upload ID to associate with the file
     * @param DependencyUpload $dependencyUpload The dependency upload record
     * @return array<string, mixed> Queue response data
     * @throws Exception If the API request fails or token is not available
     */
    public function queueFileForScan(int $ciUploadId, DependencyUpload $dependencyUpload): array
    {
        $this->ensureAuthenticated();

        if (empty($ciUploadId)) {
            throw new Exception('Valid CI upload ID is required');
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->token}",
                    'Accept' => 'application/json',
                ])
                ->post($this->buildUrl('queue_scan'), [
                    'ciUploadId' => $ciUploadId,
                ]);

            if (!$response->successful()) {
                throw new Exception("Queue scan failed with status {$response->status()}: {$response->body()}");
            }

            $responseData = $response->json() ?? [];

            Log::info('File queued for scanning successfully', [
                'ci_upload_id' => $ciUploadId,
                'response' => $responseData,
            ]);

            // Trigger rule evaluation after queuing for scan
            $this->triggerRuleEvaluation($dependencyUpload);

            return $responseData;
        } catch (Exception $e) {
            $this->logException('queueFileForScan', $e);
            throw $e;
        }
    }

    /**
     * Get the current status of the CI upload.
     *
     * @param string $ciUploadId The CI upload ID to check the status for
     * @return Response
     * @throws Exception If the status check fails or token is not available
     */
    public function getUploadStatus(string $ciUploadId): Response
    {
        $this->ensureAuthenticated();

        if (empty($ciUploadId)) {
            throw new Exception('CI upload ID is required');
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders(['Authorization' => "Bearer {$this->token}"])
                ->get($this->buildUrl('upload_status'), [
                    'ciUploadId' => $ciUploadId,
                ]);

            if ($response->failed()) {
                throw new Exception("Status check failed with status {$response->status()}: {$response->body()}");
            }

            Log::info('Upload status retrieved successfully', [
                'ci_upload_id' => $ciUploadId,
                'status' => $response->json(),
            ]);

            return $response;
        } catch (Exception $e) {
            $this->logException('getUploadStatus', $e);
            throw $e;
        }
    }

    /**
     * Get the current authentication token.
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * Check if the service is authenticated.
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return !empty($this->token);
    }

    /**
     * Re-authenticate with the API (useful if token expires).
     *
     * @return void
     * @throws Exception When authentication fails
     */
    public function refreshAuthentication(): void
    {
        $this->token = null;
        $this->authenticate();
    }

    /**
     * Obtain a bearer token from the Debricked API and store it.
     *
     * @return void
     * @throws Exception When credentials are not configured or authentication fails
     */
    private function authenticate(): void
    {
        $username = config('services.debricked_api.username');
        $password = config('services.debricked_api.password');

        if (empty($username) || empty($password)) {
            throw new Exception('Debricked API credentials are not configured');
        }

        try {
            $response = Http::timeout(30)
                ->asForm()
                ->post($this->buildUrl('login'), [
                    '_username' => $username,
                    '_password' => $password,
                ]);

            if ($response->successful()) {
                $token = $response->json('token');

                if (empty($token)) {
                    throw new Exception('Authentication succeeded but no token received');
                }

                $this->token = $token;

                Log::info('DebrickedApiService authenticated successfully');
                return;
            }

            $this->logError('authenticate', $response);
            throw new Exception('Authentication failed');
        } catch (Exception $e) {
            $this->logException('authenticate', $e);
            throw $e;
        }
    }

    /**
     * Ensure the service is authenticated before making API calls.
     *
     * @throws Exception If not authenticated
     */
    private function ensureAuthenticated(): void
    {
        if (empty($this->token)) {
            throw new Exception('Service is not authenticated. Token is missing.');
        }
    }

    /**
     * Build full URL for API endpoint.
     *
     * @param string $endpoint
     * @return string
     */
    private function buildUrl(string $endpoint): string
    {
        $path = self::ENDPOINTS[$endpoint] ?? $endpoint;
        return self::BASE_URL . $path;
    }

    /**
     * Validate upload parameters.
     *
     * @param DependencyFile $attachment
     * @param string $commitName
     * @param string $repositoryName
     * @throws Exception If validation fails
     */
    private function validateUploadParameters(
        DependencyFile $attachment,
        string $commitName,
        string $repositoryName
    ): void {
        if (empty($attachment->path)) {
            throw new Exception('Attachment path is required');
        }

        if (empty($attachment->filename)) {
            throw new Exception('Attachment file name is required');
        }

        if (empty($commitName)) {
            throw new Exception('Commit name is required');
        }

        if (empty($repositoryName)) {
            throw new Exception('Repository name is required');
        }

        if (!Storage::exists($attachment->path)) {
            throw new Exception("File does not exist at path: {$attachment->path}");
        }
    }

    /**
     * Log error details from an HTTP response.
     *
     * @param string $method The method where the error occurred
     * @param Response $response The HTTP response
     */
    private function logError(string $method, Response $response): void
    {
        Log::error("DebrickedApiService@{$method} failed", [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
            'request_url' => $response->effectiveUri()?->__toString(),
        ]);
    }

    /**
     * Log exception details.
     *
     * @param string $method The method where the exception occurred
     * @param Exception $exception The caught exception
     */
    private function logException(string $method, Exception $exception): void
    {
        Log::error("DebrickedApiService@{$method} encountered an exception", [
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    private function triggerRuleEvaluation($dependencyUpload): void
    {
        // Logic to trigger rule evaluation
        RuleManager::evaluate($dependencyUpload);
    }
}
