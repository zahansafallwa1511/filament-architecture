<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Notifications\LeaveApproved;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditLeaveRequest extends EditRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected function afterSave(): void
    {
        $leave = $this->record;

        if ($leave->wasChanged('status') && $leave->status === 'approved') {
            DB::transaction(function () use ($leave) {
                $leave->employee->decrement('annual_leave_balance', $leave->days);
                $leave->update([
                    'decided_by' => auth()->id(),
                    'decided_at' => now(),
                ]);
            });

            $leave->employee->notify(new LeaveApproved($leave));
        }
    }
}
