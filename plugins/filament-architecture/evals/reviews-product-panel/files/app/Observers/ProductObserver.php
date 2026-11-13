<?php

namespace App\Observers;

use App\Models\Product;
use Illuminate\Support\Facades\Http;

class ProductObserver
{
    public function updating(Product $product): void
    {
        // Price can never drop more than 30% in one change
        if ($product->isDirty('price') && $product->price < $product->getOriginal('price') * 0.7) {
            $product->price = round($product->getOriginal('price') * 0.7, 2);
        }
    }

    public function updated(Product $product): void
    {
        if ($product->wasChanged('stock')) {
            Http::post(config('services.storefront.url').'/stock-sync', [
                'sku' => $product->sku,
                'stock' => $product->stock,
            ]);
        }
    }
}
