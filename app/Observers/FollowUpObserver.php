<?php

namespace App\Observers;

use App\Models\FollowUp;
use App\Services\Sms\SmsNotificationService;
use App\Support\Data;

class FollowUpObserver
{
    public function __construct(private readonly SmsNotificationService $sms) {}

    public function created(FollowUp $followUp): void
    {
        $followUp->loadMissing('enquiry');
        $enquiry = $followUp->enquiry;

        if (! $enquiry) {
            return;
        }

        $this->sms->queue('follow_up_scheduled', $enquiry->contact, [
            'name' => Data::string($enquiry->name),
            'schedule_date' => $followUp->schedule_date?->format('d/m/Y') ?? '',
            'follow_up_method' => Data::string($followUp->method),
            'outcome' => Data::string($followUp->outcome),
        ]);
    }
}
