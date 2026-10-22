<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\DB;

final class SmsDeliveryLogger
{
    public function create(string $event, ?string $recipient, string $status, ?string $error = null): int
    {
        return (int) DB::table('sms_delivery_logs')->insertGetId([
            'event' => $event,
            'recipient' => $this->maskedRecipient($recipient),
            'status' => $status,
            'error' => $error,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function update(
        int $logId,
        string $status,
        ?string $error = null,
        ?string $providerResponse = null,
        bool $attempted = false,
    ): void {
        DB::table('sms_delivery_logs')
            ->where('id', $logId)
            ->update([
                'status' => $status,
                'error' => $error,
                'provider_response' => $providerResponse,
                'attempted_at' => $attempted ? now() : null,
                'updated_at' => now(),
            ]);
    }

    private function maskedRecipient(?string $recipient): ?string
    {
        if (! filled($recipient)) {
            return null;
        }

        return '******'.substr((string) $recipient, -4);
    }
}
