<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'barcode',
        'name',
        'icon',
        'image',
        'base_unit',
        'stock',
        'min_stock',
        'cost_price',
        'is_active',
    ];

    protected $casts = [
        'stock' => 'integer',
        'min_stock' => 'integer',
        'cost_price' => 'integer',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }
}
