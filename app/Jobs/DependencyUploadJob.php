<?php

namespace App\Jobs;

use App\Services\DebrickedApiService;
use App\Notifications\ScanReportUploadFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Batchable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\DependencyFile;
use App\Models\DependencyUpload;
use Throwable;

class DependencyUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels,Batchable;

    protected $dependencyFile;

    protected $dependencyUpload;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\DependencyFile  $dependencyFile
     * @param  string  $commitName
     * @param  string  $repoName
     */
    public function __construct(DependencyFile $dependencyFile, DependencyUpload $dependencyUpload)
    {
        $this->dependencyFile = $dependencyFile;
        $this->dependencyUpload = $dependencyUpload;

    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $debrickedService = new DebrickedApiService();
        Log::info(self::class.'@handle', [
            'dependencyFile' => $this->dependencyFile->id,
            'commitName' => $this->dependencyUpload->commit_name,
            'repoName' => $this->dependencyUpload->repository_name,
        ]);

        // Upload the dependency file using Debricked API
        $debrickedService->uploadDependencyFile(
            $this->dependencyUpload,
            $this->dependencyFile
        );

        Log::info(self::class.'@handle', [
            'message' => 'Dependency file uploaded successfully',
            'dependencyFileId' => $this->dependencyFile->id,
        ]);

    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error(self::class.'@failed', ['error' => $exception->getMessage()]);
        DependencyUpload::find($this->dependencyFile->dependency_upload_id)
            ?->user
            ?->notify(new ScanReportUploadFailedNotification(
                $this->dependencyFile,
                $exception->getMessage()
            ));
       
    }
}
