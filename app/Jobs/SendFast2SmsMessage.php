<?php

namespace App\Jobs;

use App\Contracts\SettingsRepository;
use App\Services\Sms\SmsDeliveryLogger;
use Composer\CaBundle\CaBundle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendFast2SmsMessage implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $event,
        public readonly string $recipient,
        public readonly string $message,
        public readonly int $logId,
    ) {}

    public function handle(SettingsRepository $settingsRepository, SmsDeliveryLogger $deliveryLogger): void
    {
        $smsSettings = data_get($settingsRepository->get(), 'notifications.sms', []);

        if (
            ! is_array($smsSettings)
            || ! (bool) ($smsSettings['enabled'] ?? false)
            || ! (bool) data_get($smsSettings, "templates.{$this->event}.enabled", false)
        ) {
            $deliveryLogger->update(
                logId: $this->logId,
                status: 'skipped',
                error: 'SMS was disabled in settings before the queued message ran.',
            );

            return;
        }

        $apiKey = trim((string) ($smsSettings['api_key'] ?? ''));

        if ($apiKey === '') {
            $deliveryLogger->update(
                logId: $this->logId,
                status: 'skipped',
                error: 'Fast2SMS API key was removed before the queued message ran.',
            );
            Log::warning('Skipping Fast2SMS notification: API key is missing.', [
                'event' => $this->event,
            ]);

            return;
        }

        $deliveryLogger->update($this->logId, 'sending', attempted: true);

        try {
            $response = Http::connectTimeout(5)
                ->timeout($this->timeout)
                ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                ->withHeaders([
                    'Authorization' => $apiKey,
                    'accept' => 'application/json',
                ])
                ->post((string) config('services.fast2sms.url'), [
                    'route' => 'q',
                    'message' => $this->message,
                    'numbers' => $this->recipient,
                ]);

            $response->throw();

            $deliveryLogger->update(
                logId: $this->logId,
                status: 'sent',
                providerResponse: $this->providerResponseSummary($response->json(), $response->status()),
                attempted: true,
            );
        } catch (RequestException $exception) {
            $deliveryLogger->update(
                logId: $this->logId,
                status: 'failed',
                error: $this->providerErrorSummary($exception->response->json(), $exception->response->status()),
                providerResponse: $this->providerResponseSummary($exception->response->json(), $exception->response->status()),
                attempted: true,
            );

            throw $exception;
        } catch (Throwable $exception) {
            $deliveryLogger->update(
                logId: $this->logId,
                status: 'failed',
                error: 'Fast2SMS request failed ('.$exception::class.').',
                attempted: true,
            );

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Fast2SMS notification job failed permanently.', [
            'event' => $this->event,
            'exception_class' => $exception ? $exception::class : null,
        ]);

        DB::table('sms_delivery_logs')
            ->where('id', $this->logId)
            ->whereIn('status', ['queued', 'sending'])
            ->update([
                'status' => 'failed',
                'error' => 'Fast2SMS job failed before completing the request.',
                'updated_at' => now(),
            ]);
    }

    private function providerResponseSummary(mixed $response, int $httpStatus): string
    {
        $summary = ['http_status' => $httpStatus];

        if (is_array($response)) {
            foreach (['status', 'return', 'message', 'request_id', 'message_id'] as $key) {
                if (isset($response[$key]) && is_scalar($response[$key])) {
                    $summary[$key] = $this->sanitizeProviderText((string) $response[$key]);
                }
            }
        }

        return json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'HTTP '.$httpStatus;
    }

    private function providerErrorSummary(mixed $response, int $httpStatus): string
    {
        if (is_array($response)) {
            foreach (['message', 'error', 'description'] as $key) {
                if (isset($response[$key]) && is_scalar($response[$key])) {
                    return $this->sanitizeProviderText((string) $response[$key], 1000);
                }
            }
        }

        return 'Fast2SMS returned HTTP '.$httpStatus.'.';
    }

    private function sanitizeProviderText(string $value, int $limit = 500): string
    {
        $maskedRecipient = '******'.substr($this->recipient, -4);
        $value = str_replace([$this->recipient, $this->message], [$maskedRecipient, '[message]'], $value);

        return mb_substr($value, 0, $limit);
    }
}
