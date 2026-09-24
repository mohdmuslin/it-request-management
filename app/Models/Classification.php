<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A request classification: New System, Enhancement, Subscription/License, Others.
 *
 * `reviewUnits` is the mapping that decides who must review a request of this
 * type. It is a pivot rather than a match expression so the real rule — which is
 * not yet confirmed by the business — can be corrected without a code change.
 */
class Classification extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function reviewUnits(): BelongsToMany
    {
        return $this->belongsToMany(ReviewUnit::class, 'classification_review_units')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
