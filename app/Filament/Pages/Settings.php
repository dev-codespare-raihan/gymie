<?php

namespace App\Filament\Pages;

use App\Contracts\SettingsRepository;
use App\Helpers\Helpers;
use Carbon\Carbon;
use Composer\CaBundle\CaBundle;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema as DatabaseSchema;

/**
 * @property-read Schema $form
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    /** @var string|null Page title */
    protected static ?string $title = null;

    /** @var string View file for the settings page */
    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed>|null Stores the settings data */
    public ?array $data = [];

    /** @var string|null Stores the uploaded settings file */
    public ?string $settings_file = null;

    public ?string $fast2SmsConnectionStatus = null;

    public ?string $fast2SmsConnectionMessage = null;

    /**
     * Mount the page and load settings from the storage.
     */
    public function mount(): void
    {
        $settings = Helpers::getSettings();
        $this->data = $settings;
        $general = is_array($this->data['general'] ?? null) ? $this->data['general'] : [];

        // Ensure gym_logo is always set correctly
        foreach (['gym_logo'] as $logoType) {
            if (! empty($general[$logoType]) && is_array($general[$logoType])) {
                $general[$logoType] = $general[$logoType];
            }
        }

        $this->data['general'] = $general;

        $this->form->fill($settings);
    }

    public function getTitle(): string
    {
        return __('app.settings.title');
    }

    /**
     * Get the most recent Fast2SMS delivery attempts.
     *
     * @return Collection<int, object>
     */
    public function getSmsDeliveryLogsProperty(): Collection
    {
        $connection = (string) config('database.default');
        if (! DatabaseSchema::connection($connection)->hasTable('sms_delivery_logs')) {
            return collect();
        }

        return DB::connection($connection)->table('sms_delivery_logs')
            ->latest()
            ->limit(50)
            ->get();
    }

    public function checkFast2SmsConnection(): void
    {
        $apiKey = data_get($this->form->getState(), 'notifications.sms.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            $this->fast2SmsConnectionStatus = 'failed';
            $this->fast2SmsConnectionMessage = __('app.settings.sms_connection.missing_key');

            return;
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                ->acceptJson()
                ->withHeaders(['Authorization' => trim($apiKey)])
                ->post('https://www.fast2sms.com/dev/wallet');

            $responseData = $response->json();
            $providerMessage = is_array($responseData)
                ? ($responseData['message'] ?? null)
                : null;

            if ($response->successful()) {
                $this->fast2SmsConnectionStatus = 'connected';
                $this->fast2SmsConnectionMessage = __('app.settings.sms_connection.connected');

                return;
            }

            $this->fast2SmsConnectionStatus = 'failed';
            $this->fast2SmsConnectionMessage = is_string($providerMessage) && $providerMessage !== ''
                ? $providerMessage
                : __('app.settings.sms_connection.failed');
        } catch (\Throwable $exception) {
            report($exception);
            $this->fast2SmsConnectionStatus = 'failed';
            $this->fast2SmsConnectionMessage = __('app.settings.sms_connection.unreachable');
        }
    }

    public static function getNavigationLabel(): string
    {
        return __('app.settings.title');
    }

    /**
     * Defines the form schema with multiple tabs.
     *
     * @return array<int, Component>
     */
    protected function getFormSchema(): array
    {
        return [
            Tabs::make(__('app.settings.title'))
                ->tabs([
                    $this->generalTab(),
                    $this->invoiceTab(),
                    $this->memberTab(),
                    $this->chargesTab(),
                    $this->expensesTab(),
                    $this->subscriptionsTab(),
                    $this->smsTab(),
                    $this->softwareConfigurationTab(),
                ]),
        ];
    }

    public function canManageSoftwareConfiguration(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function clearApplicationCaches(): void
    {
        $this->authorizeSoftwareConfiguration();

        try {
            Artisan::call('optimize:clear');
            Notification::make()
                ->title(__('app.settings.notifications.cache_cleared'))
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()
                ->title(__('app.settings.notifications.maintenance_failed'))
                ->danger()
                ->send();
        }
    }

    public function optimizeApplication(): void
    {
        $this->authorizeSoftwareConfiguration();

        try {
            Artisan::call('optimize');
            Notification::make()
                ->title(__('app.settings.notifications.application_optimized'))
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()
                ->title(__('app.settings.notifications.maintenance_failed'))
                ->danger()
                ->send();
        }
    }

    private function authorizeSoftwareConfiguration(): void
    {
        abort_unless($this->canManageSoftwareConfiguration(), 403);
    }

    /**
     * Build upload previews from the current request host so local host aliases work.
     *
     * @return array{name: string, size: int, type: ?string, url: ?string}|null
     */
    private function getSoftwareUploadedFile(
        BaseFileUpload $component,
        string $file,
        string|array|null $storedFileNames,
    ): ?array {
        $uploadedFile = $component->getUploadedFile($file, $storedFileNames);

        if ($uploadedFile !== null) {
            $uploadedFile['url'] = asset('storage/'.$file);
        }

        return $uploadedFile;
    }

    private function softwareConfigurationTab(): Tab
    {
        return Tab::make(__('app.settings.tabs.software_configuration'))
            ->icon('heroicon-m-cog-6-tooth')
            ->visible(fn (): bool => $this->canManageSoftwareConfiguration())
            ->schema([
                Section::make(__('app.settings.sections.software_branding'))
                    ->description(__('app.settings.hints.software_branding_refresh'))
                    ->schema([
                        TextInput::make('software.brand_name')
                            ->label(__('app.settings.fields.software_brand_name'))
                            ->default('Codespare Technologies')
                            ->maxLength(100)
                            ->required(),
                        ColorPicker::make('software.primary_color')
                            ->label(__('app.settings.fields.software_primary_color'))
                            ->hex()
                            ->default('#00499d')
                            ->required()
                            ->rules(['regex:/^#[0-9A-Fa-f]{6}$/']),
                        FileUpload::make('software.logo')
                            ->label(__('app.settings.fields.software_logo'))
                            ->disk('public')
                            ->directory('software-branding')
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->getUploadedFileUsing(fn (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array => $this->getSoftwareUploadedFile($component, $file, $storedFileNames))
                            ->maxSize(2048)
                            ->deletable(),
                        FileUpload::make('software.dark_mode_logo')
                            ->label(__('app.settings.fields.software_dark_mode_logo'))
                            ->disk('public')
                            ->directory('software-branding')
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->getUploadedFileUsing(fn (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array => $this->getSoftwareUploadedFile($component, $file, $storedFileNames))
                            ->maxSize(2048)
                            ->deletable(),
                        FileUpload::make('software.favicon')
                            ->label(__('app.settings.fields.software_favicon'))
                            ->disk('public')
                            ->directory('software-branding')
                            ->visibility('public')
                            ->acceptedFileTypes([
                                'image/png',
                                'image/jpeg',
                                'image/webp',
                                'image/x-icon',
                                'image/vnd.microsoft.icon',
                            ])
                            ->getUploadedFileUsing(fn (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array => $this->getSoftwareUploadedFile($component, $file, $storedFileNames))
                            ->maxSize(512)
                            ->deletable(),
                    ])
                    ->columns(2),
                Section::make(__('app.settings.sections.software_maintenance'))
                    ->description(__('app.settings.hints.software_maintenance'))
                    ->schema([
                        View::make('filament.pages.software-maintenance-actions'),
                    ]),
            ]);
    }

    /**
     * General Tab Schema.
     */
    private function generalTab(): Tab
    {
        return Tab::make(__('app.settings.tabs.gym_info'))
            ->icon('heroicon-m-briefcase')
            ->schema([
                Section::make(__('app.settings.sections.general_information'))
                    ->aside()
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('general.gym_name')
                                    ->label(__('app.settings.fields.gym_name')),
                                Select::make('general.currency')
                                    ->label(__('app.settings.fields.currency'))
                                    ->options(Helpers::getCurrencies())
                                    ->searchable(),
                                FileUpload::make('general.gym_logo')
                                    ->label(__('app.settings.fields.gym_logo'))
                                    ->disk('public')
                                    ->directory('images')
                                    ->imageEditor()
                                    ->deletable()
                                    ->visibility('public')
                                    ->image()
                                    ->acceptedFileTypes([
                                        'image/jpeg',
                                        'image/png',
                                        'image/webp',
                                    ])
                                    ->maxSize(2048)
                                    ->columnSpanFull(),
                                DatePicker::make('general.financial_year_start')
                                    ->native(false)
                                    ->label(__('app.settings.fields.financial_year_start'))
                                    ->helperText('Rounded to the first day of that month.')
                                    ->reactive()
                                    ->afterStateUpdated(function (?string $state, callable $set): void {
                                        if (filled($state)) {
                                            $start = Carbon::parse($state)
                                                ->startOfMonth();

                                            $set('general.financial_year_start', $start->toDateString());
                                            $set('general.financial_year_end', $start->copy()->addYear()->subDay()->toDateString());

                                            return;
                                        }

                                        $set('general.financial_year_end', null);
                                    }),
                                DatePicker::make('general.financial_year_end')
                                    ->native(false)
                                    ->label(__('app.settings.fields.financial_year_end'))
                                    ->helperText('Auto calculated based on the start month.')
                                    ->readOnly(),
                            ]),
                    ])
                    ->columnSpan(3),

                Section::make(__('app.settings.sections.address'))
                    ->aside()
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                Textarea::make('general.address')
                                    ->label(__('app.settings.fields.address')),
                            ]),
                        Grid::make(4)
                            ->schema([
                                Select::make('general.country')
                                    ->label(__('app.settings.fields.country'))
                                    ->options(Helpers::getCountries())
                                    ->searchable()
                                    ->reactive()
                                    ->afterStateUpdated(fn ($state, callable $set) => [
                                        $set('general.state', null),
                                        $set('general.city', null),
                                    ]),
                                Select::make('general.state')
                                    ->label(__('app.settings.fields.state'))
                                    ->options(fn ($get) => Helpers::getStates($get('general.country')))
                                    ->searchable()
                                    ->reactive(),
                                Select::make('general.city')
                                    ->label(__('app.settings.fields.city'))
                                    ->options(fn ($get) => Helpers::getCities($get('general.state')))
                                    ->searchable()
                                    ->reactive(),
                                TextInput::make('general.zip')
                                    ->label(__('app.settings.fields.zip'))
                                    ->maxLength(10),
                            ]),
                    ])
                    ->columnSpan(3),
                Section::make(__('app.settings.sections.contact_information'))
                    ->aside()
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('general.gym_email')
                                    ->label(__('app.settings.fields.email_address'))
                                    ->email()
                                    ->prefixIcon('heroicon-o-envelope'),
                                TextInput::make('general.gym_contact')
                                    ->tel()
                                    ->prefixIcon('heroicon-o-phone')
                                    ->label(__('app.settings.fields.contact_no')),
                            ]),
                    ])
                    ->columnSpan(3),
            ]);
    }

    /**
     * Invoice Tab Schema.
     */
    private function invoiceTab(): Tab
    {
        return
            Tab::make(__('app.settings.tabs.invoice'))->icon('heroicon-m-document-text')
                ->schema([
                    Grid::make(3)
                        ->schema([
                            TextInput::make('invoice.prefix')
                                ->placeholder(__('app.settings.placeholders.prefix'))
                                ->label(__('app.settings.fields.prefix')),
                            TextInput::make('invoice.last_number')
                                ->numeric()
                                ->label(__('app.settings.fields.last_number'))
                                ->maxLength(10),
                            Select::make('invoice.name_type')
                                ->native(false)
                                ->label(__('app.settings.fields.name_type'))
                                ->options([
                                    'gym_name' => __('app.settings.options.name_type.gym_name'),
                                    'gym_logo' => __('app.settings.options.name_type.gym_logo'),
                                ]),
                        ]),
                    Fieldset::make(__('app.settings.sections.email'))
                        ->columns(['default' => 1, 'md' => 5])
                        ->schema([
                            Group::make()
                                ->schema([
                                    TextInput::make('notifications.email.invoice_subject_template')
                                        ->label(__('app.settings.fields.email_invoice_subject'))
                                        ->placeholder(__('app.settings.placeholders.invoice_email_subject'))
                                        ->helperText(__('app.settings.hints.tokens_invoice')),
                                    TextInput::make('notifications.email.receipt_subject_template')
                                        ->label(__('app.settings.fields.email_receipt_subject'))
                                        ->placeholder(__('app.settings.placeholders.receipt_email_subject'))
                                        ->helperText(__('app.settings.hints.tokens_receipt')),
                                ])->columnSpan(['default' => 1, 'md' => 3]),
                            Group::make()
                                ->schema([
                                    Toggle::make('notifications.email.enabled')
                                        ->label(__('app.settings.fields.email_enabled'))
                                        ->default(false)
                                        ->inlineLabel(),
                                    Toggle::make('notifications.email.auto_send_invoice_issued')
                                        ->label(__('app.settings.fields.auto_send_invoice_issued'))
                                        ->default(false)
                                        ->inlineLabel(),
                                    Toggle::make('notifications.email.auto_send_payment_receipt')
                                        ->label(__('app.settings.fields.auto_send_payment_receipt'))
                                        ->default(false)
                                        ->inlineLabel(),
                                ])
                                ->columns(1)
                                ->columnSpan(['default' => 1, 'md' => 2]),
                        ]),
                ]);
    }

    /**
     * Member Tab Schema.
     */
    private function memberTab(): Tab
    {
        return
            Tab::make(__('app.settings.tabs.member'))->icon('heroicon-m-user-group')
                ->schema([
                    Grid::make(2)
                        ->schema([
                            TextInput::make('member.prefix')
                                ->placeholder(__('app.settings.placeholders.prefix'))
                                ->label(__('app.settings.fields.prefix')),
                            TextInput::make('member.last_number')
                                ->numeric()
                                ->label(__('app.settings.fields.last_number'))
                                ->maxLength(10),
                        ]),
                ]);
    }

    /**
     * Charges Tab Schema.
     */
    private function chargesTab(): Tab
    {
        return
            Tab::make(__('app.settings.tabs.charges'))->icon('heroicon-m-currency-rupee')
                ->schema([
                    Grid::make(3)
                        ->schema([
                            TextInput::make('charges.admission_fee')
                                ->numeric()
                                ->label(__('app.settings.fields.admission_fee')),
                            TextInput::make('charges.taxes')
                                ->numeric()
                                ->label(__('app.settings.fields.taxes'))
                                ->suffix('%'),
                            TagsInput::make('charges.discounts')
                                ->label(__('app.settings.fields.discount_percent_available'))
                                ->hint(__('app.settings.hints.press_enter_to_add'))
                                ->placeholder(__('app.settings.hints.type_discount'))
                                ->separator(','),
                        ]),
                ]);
    }

    /**
     * Expenses Tab Schema.
     */
    private function expensesTab(): Tab
    {
        return
            Tab::make(__('app.settings.tabs.expenses'))->icon('heroicon-m-banknotes')
                ->schema([
                    TagsInput::make('expenses.categories')
                        ->label(__('app.settings.fields.categories'))
                        ->hint(__('app.settings.hints.press_enter_to_add'))
                        ->placeholder(__('app.settings.hints.type_category'))
                        ->separator(','),
                ]);
    }

    /**
     * Subscriptions Tab Schema.
     */
    private function subscriptionsTab(): Tab
    {
        return
            Tab::make(__('app.settings.tabs.subscriptions'))->icon('heroicon-m-ticket')
                ->schema([
                    TextInput::make('subscriptions.expiring_days')
                        ->label(__('app.settings.fields.expiring_days'))
                        ->numeric()
                        ->minValue(1)
                        ->default(7)
                        ->required(),
                ]);
    }

    /**
     * SMS notifications tab.
     */
    private function smsTab(): Tab
    {
        $templates = [
            'enquiry_created',
            'follow_up_scheduled',
            'member_created',
            'subscription_started',
            'subscription_renewed',
            'invoice_issued',
            'payment_received',
        ];

        $templateFields = [];

        foreach ($templates as $template) {
            $templateFields[] = Toggle::make("notifications.sms.templates.{$template}.enabled")
                ->label(__("app.settings.sms_events.{$template}"))
                ->inlineLabel();
            $templateFields[] = Textarea::make("notifications.sms.templates.{$template}.message")
                ->label(__('app.settings.fields.sms_template'))
                ->rows(2)
                ->maxLength(1000)
                ->helperText(__('app.settings.hints.sms_template_tokens'));
        }

        return Tab::make(__('app.settings.tabs.sms'))
            ->icon('heroicon-m-chat-bubble-left-right')
            ->schema([
                Section::make(__('app.settings.sections.fast2sms'))
                    ->schema([
                        Toggle::make('notifications.sms.enabled')
                            ->label(__('app.settings.fields.sms_enabled'))
                            ->inlineLabel(),
                        TextInput::make('notifications.sms.api_key')
                            ->label(__('app.settings.fields.fast2sms_api_key'))
                            ->password()
                            ->revealable()
                            ->autocomplete(false),
                        TextInput::make('notifications.sms.route')
                            ->label(__('app.settings.fields.fast2sms_route'))
                            ->default('q')
                            ->disabled()
                            ->dehydrated(),
                        View::make('filament.pages.sms-connection-status')
                            ->viewData(fn (): array => [
                                'status' => $this->fast2SmsConnectionStatus,
                                'message' => $this->fast2SmsConnectionMessage,
                            ])
                            ->columnSpanFull(),
                    ]),
                Section::make(__('app.settings.sections.sms_templates'))
                    ->schema($templateFields),
                View::make('filament.pages.sms-delivery-logs')
                    ->viewData(fn (): array => ['smsLogs' => $this->smsDeliveryLogs])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Configures a form instance by setting its schema and state path.
     *
     * @param  Schema  $schema  The form instance to configure.
     * @return Schema The configured form instance.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->getFormSchema())
            ->statePath('data');
    }

    /**
     * Persist the current settings.
     */
    public function save(): void
    {
        $settings = $this->form->getState();
        $repository = app(SettingsRepository::class);
        $existingSettings = $repository->get();
        $general = is_array($settings['general'] ?? null) ? $settings['general'] : [];

        if (! empty($general['financial_year_start']) && is_string($general['financial_year_start'])) {
            $start = Carbon::parse($general['financial_year_start'])
                ->startOfMonth();

            $general['financial_year_start'] = $start->toDateString();
            $general['financial_year_end'] = $start->copy()->addYear()->subDay()->toDateString();
        }

        if (! empty($general['financial_year_end']) && is_string($general['financial_year_end'])) {
            $general['financial_year_end'] =
                Carbon::parse($general['financial_year_end'])
                    ->toDateString();
        }

        foreach (['gym_logo'] as $logoKey) {
            $value = $general[$logoKey] ?? null;
            if (is_array($value)) {
                $general[$logoKey] = $value[0] ?? null;
            }
        }

        $settings['general'] = $general;

        if ($this->canManageSoftwareConfiguration()) {
            $software = is_array($settings['software'] ?? null) ? $settings['software'] : [];
            foreach (['logo', 'dark_mode_logo', 'favicon'] as $imageKey) {
                if (is_array($software[$imageKey] ?? null)) {
                    $software[$imageKey] = array_values($software[$imageKey])[0] ?? null;
                }
            }
            $settings['software'] = $software;
        } else {
            $settings['software'] = is_array($existingSettings['software'] ?? null)
                ? $existingSettings['software']
                : [];
        }

        try {
            $repository->put($settings);
            $this->form->fill($settings);
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('app.notifications.failed'))
                ->body(__('app.notifications.failed_settings_save'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('app.notifications.success'))
            ->body(__('app.notifications.success_settings_save'))
            ->success()
            ->send();
    }
}
