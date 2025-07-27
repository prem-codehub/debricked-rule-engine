<?php

namespace App\Jobs;

use App\Services\DebrickedApiService;
use App\Notifications\ScanReportUploadFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\DependencyFile;
use Throwable;

class DependencyUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $dependencyFile;

    protected $commitName;

    protected $repoName;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\DependencyFile  $dependencyFile
     * @param  string  $commitName
     * @param  string  $repoName
     */
    public function __construct(DependencyFile $dependencyFile, string $commitName, string $repoName)
    {
        $this->dependencyFile = $dependencyFile;
        $this->commitName = $commitName;
        $this->repoName = $repoName;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $debrickedService = new DebrickedApiService();
        Log::info(self::class.'@handle', [
            'dependencyFile' => $this->dependencyFile->id,
            'commitName' => $this->commitName,
            'repoName' => $this->repoName,
        ]);

        // Upload the dependency file using Debricked API
        $debrickedService->uploadDependencyFile(
            $this->dependencyFile,
            $this->commitName,
            $this->repoName
        );
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error(self::class.'@failed', ['error' => $exception->getMessage()]);
        $this->dependencyFile->upload()->user->notify(new ScanReportUploadFailedNotification(
            $this->dependencyFile,
            $exception->getMessage()
        ));
    }
}
