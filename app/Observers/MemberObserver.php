<?php

namespace App\Observers;

use App\Models\Member;
use App\Services\Sms\SmsNotificationService;
use App\Support\Data;

class MemberObserver
{
    public function __construct(private readonly SmsNotificationService $sms) {}

    public function created(Member $member): void
    {
        $this->sms->queue('member_created', $member->contact, [
            'name' => Data::string($member->name),
            'member_code' => Data::string($member->code),
            'email' => Data::string($member->email),
        ]);
    }
}
