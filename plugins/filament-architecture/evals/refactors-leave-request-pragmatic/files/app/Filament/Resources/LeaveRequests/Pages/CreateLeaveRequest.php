<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\Employee;
use Carbon\CarbonPeriod;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLeaveRequest extends CreateRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // count working days only
        $days = 0;
        foreach (CarbonPeriod::create($data['starts_on'], $data['ends_on']) as $date) {
            if (! $date->isWeekend()) {
                $days++;
            }
        }

        $employee = Employee::findOrFail($data['employee_id']);
        if ($days > $employee->annual_leave_balance) {
            Notification::make()->danger()->title("Only {$employee->annual_leave_balance} days left")->send();
            $this->halt();
        }

        $data['days'] = $days;
        $data['status'] = 'pending';

        return $data;
    }
}
