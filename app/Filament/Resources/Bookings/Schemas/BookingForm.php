<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Filament\Support\TripOptions;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Pilgrim;
use App\Models\PriceTier;
use App\Models\Trip;
use App\Services\Bookings\BookingDesk;
use App\Support\Money;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Two forms in one: the create form takes the trip, the customer and the
 * party; the edit form only the notes, because everything else moves
 * through the desk (pilgrims, lines, status) so the trail is complete.
 */
class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Trip and customer')
                ->columns(2)
                ->schema([
                    Select::make('trip_id')->label('Trip')->required()->native(false)->live()
                        ->options(fn (): array => TripOptions::all(liveOnly: true))
                        ->helperText(function (Get $get): ?string {
                            $trip = filled($get('trip_id')) ? Trip::query()->find($get('trip_id')) : null;

                            return $trip ? $trip->seatsAvailable().' of '.$trip->capacity_total.' seats free · '.strtolower($trip->statusLabel()) : null;
                        }),
                    Select::make('customer_id')->label('Customer')->required()->native(false)->live()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Customer::query()->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->limit(20)->get()->mapWithKeys(fn (Customer $c): array => [$c->id => $c->name.($c->phone ? ' · '.$c->phone : '')])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Customer::query()->find($value)?->name),
                ])
                ->hiddenOn('edit'),
            Section::make('Who is travelling')
                ->description('Everyone on the booking, with the room they share. The price comes from the trip\'s list by room and by age on the departure day.')
                ->schema([
                    Repeater::make('party')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('pilgrim_id')->label('Pilgrim')->required()->native(false)->live()
                                ->options(function (Get $get): array {
                                    $customerId = $get('../../customer_id');

                                    return filled($customerId)
                                        ? Pilgrim::query()->where('customer_id', $customerId)->orderBy('name_en')->get()->mapWithKeys(fn (Pilgrim $p): array => [$p->id => $p->name_en.($p->date_of_birth ? ' · '.$p->date_of_birth->age : '')])->all()
                                        : [];
                                })
                                ->distinct()
                                ->columnSpan(2),
                            Select::make('occupancy')->label('Room')->required()->native(false)->live()
                                ->options(function (Get $get): array {
                                    $trip = filled($get('../../trip_id')) ? Trip::query()->with('priceTiers')->find($get('../../trip_id')) : null;
                                    $have = $trip ? $trip->priceTiers->pluck('occupancy')->unique()->all() : [];

                                    return array_intersect_key(PriceTier::OCCUPANCIES, array_flip($have)) ?: PriceTier::OCCUPANCIES;
                                }),
                            Placeholder::make('price')->label('Price')->content(fn (Get $get): string => self::preview($get)),
                        ])
                        ->columns(4)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Add a traveller')
                        ->reorderable(false),
                ])
                ->hiddenOn('edit'),
            Textarea::make('notes')->rows(3)->columnSpanFull()
                ->placeholder('Anything the office should know: wheelchair, prefers a lower floor, paying in two parts'),
        ]);
    }

    private static function preview(Get $get): string
    {
        $tripId = $get('../../trip_id');
        $pilgrimId = $get('pilgrim_id');
        $occupancy = $get('occupancy');

        if (! filled($tripId) || ! filled($pilgrimId) || ! filled($occupancy)) {
            return '—';
        }

        $trip = Trip::query()->with('priceTiers')->find($tripId);
        $pilgrim = Pilgrim::query()->find($pilgrimId);

        if ($trip === null || $pilgrim === null) {
            return '—';
        }

        $paxType = $pilgrim->paxTypeOn($trip->departs_on ?? today());
        $tier = app(BookingDesk::class)->tierFor($trip, $occupancy, $paxType);

        return $tier ? Money::ofMinor($tier->amount_minor, $tier->currency)->format().' ('.$paxType.')' : 'no price for that room';
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return ['status' => Booking::DRAFT];
    }
}
