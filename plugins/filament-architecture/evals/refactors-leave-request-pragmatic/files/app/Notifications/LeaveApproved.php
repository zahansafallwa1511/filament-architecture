<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveApproved extends Notification
{
    public function __construct(public LeaveRequest $leaveRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your leave has been approved')
            ->line("Your leave from {$this->leaveRequest->starts_on->toDateString()} to {$this->leaveRequest->ends_on->toDateString()} was approved.");
    }
}
