<?php

namespace App\Providers\Filament;

use App\Domain\Tenancy\Models\Tenant;
use App\Filament\App\Pages\Tenancy\EditCompanyProfile;
use App\Filament\Pages\EditProfile;
use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Middleware\ApplyTenantContext;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\RequireMultiFactorAuthenticationForRole;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetLocaleForTenant;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The dealer app: one panel, scoped to the dealer (tenant) in the URL: /app/{tenant-slug}/...
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->brandName(fn (): string => (string) config('app.name'))
            ->login()
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->passwordReset()
            // The user profile (/app/profile) lives outside any dealer, so it cannot use the full
            // layout: the sidebar and dealer menu need a tenant and crash without one.
            ->profile(EditProfile::class, isSimple: true)
            ->colors([
                'primary' => Color::Red,
                'gray' => Color::Zinc,
            ])
            ->tenant(Tenant::class, slugAttribute: 'slug', ownershipRelationship: 'tenant')
            ->tenantProfile(EditCompanyProfile::class)
            ->multiFactorAuthentication(
                [
                    AppAuthentication::make()->recoverable(),
                    EmailAuthentication::make(),
                ],
                isRequired: fn (): bool => (bool) config('dealer.enforce_mfa'),
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(RequireMultiFactorAuthenticationForRole::class)
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->navigationGroups([
                NavigationGroup::make(fn (): string => __('Finance')),
                NavigationGroup::make(fn (): string => __('Settings')),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->middleware([
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
            ->middleware([
                SetLocale::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ])
            ->tenantMiddleware([
                ApplyTenantContext::class,
                SetLocaleForTenant::class, // again, now that the dealer (and its language) is known
                EnforceIdleTimeout::class,
            ], isPersistent: true);
    }
}
