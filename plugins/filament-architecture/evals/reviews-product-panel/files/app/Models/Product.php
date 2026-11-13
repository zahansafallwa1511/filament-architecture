<?php

namespace App\Models;

use App\Observers\ProductObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(ProductObserver::class)]
class Product extends Model
{
    protected $fillable = ['sku', 'name', 'price', 'stock', 'discontinued_at', 'discontinued_by', 'featured'];

    protected function casts(): array
    {
        return ['discontinued_at' => 'datetime', 'featured' => 'boolean', 'price' => 'decimal:2'];
    }
}
