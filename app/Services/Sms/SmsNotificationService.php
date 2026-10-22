<?php

namespace App\Services\Sms;

use App\Contracts\SettingsRepository;
use App\Jobs\SendFast2SmsMessage;
use App\Support\Data;
use Illuminate\Support\Facades\Log;

final class SmsNotificationService
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly SmsDeliveryLogger $deliveryLogger,
    ) {}

    /**
     * Queue an SMS for an enabled CRM event.
     *
     * @param  array<string, string>  $tokens
     */
    public function queue(string $event, ?string $phoneNumber, array $tokens): void
    {
        $settings = $this->settingsRepository->get();
        $smsSettings = data_get($settings, 'notifications.sms', []);
        $template = data_get($smsSettings, "templates.{$event}", []);
        $isSmsEnabled = (bool) data_get($smsSettings, 'enabled', false);
        $isEventEnabled = is_array($template) && (bool) ($template['enabled'] ?? false);
        $hasApiKey = filled(data_get($smsSettings, 'api_key'));
        $recipient = preg_replace('/\D+/', '', Data::string($phoneNumber)) ?? '';

        if (str_starts_with($recipient, '0091') && mb_strlen($recipient) === 14) {
            $recipient = substr($recipient, 4);
        } elseif (str_starts_with($recipient, '91') && mb_strlen($recipient) === 12) {
            $recipient = substr($recipient, 2);
        } elseif (str_starts_with($recipient, '0') && mb_strlen($recipient) === 11) {
            $recipient = substr($recipient, 1);
        }

        if (! $isSmsEnabled || ! $isEventEnabled || ! $hasApiKey) {
            $reason = ! $isSmsEnabled
                ? 'SMS notifications are disabled.'
                : (! $isEventEnabled
                    ? 'SMS is disabled for this CRM event.'
                    : (! $hasApiKey
                        ? 'Fast2SMS API key is missing.'
                        : 'SMS template is unavailable.'));

            $this->deliveryLogger->create($event, $recipient, 'skipped', $reason);

            return;
        }

        if (mb_strlen($recipient) !== 10) {
            $this->deliveryLogger->create($event, $recipient, 'skipped', 'Recipient phone number is missing or invalid.');

            return;
        }

        $messageTemplate = Data::string($template['message'] ?? '');
        $tokens['gym_name'] ??= Data::string(data_get($settings, 'general.gym_name', 'Gymie'));
        $message = preg_replace_callback('/\{([a-z_]+)\}/i', static function (array $matches) use ($tokens): string {
            return $tokens[strtolower($matches[1])] ?? $matches[0];
        }, $messageTemplate) ?? $messageTemplate;
        $message = trim($message);

        if ($message === '') {
            $this->deliveryLogger->create($event, $recipient, 'skipped', 'SMS template is empty.');

            return;
        }

        $logId = $this->deliveryLogger->create($event, $recipient, 'queued');

        try {
            SendFast2SmsMessage::dispatch(
                event: $event,
                recipient: $recipient,
                message: $message,
                logId: $logId,
            )->afterCommit();
        } catch (\Throwable $exception) {
            $this->deliveryLogger->update(
                logId: $logId,
                status: 'failed',
                error: 'Unable to queue SMS ('.$exception::class.').',
            );

            Log::warning('Unable to queue Fast2SMS notification.', [
                'event' => $event,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
