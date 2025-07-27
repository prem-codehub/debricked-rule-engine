<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\DebrickedApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Http\Requests\DependencyUploadRequest;
use App\Models\DependencyFile;
use App\Models\DependencyUpload;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Jobs\DependencyUploadJob;
use Illuminate\Http\JsonResponse;

class DependencyUploadController extends Controller
{
    /**
     * Handle file uploads and initiate a vulnerability scan.
     *
     * This method processes and stores uploaded files, creates a scan record,
     * and dispatches background jobs to initiate vulnerability scans via the Debricked API.
     *
     * @param  DependencyUploadRequest  $request  The validated request containing files and metadata.
     * @return JsonResponse  A response indicating success or failure.
     */
    public function store(DependencyUploadRequest $request): JsonResponse
    {

        DB::beginTransaction();

        try {
            $uploadedFiles = $request->file('files');
            $files = is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles];

            // Create a record for the upload
            $dependencyUpload = DependencyUpload::create([
                'user_id' => $request->user()->id,
                'commit_name' => $request->input('commit_name') ?? 'default_commit',
                'repository_name' => $request->input('repository_name') ?? 'default_repo',
                'status' => 'pending',
            ]);

            $paths = [];

            foreach ($files as $file) {
                if (!$file || !$file->isValid()) {
                    return response()->json([
                        'error' => 'One or more uploaded files are invalid.'
                    ], 422);
                }

                // Generate unique filename to avoid overwriting
                $originalName = $file->getClientOriginalName();
                $uniqueSuffix = now()->timestamp . '_' . Str::random(6);
                $filename = $uniqueSuffix . '_' . $originalName;

                $path = $file->storeAs('dependencies', $filename);

                if (!$path) {
                    Log::error('Failed to store file', [
                        'file' => $originalName
                    ]);

                    return response()->json([
                        'error' => 'Failed to store one or more files.'
                    ], 500);
                }

                Log::info('File stored successfully', [
                    'path' => $path,
                    'file' => $originalName
                ]);

                // Save metadata for each uploaded file
                DependencyFile::create([
                    'dependency_upload_id' => $dependencyUpload->id,
                    'filename' => $originalName,
                    'path' => $path,
                    'vulnerabilities_found' => 0,
                    'progress' => 0,
                    'raw_data' => [],
                ]);

                $paths[] = Storage::url($path);
            }

            // Update the upload record with stored file paths and mark as complete
            $dependencyUpload->update([
                'file_paths' => $paths,
                'status' => 'completed',
            ]);

            DB::commit();

            // Start async scan via Debricked for each file
            $this->uploadAllFilesForDebrickedApi($dependencyUpload);

            return response()->json([
                'message' => 'Files uploaded successfully.',
                'upload_paths' => $paths,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Exception during file upload', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'An unexpected error occurred during file upload.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Dispatch background jobs to send uploaded files to Debricked API.
     *
     * Each file associated with the upload will be queued for scanning.
     *
     * @param  DependencyUpload  $dependencyUpload  The upload record containing files to scan.
     */
    protected function uploadAllFilesForDebrickedApi(DependencyUpload $dependencyUpload): void
    {
        foreach ($dependencyUpload->files as $attachment) {
            DependencyUploadJob::dispatch(
                $attachment,
                $dependencyUpload->commit_name,
                $dependencyUpload->repository_name
            )->onQueue('default');
        }
    }
}
