<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('reference')->disabled(),
            TextInput::make('customer_name')->required(),
            TextInput::make('customer_email')->email()->required(),
        ]);
    }
}
