<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A configuration value an administrator can change without a deployment.
 *
 * Where this and config/itrequest.php overlap, the config file documents the
 * default and this table holds the current answer.
 */
class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'type', 'group'];

    /** The value, cast according to its declared type. */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            'int' => (int) $this->value,
            'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }

    /**
     * Read a setting, falling back to a default.
     *
     * Reads through the config file first so a fresh install behaves identically
     * to one whose settings have been edited — the database is the override, not
     * the source.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting ? $setting->typedValue() : $default;
    }
}
