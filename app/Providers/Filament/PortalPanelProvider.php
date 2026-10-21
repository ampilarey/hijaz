<?php

namespace App\Providers\Filament;

use App\Filament\BottomNav;
use App\Filament\InitialsAvatarProvider;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\EnsureSecondFactorWhenRequired;
use App\Models\Trip;
use App\Support\Brand;
use App\Support\PublicAsset;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The staff portal, at /portal. One panel, staff only: pilgrims never sign
 * in here. Phone first: the theme adds touch targets and a bottom bar, and
 * every table shows a handful of columns until the screen is wide.
 */
class PortalPanelProvider extends PanelProvider
{
    public const PATH = 'portal';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('portal')
            ->path(self::PATH)
            ->brandName('Hijaz Portal')
            ->brandLogo(fn () => PublicAsset::url('images/brand/hijaz-mark.svg'))
            ->brandLogoHeight('2.25rem')
            ->favicon(fn () => PublicAsset::url('images/brand/favicon.svg'))
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // Filament builds the whole ramp from one colour and picks the
            // shade a button needs for contrast itself, so the brand dark
            // green is handed over and the rest follows.
            ->colors([
                'primary' => Color::hex(Brand::GREEN_DARK),
                'gray' => Color::Stone,
            ])
            ->login(Login::class)
            ->profile(isSimple: false)
            // Required for Admin and Finance only. The panel decides "required"
            // at boot, so it is told yes and the middleware below says for whom.
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureSecondFactorWhenRequired::class)
            ->viteTheme('resources/css/filament/portal/theme.css')
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups(['Trips', 'Pilgrims', 'Money', 'Reference', 'Admin'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => BottomNav::render())
            // The sign-in page is the front door: the trips ahead sit under the form.
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): string => view('filament.portal.login-trips', [
                'trips' => Trip::query()->whereIn('status', [Trip::OPEN, Trip::CLOSED, Trip::TRAVELLING])->whereDate('returns_on', '>=', today())->with(['hotels', 'priceTiers'])->orderBy('departs_on')->limit(6)->get(),
            ])->render())
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
            ->authMiddleware([
                Authenticate::class,
            ], isPersistent: true);
    }
}
