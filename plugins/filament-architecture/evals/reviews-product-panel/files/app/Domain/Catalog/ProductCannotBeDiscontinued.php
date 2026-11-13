<?php

namespace App\Domain\Catalog;

use App\Domain\Shared\DomainException;

final class ProductCannotBeDiscontinued extends DomainException
{
    public static function stillInStock(string $sku): self
    {
        return new self("Product {$sku} still has stock and cannot be discontinued.");
    }
}
