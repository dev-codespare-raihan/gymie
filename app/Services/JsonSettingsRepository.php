<?php

namespace App\Services;

use App\Contracts\SettingsRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;

/**
 * JSON-backed settings repository (OSS default).
 *
 * Settings are stored under `storage/data/settingsData.json`.
 * Other installations can override this binding to store settings elsewhere.
 */
class JsonSettingsRepository implements SettingsRepository
{
    private const ENCRYPTED_SECRET_PREFIX = 'encrypted:';

    private const SETTINGS_PATH = 'data/settingsData.json';

    private const EXAMPLE_SETTINGS_PATH = 'data/settingsData.json.example';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $cachedSettings = null;

    /**
     * @var array<string, mixed>|null
     */
    protected static ?array $testOverride = null;

    /**
     * @param  array<string, mixed>|null  $override
     */
    public function setTestOverride(?array $override): void
    {
        static::$testOverride = $override;
        $this->cachedSettings = null;
    }

    public function get(): array
    {
        if ($this->cachedSettings !== null) {
            return $this->cachedSettings;
        }

        if (static::$testOverride !== null) {
            return $this->cachedSettings = $this->normalize(static::$testOverride);
        }

        if (app()->runningUnitTests()) {
            $exampleFilePath = storage_path(self::EXAMPLE_SETTINGS_PATH);

            if (file_exists($exampleFilePath)) {
                $settings = json_decode((string) file_get_contents($exampleFilePath), true) ?? [];
                $settings = is_array($settings) ? $settings : [];

                return $this->cachedSettings = $this->normalize($this->decryptSecrets($settings));
            }

            return $this->cachedSettings = $this->normalize([]);
        }

        $filePath = storage_path(self::SETTINGS_PATH);

        if (! file_exists($filePath)) {
            $this->initializeFile($filePath);
        }

        $settings = json_decode((string) file_get_contents($filePath), true) ?? [];
        $settings = is_array($settings) ? $settings : [];

        return $this->cachedSettings = $this->normalize($this->decryptSecrets($settings));
    }

    /**
     * Reload settings from their backing store, bypassing the request cache.
     *
     * @return array<string, mixed>
     */
    public function fresh(): array
    {
        $this->cachedSettings = null;

        return $this->get();
    }

