<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A governance tier: Tier 1, Tier 2 or Tier P (Partnership).
 *
 * Reference data rather than a bare enum, so an administrator can rename one
 * without a deployment.
 */
class Tier extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function dueDayOverrides(): HasMany
    {
        return $this->hasMany(StageDueDay::class);
    }
}
