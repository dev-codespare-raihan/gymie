<div class="flex flex-wrap items-center gap-3">
    <x-filament::button type="button" color="gray" wire:click="checkFast2SmsConnection" wire:loading.attr="disabled" wire:target="checkFast2SmsConnection">
        <span wire:loading.remove wire:target="checkFast2SmsConnection">{{ __('app.settings.actions.check_sms_connection') }}</span>
        <span wire:loading wire:target="checkFast2SmsConnection">{{ __('app.settings.sms_connection.checking') }}</span>
    </x-filament::button>

    @if ($status === 'connected')
        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-success-600 dark:text-success-400">
            <x-filament::icon icon="heroicon-o-check-circle" class="size-5" />
            {{ $message }}
        </span>
    @elseif ($status === 'failed')
        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-danger-600 dark:text-danger-400">
            <x-filament::icon icon="heroicon-o-x-circle" class="size-5" />
            {{ $message }}
        </span>
    @else
        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('app.settings.sms_connection.not_checked') }}</span>
    @endif
</div>
