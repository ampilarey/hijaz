<?php

namespace App\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Telegram;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Resources\Islands\IslandResource;
use App\Filament\Resources\Trips\TripResource;
use Filament\Facades\Filament;

/**
 * The bar along the bottom of a phone screen: the five things staff do most.
 *
 * Each item is shown only to someone who may open it, so a group leader's
 * bar is shorter than Finance's. The list grows with each phase (Trips,
 * Pilgrims, Scan) and never beyond five.
 */
final class BottomNav
{
    /**
     * @return list<array{label: string, url: string, icon: string, active: bool}>
     */
    public static function items(): array
    {
        if (! Filament::auth()->check()) {
            return [];
        }

        $candidates = [
            ['Today', Dashboard::class, 'home'],
            ['Trips', TripResource::class, 'trips'],
            ['Rates', ExchangeRateResource::class, 'rates'],
            ['Accounts', AccountResource::class, 'accounts'],
            ['Islands', IslandResource::class, 'islands'],
            ['Telegram', Telegram::class, 'telegram'],
        ];

        $items = [];

        foreach ($candidates as [$label, $class, $icon]) {
            if (! $class::canAccess()) {
                continue;
            }

            $url = $class::getUrl();

            $items[] = [
                'label' => $label,
                'url' => $url,
                'icon' => $icon,
                'active' => request()->url() === $url || str_starts_with(request()->url(), $url.'/'),
            ];

            if (count($items) === 5) {
                break;
            }
        }

        return $items;
    }

    public static function render(): string
    {
        $items = self::items();

        return $items === [] ? '' : view('filament.portal.bottom-nav', ['items' => $items])->render();
    }
}
