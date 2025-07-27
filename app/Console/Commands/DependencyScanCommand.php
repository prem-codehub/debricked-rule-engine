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
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(DebrickedApiService $api): void
    {
        $this->info('Starting dependency scan...');

        DependencyUpload::with(['user', 'files'])
            ->where('status', '!=', 'completed')
            ->chunk(100, function ($uploads) use ($api) {
                foreach ($uploads as $upload) {
                    $this->info("Processing upload repository: {$upload->repository_name} commit: {$upload->commit_name} user: {$upload->user?->nam}");

                    $completed = true;
                    $totalVulns = 0;

                    foreach ($upload->files as $file) {
                        if (!$file->ci_upload_id) {
                            continue;
                        }

                        try {
                            // Check the scan status for the file using Debricked API
                            $response = $api->getUploadStatus($file->ci_upload_id)->json();

                            $file->update([
                                'progress' => $response['progress'] ?? 0,
                                'vulnerabilities_found' => $response['vulnerabilitiesFound'] ?? 0,
                            ]);

                            $totalVulns += $file->vulnerabilities_found;

                            if (($response['progress'] ?? 0) < 100) {
                                $completed = false;
                            }
                            Log::info('Scan status updated for file', [
                                'file_id' => $file->id,
                                'progress' => $file->progress,
                                'vulnerabilities_found' => $file->vulnerabilities_found,
                            ]);

                            $this->info("File {$file->id} processed. Progress: {$file->progress} Vulnerabilities found: {$file->vulnerabilities_found}");
                        } catch (\Throwable $e) {
                            Log::error('Error checking scan status', [
                                'file_id' => $file->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $upload->update([
                        'vulnerability_count' => $totalVulns,
                    ]);

                    if ($completed) {
                        $upload->update(['status' => 'completed']);
                        Log::info('Scan completed for upload', [
                            'upload_id' => $upload->id,
                            'vulnerability_count' => $totalVulns,
                        ]);
                        // Notify user about completed scan
                        $upload->user?->notify(new ScanReportCompletedNotification($upload));
                    } else {
                        Log::info('Scan in progress for upload', [
                            'upload_id' => $upload->id,
                        ]);
                        // Notify user about scan in progress
                        $upload->user?->notify(new ScanReportStatusNotification($upload));
                    }

                    // Notify via Slack
                    Notification::route('slack', config('services.slack.webhook_url'))
                        ->route('mail', $upload->user?->email)
                        ->notify(new SlackNotification("Upload repository: {$upload->repository_name} processed. Total vulnerabilities found: {$totalVulns}"));

                    $this->info("Upload repository: {$upload->repository_name} processed. Total vulnerabilities found: {$totalVulns}");
                }
            });
    }
}
