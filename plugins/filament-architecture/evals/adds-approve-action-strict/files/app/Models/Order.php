<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = ['reference', 'customer_name', 'customer_email', 'total', 'status'];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }
}
