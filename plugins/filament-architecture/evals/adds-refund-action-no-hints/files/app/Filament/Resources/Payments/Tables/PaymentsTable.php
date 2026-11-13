<?php

namespace App\Filament\Resources\Payments\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')->searchable(),
                TextColumn::make('amount_pence')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => '£'.number_format($state / 100, 2)),
                TextColumn::make('status')->badge(),
                TextColumn::make('paid_at')->dateTime()->sortable(),
            ])
            ->defaultSort('paid_at', 'desc')
            ->recordActions([]);
    }
}
