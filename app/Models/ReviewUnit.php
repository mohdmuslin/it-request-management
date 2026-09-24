<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A reviewing unit: IT Operations, IT Platforms, IT Delivery & Governance.
 *
 * WHY THIS IS NOT A ROLE
 *
 * A role is what a person may do. A unit is where they sit. A Technical Reviewer
 * holds the role AND belongs to a unit, because the same role behaves differently
 * depending on the unit — and because "which units must review this?" is a
 * question about units, not about permissions.
 */
class ReviewUnit extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'review_unit_user')->withPivot('is_lead');
    }

    public function classifications(): BelongsToMany
    {
        return $this->belongsToMany(Classification::class, 'classification_review_units')
            ->withPivot('sort_order');
    }

    public function recommendations()
    {
        return $this->hasMany(Recommendation::class);
    }
}