    public function put(array $settings): void
    {
        $normalized = $this->normalize($settings);

        if (app()->runningUnitTests()) {
            static::$testOverride = $normalized;
            $this->cachedSettings = $normalized;

            return;
        }

        $filePath = storage_path(self::SETTINGS_PATH);

        File::ensureDirectoryExists(dirname($filePath));
        File::replace(
            $filePath,
            json_encode($this->encryptSecrets($normalized), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $this->cachedSettings = $normalized;
    }

    /**
     * Encrypt the SMS API key before persisting settings to disk.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function encryptSecrets(array $settings): array
    {
        $apiKey = data_get($settings, 'notifications.sms.api_key');

        if (is_string($apiKey) && $apiKey !== '' && ! str_starts_with($apiKey, self::ENCRYPTED_SECRET_PREFIX)) {
            data_set($settings, 'notifications.sms.api_key', self::ENCRYPTED_SECRET_PREFIX.Crypt::encryptString($apiKey));
        }

        return $settings;
    }

    /**
     * Decrypt the SMS API key after reading settings from disk.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function decryptSecrets(array $settings): array
    {
        $apiKey = data_get($settings, 'notifications.sms.api_key');

        if (! is_string($apiKey) || ! str_starts_with($apiKey, self::ENCRYPTED_SECRET_PREFIX)) {
            return $settings;
        }

        try {
            data_set(
                $settings,
                'notifications.sms.api_key',
                Crypt::decryptString(substr($apiKey, mb_strlen(self::ENCRYPTED_SECRET_PREFIX))),
            );
        } catch (DecryptException) {
            data_set($settings, 'notifications.sms.api_key', '');
        }

        return $settings;
    }

    private function initializeFile(string $filePath): void
    {
        $exampleFilePath = storage_path(self::EXAMPLE_SETTINGS_PATH);

        if (file_exists($exampleFilePath)) {
            copy($exampleFilePath, $filePath);

            return;
        }

        if (! file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }

        file_put_contents($filePath, json_encode([
            'general' => [],
            'invoice' => [],
            'member' => [],
            'charges' => [],
            'expenses' => [],
            'subscriptions' => [],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function normalize(array $settings): array
    {
        foreach ([
            'general',
            'invoice',
            'member',
            'charges',
            'expenses',
            'subscriptions',
            'payments',
            'notifications',
        ] as $key) {
            if (! array_key_exists($key, $settings) || ! is_array($settings[$key])) {
                $settings[$key] = [];
            }
        }

        /** @var array<string, mixed> $general */
        $general = $settings['general'];
        if (
            ! array_key_exists('locale', $general) ||
            (! is_string($general['locale']) && $general['locale'] !== null)
        ) {
            $general['locale'] = null;
        }
        $settings['general'] = $general;

        /** @var array<string, mixed> $charges */
        $charges = $settings['charges'];
        if (! isset($charges['discounts']) || ! is_array($charges['discounts'])) {
            $charges['discounts'] = [];
        }
        $settings['charges'] = $charges;

        /** @var array<string, mixed> $expenses */
        $expenses = $settings['expenses'];
        if (! isset($expenses['categories']) || ! is_array($expenses['categories'])) {
            $expenses['categories'] = [];
        }
        $settings['expenses'] = $expenses;

        /** @var array<string, mixed> $subscriptions */
        $subscriptions = $settings['subscriptions'];
        $expiringDays = $subscriptions['expiring_days'] ?? null;
        $subscriptions['expiring_days'] = is_numeric($expiringDays) && (int) $expiringDays >= 1
            ? (int) $expiringDays
            : 7;
        $settings['subscriptions'] = $subscriptions;

        /** @var array<string, mixed> $notifications */
        $notifications = $settings['notifications'];
        if (
            ! array_key_exists('email', $notifications) ||
            ! is_array($notifications['email'])
        ) {
            $notifications['email'] = [];
        }
        if (
            ! array_key_exists('sms', $notifications) ||
            ! is_array($notifications['sms'])
        ) {
            $notifications['sms'] = [];
        }
        $settings['notifications'] = $notifications;

        /** @var array<string, mixed> $emailSettings */
        $emailSettings = $settings['notifications']['email'];

        foreach ([
            'enabled' => false,
            'auto_send_invoice_issued' => false,
            'auto_send_payment_receipt' => false,
            'invoice_subject_template' => 'Invoice {invoice_number} - {status}',
            'receipt_subject_template' => 'Payment received - {invoice_number}',
        ] as $key => $default) {
            if (! array_key_exists($key, $emailSettings)) {
                $emailSettings[$key] = $default;
            }
        }
        $settings['notifications']['email'] = $emailSettings;

        /** @var array<string, mixed> $smsSettings */
        $smsSettings = $settings['notifications']['sms'];
        $smsSettings['enabled'] ??= false;
        $smsSettings['api_key'] ??= '';
        $smsSettings['route'] = 'q';
        $smsSettings['templates'] = array_replace_recursive([
            'enquiry_created' => [
                'enabled' => false,
                'message' => 'Hi {name}, thanks for your interest in {gym_name}. We will be in touch soon.',
            ],
            'follow_up_scheduled' => [
                'enabled' => false,
                'message' => 'Hi {name}, your follow-up with {gym_name} is scheduled for {schedule_date}.',
            ],
            'member_created' => [
                'enabled' => false,
                'message' => 'Welcome to {gym_name}, {name}! Your member code is {member_code}.',
            ],
            'subscription_started' => [
                'enabled' => false,
                'message' => 'Hi {name}, your {plan_name} membership at {gym_name} starts on {start_date} and ends on {end_date}.',
            ],
            'subscription_renewed' => [
                'enabled' => false,
                'message' => 'Hi {name}, your {plan_name} membership at {gym_name} has been renewed through {end_date}.',
            ],
            'invoice_issued' => [
                'enabled' => false,
                'message' => 'Hi {name}, invoice {invoice_number} from {gym_name}: total {total}, due {due}.',
            ],
            'payment_received' => [
                'enabled' => false,
                'message' => 'Hi {name}, we received {payment_amount} for invoice {invoice_number}. Thank you, {gym_name}.',
            ],
        ], is_array($smsSettings['templates'] ?? null) ? $smsSettings['templates'] : []);
        $settings['notifications']['sms'] = $smsSettings;

        /** @var array<string, mixed> $payments */
        $payments = $settings['payments'];
        if (
            ! array_key_exists('provider', $payments) ||
            ! is_string($payments['provider']) ||
            trim($payments['provider']) === ''
        ) {
            $payments['provider'] = 'stripe';
        }
        $settings['payments'] = $payments;

        return $settings;
    }
}
