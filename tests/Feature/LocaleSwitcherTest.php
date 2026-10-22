<?php

use App\Contracts\SettingsRepository;
use App\Filament\Livewire\LocaleSwitcher;
use Illuminate\Support\Arr;
use Livewire\Livewire;

it('persists the selected locale in settings', function (): void {
    config()->set('app.supported_locales', ['en', 'fr', 'ar']);

    $repository = new class implements SettingsRepository
    {
        /**
         * @var array<string, mixed>
         */
        public array $lastPut = [];

        public function get(): array
        {
            return [
                'general' => [
                    'locale' => 'en',
                ],
                'invoice' => [],
                'member' => [],
                'charges' => [],
                'expenses' => [],
                'subscriptions' => [],
                'payments' => [],
                'notifications' => [
                    'email' => [],
                ],
            ];
        }

        public function put(array $settings): void
        {
            $this->lastPut = $settings;
        }
    };

    app()->instance(SettingsRepository::class, $repository);
    app()->setLocale('en');

    Livewire::test(LocaleSwitcher::class)
        ->call('setLocale', 'fr')
        ->assertRedirect()
        ->assertSet('locale', 'fr');

    expect(data_get($repository->lastPut, 'general.locale'))->toBe('fr');
    expect(session('filament.notifications.0.title'))->toBe('Langue mise à jour');
});

it('ignores unsupported locales', function (): void {
    config()->set('app.supported_locales', ['en', 'fr', 'ar']);

    $repository = new class implements SettingsRepository
    {
        public int $putCount = 0;

        public function get(): array
        {
            return [
                'general' => [
                    'locale' => 'en',
                ],
                'invoice' => [],
                'member' => [],
                'charges' => [],
                'expenses' => [],
                'subscriptions' => [],
                'payments' => [],
                'notifications' => [
                    'email' => [],
                ],
            ];
        }

        public function put(array $settings): void
        {
            $this->putCount++;
        }
    };

    app()->instance(SettingsRepository::class, $repository);
    app()->setLocale('en');

    Livewire::test(LocaleSwitcher::class)
        ->call('setLocale', 'de')
        ->assertSet('locale', 'en');

    expect($repository->putCount)->toBe(0);
});

it('renders the current locale label in the trigger', function (): void {
    config()->set('app.supported_locales', ['en', 'fr', 'ar']);

    app()->setLocale('en');

    Livewire::test(LocaleSwitcher::class)
        ->assertSeeHtml('fi-locale-switcher-label')
        ->assertSee('English');
});

it('offers English, Bangla, and Hindi in the language selector', function (): void {
    app()->setLocale('en');

    Livewire::test(LocaleSwitcher::class)
        ->assertSee('বাংলা')
        ->assertSee('हिन्दी')
        ->assertDontSee('French')
        ->assertDontSee('Arabic')
        ->assertDontSee('Farsi');
});

it('loads translated application strings and preserves dynamic placeholders', function (): void {
    $originalLocale = app()->getLocale();

    try {
        foreach (['bn' => 'ভাষা', 'hi' => 'भाषा'] as $locale => $expectedLanguageLabel) {
            app()->setLocale($locale);

            expect(__('app.settings.language'))->toBe($expectedLanguageLabel);

            $message = __('app.empty.no_records_marked_as', [
                'records' => 'Subscriptions',
                'status' => 'Ongoing',
            ]);

            expect($message)
                ->toContain('Subscriptions')
                ->toContain('Ongoing')
                ->not->toContain(':records')
                ->not->toContain(':status');
        }
    } finally {
        app()->setLocale($originalLocale);
    }
});

it('keeps Bangla and Hindi translation keys and placeholders aligned with English', function (): void {
    $englishMessages = Arr::dot(require lang_path('en/app.php'));

    foreach (['bn', 'hi'] as $locale) {
        $translatedMessages = Arr::dot(require lang_path("{$locale}/app.php"));

        expect(array_keys($translatedMessages))->toBe(array_keys($englishMessages));

        foreach ($englishMessages as $key => $englishMessage) {
            preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*|\{[^{}]+\}/', $englishMessage, $englishPlaceholders);
            preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*|\{[^{}]+\}/', $translatedMessages[$key], $translatedPlaceholders);

            sort($englishPlaceholders[0]);
            sort($translatedPlaceholders[0]);

            expect($translatedPlaceholders[0])->toBe($englishPlaceholders[0]);
        }
    }
});
