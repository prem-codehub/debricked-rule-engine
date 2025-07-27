<?php

namespace App\Notifications;

use App\Models\DependencyUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScanReportUploadFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $dependencyFile;

    protected $errorMessage;

    /**
     * Create a new notification instance.
     */
    public function __construct($dependencyFile, $errorMessage)
    {
        $this->dependencyFile = $dependencyFile;
        $this->errorMessage = $errorMessage;
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
        $dependencyUpload = DependencyUpload::find($this->dependencyFile->dependency_upload_id);
        return (new MailMessage)
            ->greeting('Hello, '.($dependencyUpload?->user?->name ?? 'User').'!')
            ->subject('File Upload Failed in Commit: '.$dependencyUpload?->commit_name)
            ->line('The upload of your scan report failed. Please check the details and try again.')
            ->line('The following file in this commit has issues:')
            ->line('File Name: '.$this->dependencyFile->filename)
            ->line('Error: '.$this->errorMessage);

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

    // Optional: Specify a queue
    public function queue($notifiable)
    {
        return $this->onQueue('default');
    }
}
