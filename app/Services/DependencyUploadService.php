<?php

namespace App\Services;

use App\Jobs\DependencyUploadJob;
use App\Models\DependencyFile;
use App\Models\DependencyUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Http\Requests\DependencyUploadRequest;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Throwable;


class DependencyUploadService
{
    /**
     * Handle file uploads and initiate a vulnerability scan.
     *
     * This method processes and stores uploaded files, creates a scan record,
     * and dispatches background jobs to initiate vulnerability scans via Debricked.
     *
     * @param  DependencyUploadRequest  $request
     * @return array
     */
    public function handleUpload(DependencyUploadRequest $request): array
    {

        return DB::transaction(function () use ($request) {
            $files = is_array($request->file('files')) ? $request->file('files') : [$request->file('files')];

            // Create a record for the upload
            $upload = DependencyUpload::create([
                'user_id' => $request->user()->id,
                'commit_name' => $request->input('commit_name', 'default_commit'),
                'repository_name' => $request->input('repository_name', 'default_repo'),
                'status' => 'pending',
                'ci_upload_id' => null,
            ]);

            $fileMetadata = collect();

            foreach ($files as $file) {
                if (!$file || !$file->isValid()) {
                    throw new \RuntimeException('One or more uploaded files are invalid.');
                }

                // Store file
                $stored = $this->storeFile($file);

                // Save metadata
                $dependencyFile = DependencyFile::create([
                    'dependency_upload_id' => $upload->id,
                    'filename' => $stored['originalName'],
                    'path' => $stored['path'],
                    'vulnerabilities_found' => 0,
                    'progress' => 0,
                ]);

                $fileMetadata->push([
                    'filename' => $dependencyFile->filename,
                    'path' => Storage::url($dependencyFile->path),
                    'progress' => $dependencyFile->progress,
                    'vulnerabilities_found' => $dependencyFile->vulnerabilities_found,
                ]);
            }

            // Update upload status
            $upload->update([
                'file_paths' => $fileMetadata->pluck('path')->toArray(),
                'status' => 'in_progress',
            ]);

            // Dispatch background scan jobs
            $this->dispatchScanJobs($upload);

            return [
                'upload_id' => $upload->id,
                'status' => $upload->status,
                'repository' => $upload->repository_name,
                'commit' => $upload->commit_name,
                'files' => $fileMetadata,
            ];
        });
    }

    /**
     * Store the uploaded file in local disk and return metadata.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     * @return array
     */
    private function storeFile($file): array
    {
        $originalName = $file->getClientOriginalName();
        $uniqueName = now()->timestamp . '_' . Str::random(6) . '_' . $originalName;

        $path = $file->storeAs('dependencies', $uniqueName);

        if (!$path) {
            Log::error('File storage failed', ['file' => $originalName]);
            throw new \RuntimeException('Failed to store file: ' . $originalName);
        }

        Log::info('File stored', ['path' => $path]);

        return [
            'path' => $path,
            'originalName' => $originalName
        ];
    }

    /**
     * Dispatch background jobs to send uploaded files to Debricked API.
     *
     * @param  DependencyUpload  $upload
     * @return void
     */
    private function dispatchScanJobs(DependencyUpload $upload): void
    {
        // foreach ($upload->files as $file) {
        //     DependencyUploadJob::dispatch(
        //         $file,
        //         $upload
        //     )->onQueue('default');
        // }

        Bus::batch(
            collect($upload->files)->map(fn($file) => new DependencyUploadJob($file, $upload))->toArray()
        )
            ->then(function () use ($upload) {
                Log::info('All done!', ['upload_id' => $upload->id]);

                // Call service or API after all jobs succeed
                app(DebrickedApiService::class)->queueFileForScan($upload->ci_upload_id, $upload);

            })
            ->catch(function (Throwable $e) {
                Log::error('Batch failed', ['error' => $e->getMessage()]);
            })
            ->finally(function () {
                Log::info('Batch finished (either success or failure)');
            })
            ->onQueue('default')
            ->dispatch();
    }
}
