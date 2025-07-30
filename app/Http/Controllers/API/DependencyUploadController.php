<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DependencyUploadRequest;
use App\Services\DependencyUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DependencyUploadController extends Controller
{
    /**
     * Class Constructor
     *
     * Inject the DependencyUploadService using dependency injection.
     *
     * @param DependencyUploadService $uploadService
     */
    public function __construct(
        protected DependencyUploadService $uploadService
    ) {}

    /**
     * API: Upload Dependency Files and Initiate Vulnerability Scan
     *
     * @command: Handles multi-file upload from frontend.
     * - Validates and stores each file under `dependencies/` directory.
     * - Creates a DependencyUpload and DependencyFile entry in DB.
     * - Triggers async job to scan each file using Debricked API.
     *
     * @route: POST /api/dependencies/upload
     * @request: DependencyUploadRequest (expects `files[]`, `commit_name`, `repository_name`)
     * @return JsonResponse
     */
    public function store(DependencyUploadRequest $request): JsonResponse
    {
        try {
            $result = $this->uploadService->handleUpload($request);

            return response()->json([
                'message' => 'Files uploaded successfully.',
                ...$result
            ]);

        } catch (\Throwable $e) {
            Log::error('Upload failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'An unexpected error occurred during file upload.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
