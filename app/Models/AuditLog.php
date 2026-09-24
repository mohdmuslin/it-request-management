<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The audit trail.
 *
 * Before/after as JSON, because the auditable surface spans a dozen tables and a
 * column-per-field design would need a new column for every future field.
 *
 * APPEND-ONLY. No update or delete path exists in the application, and none may be
 * added — NFR-006 requires critical records to be immutable to ordinary users, and
 * a record that can be edited is not evidence.
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'auditable_type',
        'auditable_id',
        'event',
        'old_values_json',
        'new_values_json',
        'request_id',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values_json' => 'array',
            'new_values_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Null for system actions. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItRequest::class, 'request_id');
    }

    /**
     * Which fields actually changed.
     *
     * Used to suppress noise: a save that touched an unrelated column should not
     * appear as a change to a field nobody edited.
     *
     * @return array<int, string>
     */
    public function changedFields(): array
    {
        $old = $this->old_values_json ?? [];
        $new = $this->new_values_json ?? [];

        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));

        return array_values(array_filter($keys, fn ($k) => ($old[$k] ?? null) !== ($new[$k] ?? null)));
    }

    /** A readable summary of an old→new change, for the audit screen. */
    public function summary(): string
    {
        $changed = $this->changedFields();

        if ($changed === []) {
            return '—';
        }

        return collect($changed)->map(function (string $field) {
            $old = $this->old_values_json[$field] ?? '—';
            $new = $this->new_values_json[$field] ?? '—';

            return "{$field}: {$this->stringify($old)} → {$this->stringify($new)}";
        })->implode('; ');
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}
