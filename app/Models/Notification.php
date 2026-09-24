<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded notification attempt.
 *
 * WHY EVERY SEND IS RECORDED
 *
 * The notification jobs run hourly and must be idempotent, which means each run
 * has to know what it already sent. Without this table an hourly reminder job is
 * an hourly spam job — and on this host there is no shell to inspect, so a failed
 * send that leaves no trace is indistinguishable from a notification that was
 * never due.
 */
class Notification extends Model
{
    use HasFactory;

    protected $table = 'notifications';

    protected $fillable = [
        'user_id',
        'request_id',
        'template',
        'channel',
        'status',
        'sent_at',
        'error',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItRequest::class, 'request_id');
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Whether this template has already been sent successfully.
     *
     * The idempotency check. Called before sending so a re-run does not duplicate.
     */
    public static function alreadySent(int $userId, ?int $requestId, string $template): bool
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('request_id', $requestId)
            ->where('template', $template)
            ->where('status', 'sent')
            ->exists();
    }
}
