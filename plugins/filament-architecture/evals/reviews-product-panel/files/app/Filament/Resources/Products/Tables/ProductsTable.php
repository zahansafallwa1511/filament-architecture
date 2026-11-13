<?php

namespace App\Filament\Resources\Products\Tables;

use App\Application\Catalog\UseCases\DiscontinueProduct;
use App\Domain\Shared\DomainException;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('price')->money('GBP'),
                TextColumn::make('stock')->sortable(),
                ToggleColumn::make('featured'),
            ])
            ->filters([
                Filter::make('low_stock')
                    ->query(fn (Builder $query) => $query->where('stock', '<', 5)),
            ])
            ->recordActions([
                Action::make('restock')
                    ->schema([TextInput::make('quantity')->numeric()->minValue(1)->required()])
                    ->action(function (Product $record, array $data) {
                        if ($record->discontinued_at !== null) {
                            Notification::make()->danger()->title('Discontinued products cannot be restocked')->send();

                            return;
                        }

                        $record->increment('stock', (int) $data['quantity']);

                        Http::post(config('services.warehouse.url').'/restock', [
                            'sku' => $record->sku,
                            'quantity' => (int) $data['quantity'],
                        ]);

                        Notification::make()->success()->title('Restocked')->send();
                    }),
                Action::make('discontinue')
                    ->requiresConfirmation()
                    ->visible(fn (Product $record) => $record->stock === 0 && $record->discontinued_at === null)
                    ->action(function (Product $record, Action $action, DiscontinueProduct $discontinue) {
                        try {
                            $discontinue->execute($record->getKey());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }

                        Notification::make()->success()->title('Product discontinued')->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('apply_discount')
                    ->schema([TextInput::make('percent')->numeric()->minValue(1)->maxValue(90)->required()])
                    ->action(function (Collection $records, array $data) {
                        foreach ($records as $product) {
                            $product->price = round($product->price * (1 - $data['percent'] / 100), 2);
                            $product->save();
                        }
                    }),
            ]);
    }
}
