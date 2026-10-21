<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New booking')];
    }

    public function getTabs(): array
    {
        return [
            'live' => Tab::make('Live')->modifyQueryUsing(fn (Builder $query) => $query->live()),
            'drafts' => Tab::make('Holding seats')->modifyQueryUsing(fn (Builder $query) => $query->where('status', Booking::DRAFT)),
            'unpaid' => Tab::make('Balance due')->modifyQueryUsing(function (Builder $query): Builder {
                // Balances live in JSON per currency, so the handful of confirmed bookings is sifted here.
                $ids = Booking::query()->where('status', Booking::CONFIRMED)->get(['id', 'totals', 'paid'])->reject(fn (Booking $b): bool => $b->isPaidUp())->pluck('id');

                return $query->whereKey($ids);
            }),
            'all' => Tab::make('All'),
        ];
    }
}
