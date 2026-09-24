<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ItRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records who changed what.
 *
 * WHY THIS EXISTS SEPARATELY FROM workflow_histories
 *
 * `workflow_histories` answers "how did this request move?" — a business question,
 * read by the status timeline and by a requestor looking at their own request.
 *
 * This answers "who changed what value, and to what?" — a compliance question,
 * read during a dispute.
 *
 * Merging them makes the timeline either unreadably detailed or the audit
 * incomplete, and they have different immutability needs.
 *
 * APPEND-ONLY. There is no update or delete path here, and none may be added —
 * NFR-006 requires critical records to be immutable to ordinary users, and a
 * record that can be edited is not evidence.
 */
class AuditService
{
    /**
     * Record a change to a model.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(
        string $event,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?int $requestId = null,
        ?int $userId = null,
    ): AuditLog {
        return AuditLog::create([
            /*
             * Nullable for system actions.
             *
             * "All recommendations are in, so this advanced" has no human actor.
             * Forcing a user id would mean inventing one, and a fabricated actor in
             * an audit trail is worse than an honest null.
             */
            'user_id' => $userId ?? Auth::id(),
            'auditable_type' => $subject ? $subject::class : 'system',
            'auditable_id' => $subject?->getKey() ?? 0,
            'event' => $event,
            'old_values_json' => $old,
            'new_values_json' => $new,
            'request_id' => $requestId ?? ($subject instanceof ItRequest ? $subject->getKey() : null),
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
        ]);
    }

    /**
     * Record only the fields that actually changed.
     *
     * WHY THIS IS THE PREFERRED ENTRY POINT
     *
     * Saving a model touches `updated_at` and often several columns that did not
     * change. Recording all of them makes the audit log unreadable — the reader has
     * to work out which of fifteen entries is the one that matters.
     *
     * Returns null when nothing changed, so a no-op save does not create an audit
     * row at all.
     *
     * @param  array<string, mixed>  $before  Attribute values prior to the change
     */
    public function recordChanges(string $event, Model $subject, array $before, ?int $requestId = null): ?AuditLog
    {
        $after = $subject->getAttributes();

        // Never audit these: they change on every save and carry no meaning.
        $ignored = ['updated_at', 'created_at', 'remember_token', 'password', 'updated_by'];

        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            if (in_array($key, $ignored, true)) {
                continue;
            }

            $previous = $before[$key] ?? null;

            if ($previous !== $value) {
                $old[$key] = $previous;
                $new[$key] = $value;
            }
        }

        if ($old === [] && $new === []) {
            return null;
        }

        return $this->record($event, $subject, $old, $new, $requestId);
    }
}
