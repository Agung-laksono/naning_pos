<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    protected $fillable = [
        'transaction_id',
        'product_id',
        'product_name',
        'unit_name',
        'unit_price',
        'cost_price',
        'ratio',
        'qty',
        'discount',
        'total',
    ];

    protected $casts = [
        'unit_price' => 'integer',
        'cost_price' => 'integer',
        'ratio' => 'integer',
        'qty' => 'integer',
        'discount' => 'integer',
        'total' => 'integer',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
