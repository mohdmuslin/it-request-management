<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document metadata. The file itself lives on a private disk.
 *
 * Downloads go through an authorised action rather than a public URL. A
 * predictable path is not access control, and these documents routinely contain
 * budgets, quotations and risk assessments.
 */
class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'category',
        'original_name',
        'storage_path',
        'mime_type',
        'size',
        'checksum',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItRequest::class, 'request_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Human-readable size, for the document list. */
    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->size;
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return $i === 0
            ? "{$size} {$units[$i]}"
            : number_format($size, 0).' '.$units[$i];
    }
}
