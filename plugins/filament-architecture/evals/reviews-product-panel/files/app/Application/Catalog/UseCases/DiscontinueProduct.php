<?php

namespace App\Application\Catalog\UseCases;

use App\Domain\Catalog\ProductCannotBeDiscontinued;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DiscontinueProduct
{
    public function execute(int $productId): void
    {
        DB::transaction(function () use ($productId) {
            $product = Product::lockForUpdate()->findOrFail($productId);

            if ($product->stock > 0) {
                throw ProductCannotBeDiscontinued::stillInStock($product->sku);
            }

            $product->update([
                'discontinued_at' => now(),
                'discontinued_by' => auth()->id(),
                'featured' => false,
            ]);
        });

        Log::info("Product {$productId} discontinued"); // "now (request) is logged"
    }
}
