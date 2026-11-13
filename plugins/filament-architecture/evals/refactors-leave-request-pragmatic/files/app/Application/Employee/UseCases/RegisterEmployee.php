<?php

namespace App\Application\Employee\UseCases;

use App\Models\Employee;

final class RegisterEmployee
{
    public function execute(string $name, string $email, string $managerEmail, int $annualLeaveBalance = 25): Employee
    {
        return Employee::create([
            'name' => $name,
            'email' => $email,
            'manager_email' => $managerEmail,
            'annual_leave_balance' => $annualLeaveBalance,
        ]);
    }
}
