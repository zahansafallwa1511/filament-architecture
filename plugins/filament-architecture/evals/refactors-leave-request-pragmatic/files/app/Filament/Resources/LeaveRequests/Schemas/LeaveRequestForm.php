<?php

namespace App\Filament\Resources\LeaveRequests\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class LeaveRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')->relationship('employee', 'name')->required(),
            DatePicker::make('starts_on')->required(),
            DatePicker::make('ends_on')->required()->afterOrEqual('starts_on'),
            Textarea::make('reason'),
            Select::make('status')
                ->options([
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ])
                ->visibleOn('edit'),
        ]);
    }
}
