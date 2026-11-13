<?php

namespace App\Filament\Resources\LeaveRequests\Tables;

use App\Mail\LeaveRejectedMail;
use App\Models\LeaveRequest;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;

class LeaveRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.name')->searchable(),
                TextColumn::make('starts_on')->date(),
                TextColumn::make('ends_on')->date(),
                TextColumn::make('days'),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('reject')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $record) => $record->status === 'pending')
                    ->schema([
                        Textarea::make('rejection_reason')->required(),
                    ])
                    ->action(function (LeaveRequest $record, array $data) {
                        $record->update([
                            'status' => 'rejected',
                            'rejection_reason' => $data['rejection_reason'],
                            'decided_by' => auth()->id(),
                            'decided_at' => now(),
                        ]);

                        Mail::to($record->employee->email)
                            ->cc($record->employee->manager_email)
                            ->send(new LeaveRejectedMail($record));

                        Notification::make()->success()->title('Leave request rejected')->send();
                    }),
            ]);
    }
}
