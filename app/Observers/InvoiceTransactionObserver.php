<?php

namespace App\Observers;

use App\Contracts\SettingsRepository;
use App\Helpers\Helpers;
use App\Jobs\SendInvoicePaymentReceiptEmail;
use App\Models\InvoiceTransaction;
use App\Services\Sms\SmsNotificationService;
use App\Support\Data;
use Illuminate\Support\Facades\Log;

/**
 * Invoice transaction observer.
 *
 * Responsible for triggering receipt emails on payment transactions.
 */
class InvoiceTransactionObserver
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly SmsNotificationService $sms,
    ) {}

    /**
     * Handle the InvoiceTransaction "created" event.
     */
    public function created(InvoiceTransaction $invoiceTransaction): void
    {
        if ($invoiceTransaction->type !== 'payment') {
            return;
        }

        if (($invoiceTransaction->note ?? null) === 'Initial payment') {
            return;
        }

        $invoiceTransaction->loadMissing('invoice.subscription.member');

        $invoiceId = $invoiceTransaction->invoice?->getKey();
        if (! $invoiceId) {
            return;
        }

        $invoice = $invoiceTransaction->invoice;
        $member = $invoice->subscription?->member;

        if ($member) {
            $this->sms->queue('payment_received', $member->contact, [
                'name' => (string) $member->name,
                'member_code' => (string) $member->code,
                'invoice_number' => (string) $invoice->number,
                'status' => $invoice->getDisplayStatusLabel(),
                'total' => Helpers::formatCurrency((float) $invoice->total_amount),
                'paid' => Helpers::formatCurrency((float) $invoice->paid_amount),
                'due' => Helpers::formatCurrency((float) $invoice->due_amount),
                'payment_amount' => Helpers::formatCurrency((float) $invoiceTransaction->amount),
            ]);
        }

        $settings = $this->settingsRepository->get();

        if (
            ! (bool) data_get($settings, 'notifications.email.enabled', false) ||
            ! (bool) data_get($settings, 'notifications.email.auto_send_payment_receipt', false)
        ) {
            return;
        }

        $email = (string) ($member?->email ?? '');

        if (! filled($email)) {
            Log::info('Skipping payment receipt email: member email missing.', [
                'invoice_id' => $invoiceId,
                'invoice_transaction_id' => $invoiceTransaction->getKey(),
            ]);

            return;
        }

        SendInvoicePaymentReceiptEmail::dispatch(
            invoiceId: Data::int($invoiceId),
            invoiceTransactionId: Data::int($invoiceTransaction->getKey()),
            toEmail: $email,
        )->afterCommit();
    }

    // Other lifecycle events intentionally left unhandled for v1.
}
