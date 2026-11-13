<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Mail\Mailable;

class LeaveRejectedMail extends Mailable
{
    public function __construct(public LeaveRequest $leaveRequest) {}

    public function build(): self
    {
        return $this->subject('Your leave request was rejected')
            ->text('mail.leave-rejected');
    }
}
