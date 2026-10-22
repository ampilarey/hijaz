<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BookingPilgrim;
use App\Models\BusGroup;
use App\Models\Card;
use App\Models\Trip;
use App\Models\TripExcursion;
use App\Models\TripHotel;
use App\Observers\AuditObserver;
use App\Services\Operations\Cards;
use App\Services\Operations\ListDocument;
use App\Services\Operations\Lists;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lists, manifests, cards and tags, rendered when asked for by signed-in
 * staff who may see lists. Every print of personal data is audited.
 */
class OperationsController extends Controller
{
    public function __construct(private readonly Lists $lists, private readonly Cards $cards) {}

    public function manifest(Trip $trip, Request $request): Response|StreamedResponse
    {
        $this->allow($request);
        $preset = (string) $request->query('preset', 'office');
        abort_unless(array_key_exists($preset, Lists::PRESETS), 404);

        $only = $request->filled('ids') ? $this->lists->travellers($trip)->whereIn('id', array_map('intval', explode(',', (string) $request->query('ids')))) : null;

        $group = $request->filled('group') ? $trip->groups()->findOrFail((int) $request->query('group')) : null;

        return $this->send($this->lists->manifest($trip, $preset, $only, $group), $request, $trip, array_filter(['list' => 'manifest', 'preset' => $preset, 'group' => $group?->name]));
    }

    public function busList(BusGroup $busGroup, Request $request): Response|StreamedResponse
    {
        $this->allow($request);

        return $this->send($this->lists->busList($busGroup), $request, $busGroup, ['list' => 'bus']);
    }

    public function roomList(TripHotel $tripHotel, Request $request): Response|StreamedResponse
    {
        $this->allow($request);

        return $this->send($this->lists->roomList($tripHotel, $request->boolean('hotel')), $request, $tripHotel, ['list' => 'rooms', 'hotel_copy' => $request->boolean('hotel')]);
    }

    public function payments(Trip $trip, Request $request): Response|StreamedResponse
    {
        $this->allow($request);

        return $this->send($this->lists->payments($trip), $request, $trip, ['list' => 'payments']);
    }

    public function documents(Trip $trip, Request $request): Response|StreamedResponse
    {
        $this->allow($request);

        return $this->send($this->lists->documents($trip), $request, $trip, ['list' => 'documents']);
    }

    public function excursion(TripExcursion $tripExcursion, Request $request): Response|StreamedResponse
    {
        $this->allow($request);

        return $this->send($this->lists->excursion($tripExcursion), $request, $tripExcursion, ['list' => 'excursion']);
    }

    /** A sheet of cards or tags for the travellers named, or a whole trip or bus. */
    public function cards(Request $request, string $kind): Response
    {
        abort_unless($request->user()?->can('card.create'), 403);
        abort_unless(in_array($kind, [Card::CUSTOMER, Card::LUGGAGE], true), 404);

        $query = BookingPilgrim::query()->whereHas('booking', fn ($b) => $b->live());

        if ($request->filled('ids')) {
            $query->whereKey(array_map('intval', explode(',', (string) $request->query('ids'))));
        } elseif ($request->filled('bus')) {
            $query->where('bus_group_id', (int) $request->query('bus'));
        } elseif ($request->filled('trip')) {
            $query->whereHas('booking', fn ($b) => $b->where('trip_id', (int) $request->query('trip')));
        } else {
            abort(404);
        }

        $travellers = $query->with(['pilgrim', 'booking.customer', 'booking.trip'])->get();
        abort_if($travellers->isEmpty(), 404);

        $travellers = $travellers->sortBy(fn (BookingPilgrim $bp) => $bp->booking->customer->name.' '.$bp->pilgrim->name_en)->values();
        AuditObserver::record($travellers->first()->booking->trip, AuditLog::DOWNLOADED, null, ['document' => $kind.'_cards', 'count' => $travellers->count()]);

        return $this->cards->sheet($travellers, $kind)->stream(($kind === Card::LUGGAGE ? 'luggage-tags' : 'customer-cards').'-'.now()->format('Ymd-Hi').'.pdf');
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()?->can('list.view'), 403);
    }

    /** @param  array<string, mixed>  $meta */
    private function send(ListDocument $list, Request $request, Model $subject, array $meta): Response|StreamedResponse
    {
        $format = (string) $request->query('format', 'pdf');
        AuditObserver::record($subject, AuditLog::DOWNLOADED, null, $meta + ['format' => $format, 'rows' => count($list->rows)]);

        if ($format === 'csv') {
            return response()->streamDownload(fn () => print $list->csv(), $list->filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return $this->lists->pdf($list)->stream($list->filename.'.pdf');
    }
}
