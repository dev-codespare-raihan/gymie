<?php

namespace App\Observers;

use App\Contracts\SettingsRepository;
use App\Helpers\Helpers;
use App\Jobs\SendInvoiceIssuedEmail;
use App\Models\Invoice;
use App\Services\Sms\SmsNotificationService;
use App\Support\Data;
use Illuminate\Support\Facades\Log;

/**
 * Invoice observer.
 *
 * Responsible for triggering invoice-related side effects (emails).
 */
class InvoiceObserver
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly SmsNotificationService $sms,
    ) {}

    /**
     * Handle the Invoice "created" event.
     */
    public function created(Invoice $invoice): void
    {
        $invoice->loadMissing('subscription.member', 'subscription.plan');
        $member = $invoice->subscription?->member;
        $plan = $invoice->subscription?->plan;

        if ($member) {
            $this->sms->queue('invoice_issued', $member->contact, [
                'name' => Data::string($member->name),
                'member_code' => Data::string($member->code),
                'invoice_number' => Data::string($invoice->number),
                'status' => $invoice->getDisplayStatusLabel(),
                'total' => Helpers::formatCurrency((float) $invoice->total_amount),
                'paid' => Helpers::formatCurrency((float) $invoice->paid_amount),
                'due' => Helpers::formatCurrency((float) $invoice->due_amount),
                'plan_name' => Data::string($plan?->name),
            ]);
        }

        $settings = $this->settingsRepository->get();

        if (
            ! (bool) data_get($settings, 'notifications.email.enabled', false) ||
            ! (bool) data_get($settings, 'notifications.email.auto_send_invoice_issued', false)
        ) {
            return;
        }

        $email = (string) ($member?->email ?? '');

        if (! filled($email)) {
            Log::info('Skipping invoice issued email: member email missing.', [
                'invoice_id' => $invoice->getKey(),
                'subscription_id' => $invoice->subscription_id,
            ]);

            return;
        }

        SendInvoiceIssuedEmail::dispatch(
            invoiceId: Data::int($invoice->getKey()),
            toEmail: $email,
        )->afterCommit();
    }

    // Other lifecycle events intentionally left unhandled for v1.
}
