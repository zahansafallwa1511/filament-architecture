<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sku')->required()->maxLength(32),
            TextInput::make('name')->required(),
            TextInput::make('price')->numeric()->prefix('£')->required(),
        ]);
    }
}
