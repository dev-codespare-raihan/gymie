<?php

namespace App\Providers\Filament;

use App\Contracts\SettingsRepository;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Users\UserResource;
use App\Http\Middleware\SetAppLocale;
use App\Services\GitHubMainVersionService;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Filament panel provider for the main admin panel.
 */
class AdminPanelProvider extends PanelProvider
{
    /**
     * Configure the panel.
     */
    public function panel(Panel $panel): Panel
    {
        return $this->basePanel($panel)
            ->navigation(fn (NavigationBuilder $builder) => $this->buildNavigation($builder));
    }

    /**
     * Configure the base panel options.
     */
    public function basePanel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('/')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->passwordReset()
            ->brandName(fn (): string => $this->softwareSetting('brand_name', 'Codespare Technologies'))
            ->brandLogo(fn (): string => $this->softwareImageUrl('logo', 'images/codespare-logo.svg'))
            ->darkModeBrandLogo(fn (): string => $this->softwareImageUrl('dark_mode_logo', 'images/codespare-logo-dark-mode.svg'))
            ->brandLogoHeight('4rem')
            ->favicon(fn (): string => $this->softwareImageUrl('favicon', 'images/codespare-mark.svg'))
            ->unsavedChangesAlerts()
            ->colors($this->colors())
            ->defaultThemeMode(ThemeMode::Light)
            ->sidebarWidth('12rem')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
                Settings::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->plugins([FilamentShieldPlugin::make()
                ->navigationIcon(fn (): null => null)
                ->activeNavigationIcon(fn (): null => null)])
            ->middleware([
                SetAppLocale::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->databaseNotifications()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): HtmlString => new HtmlString(
                    Blade::render('@livewire(\\App\\Filament\\Livewire\\LocaleSwitcher::class, [], key(\'locale-switcher\'))')
                ),
            )
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn (): View => view('filament.components.footer', [
                    'githubVersion' => app(GitHubMainVersionService::class)->latest(),
                ]),
            );
    }

    /**
     * Build grouped navigation for the admin panel.
     */
    protected function buildNavigation(NavigationBuilder $builder): NavigationBuilder
    {
        $administration = [
            ...Settings::getNavigationItems(),
            ...UserResource::getNavigationItems(),
            ...RoleResource::getNavigationItems(),
        ];

        $sales = [
            ...EnquiryResource::getNavigationItems(),
            ...FollowUpResource::getNavigationItems(),
        ];

        $billing = [
            ...InvoiceResource::getNavigationItems(),
            ...ExpenseResource::getNavigationItems(),
        ];

        $memberships = [
            ...MemberResource::getNavigationItems(),
            ...PlanResource::getNavigationItems(),
            ...ServiceResource::getNavigationItems(),
            ...SubscriptionResource::getNavigationItems(),
        ];

        return $builder
            ->groups([
                NavigationGroup::make(__('app.navigation.groups.sales'))
                    ->icon('heroicon-o-shopping-cart')
                    ->items($sales)
                    ->collapsed(false),

                NavigationGroup::make(__('app.navigation.groups.memberships'))
                    ->icon('heroicon-o-user-group')
                    ->items($memberships)
                    ->collapsed(false),

                NavigationGroup::make(__('app.navigation.groups.billing'))
                    ->icon('heroicon-o-document-text')
                    ->items($billing)
                    ->collapsed(false),

                NavigationGroup::make(__('app.navigation.groups.administration'))
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->items($administration)
                    ->collapsed(false),
            ])
            ->item(
                NavigationItem::make(__('app.navigation.dashboard'))
                    ->icon('heroicon-o-chart-bar')
                    ->url(fn () => Dashboard::getUrl())
                    ->isActiveWhen(fn () => request()->routeIs('filament.admin.pages.dashboard'))
            );
    }

    /**
     * Panel color palette.
     *
     * @return array<string, mixed>
     */
    protected function colors(): array
    {
        $brandColor = $this->softwareSetting('primary_color', '#00499d');
        $primary = is_string($brandColor) && preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor)
            ? Color::hex($brandColor)
            : Color::hex('#00499d');

        return [
            'primary' => $primary,
            'danger' => Color::Rose,
            'gray' => Color::Gray,
            'info' => Color::Blue,
            'success' => Color::Emerald,
            'warning' => Color::Orange,
        ];
    }

    private function softwareSetting(string $key, string $default): string
    {
        $settings = app(SettingsRepository::class)->get();
        $value = data_get($settings, "software.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function softwareImageUrl(string $key, string $fallback): string
    {
        $path = $this->softwareSetting($key, '');
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            return asset('storage/'.$path);
        }

        $version = file_exists(public_path($fallback)) ? filemtime(public_path($fallback)) : time();

        return asset($fallback).'?v='.$version;
    }
}
