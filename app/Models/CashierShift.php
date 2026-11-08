<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierShift extends Model
{
    protected $fillable = [
        'user_id',
        'cashier_name',
        'start_time',
        'end_time',
        'starting_cash',
        'expected_cash',
        'actual_cash',
        'difference',
        'status',
        'notes',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'starting_cash' => 'integer',
        'expected_cash' => 'integer',
        'actual_cash' => 'integer',
        'difference' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'shift_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'shift_id');
    }
}

