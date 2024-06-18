<?php

namespace App\Models;

use App\Jobs\BackupTasks\SendDiscordNotificationJob;
use App\Jobs\BackupTasks\SendSlackNotificationJob;
use App\Jobs\RunDatabaseBackupTaskJob;
use App\Jobs\RunFileBackupTaskJob;
use App\Mail\BackupTasks\OutputMail;
use App\Traits\HasTags;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BackupTask extends Model
{
    use HasFactory, HasTags;

    const string STATUS_READY = 'ready';

    const string STATUS_RUNNING = 'running';

    const string FREQUENCY_DAILY = 'daily';

    const string FREQUENCY_WEEKLY = 'weekly';

    const string TYPE_FILES = 'files';

    const string TYPE_DATABASE = 'database';

    protected $guarded = [];

    protected $casts = [
        'last_run_at' => 'datetime',
        'last_scheduled_weekly_run_at' => 'datetime',
    ];

    public static function logsCountPerMonthForLastSixMonths(int $userId): array
    {
        $sixMonthsAgo = now()->subMonths(6);

        return BackupTaskLog::query()
            ->join('backup_tasks', 'backup_tasks.id', '=', 'backup_task_logs.backup_task_id')
            ->where('backup_tasks.user_id', $userId)
            ->where('backup_task_logs.created_at', '>=', $sixMonthsAgo)
            ->selectRaw('COUNT(*) as count, to_char(backup_task_logs.created_at, \'Mon YYYY\') as month')
            ->groupBy('month')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item['month'] => $item['count']];
            })
            ->toArray();
    }

    public static function backupTasksCountByType(int $userId): array
    {
        return self::query()
            ->where('user_id', $userId)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item['type'] => $item['count']];
            })
            ->toArray();
    }

    public function scopeNotPaused($query): Builder
    {
        return $query->whereNull('paused_at');
    }

    public function scopeReady($query): Builder
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function backupDestination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class);
    }

    public function remoteServer(): BelongsTo
    {
        return $this->belongsTo(RemoteServer::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BackupTaskLog::class);
    }

    public function updateLastRanAt(): void
    {
        $this->update(['last_run_at' => now()]);
        $this->save();
    }

    public function usingCustomCronExpression(): bool
    {
        return ! is_null($this->custom_cron_expression);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function markAsRunning(): void
    {
        $this->update(['status' => 'running']);
        $this->save();
    }

    public function markAsReady(): void
    {
        $this->update(['status' => 'ready']);
        $this->save();
    }

    public function isTheRightTimeToRun(): bool
    {
        if ($this->isDaily()) {
            return $this->time_to_run_at === now()->format('H:i');
        }

        if ($this->isWeekly()) {
            if ($this->time_to_run_at === now()->format('H:i') && $this->last_scheduled_weekly_run_at === null) {
                return true;
            }

            if ($this->time_to_run_at === now()->format('H:i') && $this->last_scheduled_weekly_run_at?->isLastWeek()) {
                return true;
            }
        }

        return false;
    }

    public function eligibleToRunNow(): bool
    {
        if ($this->isRunning()) {
            return false;
        }

        if ($this->isPaused()) {
            return false;
        }

        if ($this->isReady() && $this->usingCustomCronExpression()) {
            return $this->cronExpressionMatches();
        }

        if ($this->isReady() && ! $this->usingCustomCronExpression()) {
            return $this->isTheRightTimeToRun();
        }

        return false;
    }

    public function cronExpressionMatches(): bool
    {
        return $this->cronExpression()->isDue();
    }

    public function isDaily(): bool
    {
        return $this->frequency === self::FREQUENCY_DAILY;
    }

    public function isWeekly(): bool
    {
        return $this->frequency === self::FREQUENCY_WEEKLY;
    }

    public function updateScheduledWeeklyRun(): void
    {
        if (! $this->isWeekly()) {
            return;
        }

        $this->update(['last_scheduled_weekly_run_at' => now()]);
        $this->save();
    }

    public function isRotatingBackups(): bool
    {
        return $this->maximum_backups_to_keep > 0;
    }

    public function run(): void
    {
        if ($this->isPaused()) {
            Log::debug("Task {$this->id} is paused, skipping run");

            return;
        }

        if ($this->isAnotherTaskRunningOnSameRemoteServer()) {
            Log::debug('Another task is running on the same remote server, skipping run for task ' . $this->id . ' for now.');

            return;
        }

        if ($this->isFilesType()) {
            RunFileBackupTaskJob::dispatch($this->id)
                ->onQueue('backup-tasks');
        }

        if ($this->isDatabaseType()) {
            RunDatabaseBackupTaskJob::dispatch($this->id)
                ->onQueue('backup-tasks');
        }
    }

    public function isFilesType(): bool
    {
        return $this->type === self::TYPE_FILES;
    }

    public function isDatabaseType(): bool
    {
        return $this->type === self::TYPE_DATABASE;
    }

    public function calculateNextRun(): ?Carbon
    {
        if (is_null($this->frequency) && $this->custom_cron_expression) {
            $cron = new CronExpression($this->custom_cron_expression);

            return Carbon::instance($cron->getNextRunDate(Carbon::now()));
        }

        if ($this->frequency === self::FREQUENCY_DAILY) {
            $nextRun = Carbon::today()->setTimeFromTimeString($this->time_to_run_at);

            if ($nextRun->lte(Carbon::now())) {
                $nextRun->addDay();
            }

            return $nextRun;
        }

        if ($this->frequency === self::FREQUENCY_WEEKLY) {

            if ($this->last_scheduled_weekly_run_at) {
                return Carbon::parse($this->last_scheduled_weekly_run_at)->addWeek();
            }

            return Carbon::today()->addDays(7 - Carbon::today()->dayOfWeek + Carbon::parse($this->time_to_run_at)->dayOfWeek)
                ->setTimeFromTimeString($this->time_to_run_at);
        }

        return null;
    }

    public function pause(): void
    {
        $this->update(['paused_at' => now()]);
        $this->save();
    }

    public function resume(): void
    {
        $this->update(['paused_at' => null]);
        $this->save();
    }

    public function isPaused(): bool
    {
        return ! is_null($this->paused_at);
    }

    public function hasFileNameAppended(): bool
    {
        return ! is_null($this->appended_file_name);
    }

    public function setScriptUpdateTime(): void
    {
        $this->update(['last_script_update_at' => now()]);
        $this->saveQuietly();
    }

    public function resetScriptUpdateTime(): void
    {
        $this->update(['last_script_update_at' => null]);
        $this->save();
    }

    public function hasNotifyEmail(): bool
    {
        return ! is_null($this->notify_email);
    }

    public function hasNotifyDiscordWebhook(): bool
    {
        return ! is_null($this->notify_discord_webhook);
    }

    public function hasNotifySlackWebhook(): bool
    {
        return ! is_null($this->notify_slack_webhook);
    }

    public function sendNotifications(): void
    {
        $latestLog = $this->fresh()?->logs()->latest()->first();
        $wasSuccessful = $latestLog?->successful_at;
        // if we want to only send notifications on failure in the future ^^

        if ($this->hasNotifyEmail()) {
            $this->sendEmailNotification($latestLog);
        }

        if ($this->hasNotifyDiscordWebhook()) {
            SendDiscordNotificationJob::dispatch($this, $latestLog)
                ->onQueue('backup-task-notifications');
        }

        if ($this->hasNotifySlackWebhook()) {
            SendSlackNotificationJob::dispatch($this, $latestLog)
                ->onQueue('backup-task-notifications');
        }
    }

    public function sendEmailNotification($latestLog): void
    {
        Mail::to($this->notify_email)
            ->queue(new OutputMail($latestLog));
    }

    public function sendDiscordWebhookNotification($latestLog): void
    {
        $status = $latestLog?->successful_at ? 'success' : 'failure';
        $message = $latestLog?->successful_at ? 'The backup task was successful. Please see the details below for more information about this task.' : 'The backup task failed. Please see the details below for more information about this task.';
        $color = $latestLog?->successful_at ? 3066993 : 15158332; // Green for success, Red for failure

        $embed = [
            'title' => $this->label . ' Backup Task',
            'description' => $message,
            'color' => $color,
            'fields' => [
                [
                    'name' => __('Backup Type'),
                    'value' => ucfirst($this->type),
                    'inline' => true,
                ],
                [
                    'name' => __('Remote Server'),
                    'value' => $this->remoteServer?->label,
                    'inline' => true,
                ],
                [
                    'name' => __('Backup Destination'),
                    'value' => $this->backupDestination?->label . ' (' . $this->backupDestination?->type() . ')',
                    'inline' => true,
                ],
                [
                    'name' => __('Result'),
                    'value' => ucfirst($status),
                    'inline' => true,
                ],
                [
                    'name' => __('Ran at'),
                    'value' => $latestLog?->created_at->format('jS F Y, H:i:s'),
                    'inline' => true,
                ],
            ],
            'footer' => [
                'icon_url' => asset('images/logo.png'),
                'text' => __('This notification was sent by :app.', ['app' => config('app.name')]),
            ],
        ];

        $http = Http::withHeaders([
            'Content-Type' => 'application/json',
        ]);

        $http->post($this->notify_discord_webhook, [
            'username' => __('Vanguard'),
            'avatar_url' => asset('images/logo-on-black.png'),
            'embeds' => [$embed],
        ]);
    }

    public function sendSlackWebhookNotification($latestLog): void
    {
        $status = $latestLog?->successful_at ? 'success' : 'failure';
        $message = $latestLog?->successful_at ? 'The backup task was successful. Please see the details below for more information about this task.' : 'The backup task failed. Please see the details below for more information about this task.';
        $color = $latestLog?->successful_at ? 'good' : 'danger'; // Green for success, Red for failure

        $payload = [
            'attachments' => [
                [
                    'title' => $this->label . ' Backup Task',
                    'text' => $message,
                    'color' => $color,
                    'fields' => [
                        [
                            'title' => __('Backup Type'),
                            'value' => ucfirst($this->type),
                            'short' => true,
                        ],
                        [
                            'title' => __('Remote Server'),
                            'value' => $this->remoteServer?->label,
                            'short' => true,
                        ],
                        [
                            'title' => __('Backup Destination'),
                            'value' => $this->backupDestination?->label . ' (' . $this->backupDestination?->type() . ')',
                            'short' => true,
                        ],
                        [
                            'title' => __('Result'),
                            'value' => ucfirst($status),
                            'short' => true,
                        ],
                        [
                            'title' => __('Ran at'),
                            'value' => $latestLog?->created_at->format('jS F Y, H:i:s'),
                            'short' => true,
                        ],
                    ],
                    'footer' => __('This notification was sent by :app.', ['app' => config('app.name')]),
                ],
            ],
        ];

        $http = Http::withHeaders([
            'Content-Type' => 'application/json',
        ]);

        $http->post($this->notify_slack_webhook, $payload);
    }

    public function hasCustomStorePath(): bool
    {
        return ! is_null($this->store_path);
    }

    public function isAnotherTaskRunningOnSameRemoteServer(): bool
    {
        return static::query()
            ->where('remote_server_id', $this->remote_server_id)
            ->where('status', static::STATUS_RUNNING)
            ->where('id', '<>', $this->id)
            ->exists();
    }

    public function listOfAttachedTagLabels(): ?string
    {
        if ($this->tags->isEmpty()) {
            return null;
        }

        return $this->tags->pluck('label')->implode(', ');
    }

    private function cronExpression(): CronExpression
    {
        return new CronExpression($this->custom_cron_expression);
    }
}
