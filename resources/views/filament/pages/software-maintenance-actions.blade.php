<div class="flex flex-wrap gap-3">
    <x-filament::button
        color="warning"
        icon="heroicon-m-trash"
        wire:click="clearApplicationCaches"
        wire:loading.attr="disabled"
        wire:target="clearApplicationCaches"
    >
        {{ __('app.settings.actions.clear_application_caches') }}
    </x-filament::button>

    <x-filament::button
        color="primary"
        icon="heroicon-m-rocket-launch"
        wire:click="optimizeApplication"
        wire:loading.attr="disabled"
        wire:target="optimizeApplication"
    >
        {{ __('app.settings.actions.optimize_application') }}
    </x-filament::button>
</div>
