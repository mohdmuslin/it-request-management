<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A governance route: Light, Moderate or Full.
 *
 * `requires_committee` is the routing rule held as data. Only Full sets it, which
 * is why only Full reaches the IT Investment Committee.
 */
class GovernanceRoute extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'description', 'requires_committee', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'requires_committee' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
