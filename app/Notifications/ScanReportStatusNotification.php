<?php

namespace App\Notifications;

use App\Models\DependencyUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class ScanReportStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $dependencyUpload;

    /**
     * Create a new notification instance.
     */
    public function __construct(DependencyUpload $dependencyUpload)
    {
        $this->dependencyUpload = $dependencyUpload;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        Log::info('Preparing scan report status notification');

        $message = (new MailMessage)
            ->greeting('Hello, '.($notifiable->name ?? 'User').'!')
            ->subject('Scan In Progress')
            ->line('Your scan is in progress. Here are the details:')
            ->line('**Repo Name:** '.($this->dependencyUpload->repository_name ?? 'N/A'))
            ->line('**Commit Name:** '.($this->dependencyUpload->commit_name ?? 'N/A'))
            ->line('**Total Vulnerabilities Found So Far:** '.($this->dependencyUpload->vulnerability_count ?? '0'));

        // Add file details with progress information
        if ($this->dependencyUpload->files && count($this->dependencyUpload->files) > 0) {
            $message->line('**File Progress:**');
            foreach ($this->dependencyUpload->files as $file) {
                $filename = $file['filename'] ?? 'Unknown file';
                $vulnerabilities = $file['vulnerabilities_found'] ?? 0;
                $progress = $file['progress'] ?? 0;
                $message->line("• {$filename}: {$vulnerabilities} vulnerabilities found ({$progress}% complete)");
            }
        } else {
            $message->line('**File Progress:** No files being processed');
        }

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}