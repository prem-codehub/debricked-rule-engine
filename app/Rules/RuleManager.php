<?php
namespace App\Rules;

use App\Models\DependencyFile;
use App\Models\DependencyUpload;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ScanReportStatusNotification;


class RuleManager
{
    public static function evaluate(DependencyFile $upload): void
    {
        $rules = static::rules();

        foreach ($rules as $rule) {
            if (($rule['trigger'])($upload)) {
                ($rule['action'])($upload);
            }
        }
    }

    protected static function rules(): array
    {
        return [
            [
                'trigger' => fn($upload) => $upload->vulnerabilities_count > 5,
                'action' => fn($upload) => Notification::route('mail', $upload->user->email)
                    ->notify(new ScanReportStatusNotification($upload)),
            ],
            [
                'trigger' => fn($upload) => $upload->status === 'in_progress',
                'action' => fn($upload) => Notification::route('mail', $upload->user->email)
                    ->notify(new ScanReportStatusNotification($upload)),
            ],
        ];
    }
}
