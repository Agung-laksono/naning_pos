<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    protected $fillable = [
        'shift_id',
        'uuid',
        'invoice_no',
        'transaction_type',
        'cashier_name',
        'customer_id',
        'customer_name',
        'subtotal',
        'item_discount',
        'global_discount',
        'global_discount_note',
        'grand_total',
        'admin_fee',
        'total_cost',
        'payment_method',
        'paid_amount',
        'change_amount',
        'due_date',
        'status',
        'synced_at',
    ];

    protected $casts = [
        'subtotal' => 'integer',
        'item_discount' => 'integer',
        'global_discount' => 'integer',
        'grand_total' => 'integer',
        'admin_fee' => 'integer',
        'total_cost' => 'integer',
        'paid_amount' => 'integer',
        'change_amount' => 'integer',
        'due_date' => 'date',
        'synced_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'shift_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function debtPayments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }
}
