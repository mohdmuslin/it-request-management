<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comment on a request.
 *
 * `is_internal` is the brief's §6.3 requirement to keep applicant content separate
 * from internal governance discussion. A requestor must never see an internal
 * note, and that is enforced in the query — a hidden tab is not a control.
 */
class Comment extends Model
{
    use HasFactory;

    protected $fillable = ['request_id', 'user_id', 'body', 'is_internal'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItRequest::class, 'request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
