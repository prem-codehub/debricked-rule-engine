<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\DebrickedApiService;
use App\Models\DependencyUpload;
use App\Notifications\ScanReportCompletedNotification;
use App\Notifications\ScanReportStatusNotification;
use Illuminate\Support\Facades\Notification;
use App\Notifications\SlackNotification;

class DependencyScanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:dependency-scan';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check scan progress of dependency uploads and notify status.';

    /**
     * Execute the console command.
     */
    public function handle(DebrickedApiService $api): void
    {
        $this->info('Starting dependency scan...');

        DependencyUpload::with('user')
            ->where('status', '!=', 'completed')
            ->whereNotNull('ci_upload_id')
            ->chunk(100, function ($uploads) use ($api) {
                foreach ($uploads as $upload) {
                    $this->info("Processing upload repository: {$upload->repository_name}, commit: {$upload->commit_name}, user: {$upload->user?->name}");

                    try {
                        $response = $api->getUploadStatus($upload->ci_upload_id)->json();

                        $progress = $response['progress'] ?? 0;
                        $vulnerabilitiesFound = $response['vulnerabilitiesFound'] ?? 0;

                        $upload->update([
                            'progress' => $progress,
                            'vulnerability_count' => $vulnerabilitiesFound,
                        ]);

                        Log::info('Scan status updated', [
                            'upload_id' => $upload->id,
                            'progress' => $progress,
                            'vulnerabilities_found' => $vulnerabilitiesFound,
                        ]);

                        $this->info("Upload ID {$upload->id} - Progress: {$progress}% | Vulnerabilities: {$vulnerabilitiesFound}");

                        if ($progress >= 100) {
                            $upload->update(['status' => 'completed']);
                            $upload->user?->notify(new ScanReportCompletedNotification($upload));

                            Log::info('Scan completed', ['upload_id' => $upload->id]);
                        } else {
                            $upload->user?->notify(new ScanReportStatusNotification($upload));
                            Log::info('Scan still in progress', ['upload_id' => $upload->id]);
                        }

                        Notification::route('slack', config('services.slack.webhook_url'))
                            ->route('mail', $upload->user?->email)
                            ->notify(new SlackNotification("Upload repository: {$upload->repository_name} processed. Total vulnerabilities found: {$vulnerabilitiesFound}"));

                    } catch (\Throwable $e) {
                        Log::error('Error checking scan status', [
                            'upload_id' => $upload->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
