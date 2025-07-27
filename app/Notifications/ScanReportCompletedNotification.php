<?php

namespace App\Notifications;

use App\Models\DependencyUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScanReportCompletedNotification extends Notification implements ShouldQueue
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
        $message = (new MailMessage)
            ->greeting('Hello, '.($notifiable->name ?? 'User').'!')
            ->subject('Scan Completed')
            ->line('Your scan is completed. Here are the details:')
            ->line('**Repo Name:** '.($this->dependencyUpload->repository_name ?? 'N/A'))
            ->line('**Commit Name:** '.($this->dependencyUpload->commit_name ?? 'N/A'))
            ->line('**Total Vulnerabilities Found:** '.($this->dependencyUpload->vulnerability_count ?? '0'));

        // Add file details in a readable format
        if ($this->dependencyUpload->files && count($this->dependencyUpload->files) > 0) {
            $message->line('**File Details:**');
            foreach ($this->dependencyUpload->files as $file) {
                $filename = $file['filename'] ?? 'Unknown file';
                $vulnerabilities = $file['vulnerabilities_found'] ?? 0;
                $message->line("• {$filename}: {$vulnerabilities} vulnerabilities");
            }
        } else {
            $message->line('**File Details:** No files processed');
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