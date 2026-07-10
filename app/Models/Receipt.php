<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Receipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'store_name',
        'date',
        'total',
        'currency',
        'category',
        'image_path',
        'raw_extraction',
        'confidence',
    ];

    protected $casts = [
        'date' => 'date',
        'total' => 'decimal:2',
        'raw_extraction' => 'array',
        'confidence' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptItem::class);
    }

    /** Scope: receipts within a given 'YYYY-MM' month. Driver-agnostic (works on SQLite and MySQL/RDS). */
    public function scopeInMonth(Builder $query, string $month): Builder
    {
        [$year, $mon] = explode('-', $month);

        return $query->whereYear('date', (int) $year)->whereMonth('date', (int) $mon);
    }

    /** Scope: receipts in a given category (case-insensitive exact match). */
    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->whereRaw('LOWER(category) = ?', [strtolower($category)]);
    }
}
