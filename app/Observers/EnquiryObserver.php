<?php

namespace App\Observers;

use App\Models\Enquiry;
use App\Services\Sms\SmsNotificationService;
use App\Support\Data;

class EnquiryObserver
{
    public function __construct(private readonly SmsNotificationService $sms) {}

    public function created(Enquiry $enquiry): void
    {
        $this->sms->queue('enquiry_created', $enquiry->contact, [
            'name' => Data::string($enquiry->name),
            'enquiry_date' => $enquiry->date?->format('d/m/Y') ?? '',
            'start_by' => $enquiry->start_by?->format('d/m/Y') ?? '',
            'source' => Data::string($enquiry->source),
        ]);
    }
}
