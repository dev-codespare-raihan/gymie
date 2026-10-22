<?php

namespace App\Observers;

use App\Helpers\Helpers;
use App\Models\Subscription;
use App\Services\Sms\SmsNotificationService;
use App\Support\Data;

class SubscriptionObserver
{
    public function __construct(private readonly SmsNotificationService $sms) {}

    public function created(Subscription $subscription): void
    {
        $subscription->loadMissing(['member', 'plan']);
        $member = $subscription->member;
        $plan = $subscription->plan;

        if (! $member) {
            return;
        }

        $event = $subscription->renewed_from_subscription_id
            ? 'subscription_renewed'
            : 'subscription_started';

        $this->sms->queue($event, $member->contact, [
            'name' => Data::string($member->name),
            'member_code' => Data::string($member->code),
            'plan_name' => Data::string($plan?->name),
            'plan_fee' => $plan ? Helpers::formatCurrency((float) $plan->amount) : '',
            'start_date' => $subscription->start_date?->format('d/m/Y') ?? '',
            'end_date' => $subscription->end_date?->format('d/m/Y') ?? '',
        ]);
    }
}
