<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'price',
        'ratio',
        'is_base',
    ];

    protected $casts = [
        'price' => 'integer',
        'ratio' => 'integer',
        'is_base' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
