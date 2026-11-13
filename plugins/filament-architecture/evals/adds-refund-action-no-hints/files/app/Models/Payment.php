<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['customer_id', 'stripe_charge_id', 'amount_pence', 'currency', 'status', 'paid_at'];

    protected function casts(): array
    {
        return [
            'amount_pence' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
