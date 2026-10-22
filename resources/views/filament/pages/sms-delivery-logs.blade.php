<x-filament::section
    :heading="__('app.settings.sections.sms_logs')"
    :description="__('app.settings.hints.sms_logs_description')"
>
    <div class="flex justify-end pb-4">
        <x-filament::button type="button" color="gray" wire:click="$refresh">
            {{ __('app.settings.actions.refresh_sms_logs') }}
        </x-filament::button>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full divide-y divide-gray-200 text-start dark:divide-white/10">
            <thead>
                <tr>
                    <th class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('app.settings.sms_log_columns.date') }}</th>
                    <th class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('app.settings.sms_log_columns.event') }}</th>
                    <th class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('app.settings.sms_log_columns.recipient') }}</th>
                    <th class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('app.settings.sms_log_columns.status') }}</th>
                    <th class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300">{{ __('app.settings.sms_log_columns.details') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($smsLogs as $smsLog)
                    @php
                        $statusColor = match ($smsLog->status) {
                            'sent' => 'success',
                            'failed' => 'danger',
                            'sending' => 'info',
                            'queued' => 'warning',
                            default => 'gray',
                        };
                        $details = $smsLog->error ?: $smsLog->provider_response;
                    @endphp
                    <tr>
                        <td class="whitespace-nowrap px-3 py-3 text-sm text-gray-600 dark:text-gray-300">
                            {{ \Illuminate\Support\Carbon::parse($smsLog->created_at)->timezone(\App\Support\AppConfig::timezone())->format('d/m/Y H:i') }}
                        </td>
                        <td class="px-3 py-3 text-sm text-gray-950 dark:text-white">
                            {{ __("app.settings.sms_events.{$smsLog->event}") }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-sm text-gray-600 dark:text-gray-300">
                            {{ $smsLog->recipient ?: __('app.placeholders.dash') }}
                        </td>
                        <td class="px-3 py-3 text-sm">
                            <x-filament::badge :color="$statusColor">
                                {{ __("app.settings.sms_status.{$smsLog->status}") }}
                            </x-filament::badge>
                        </td>
                        <td class="max-w-md whitespace-normal break-words px-3 py-3 text-sm text-gray-600 dark:text-gray-300">
                            {{ $details ?: __('app.settings.sms_status.no_details') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ __('app.settings.sms_status.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament::section>
