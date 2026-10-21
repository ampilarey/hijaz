<?php

namespace App\Http\Controllers;

use App\Models\BookingPilgrim;
use App\Models\ExcursionBooking;
use App\Models\Incident;
use App\Models\RollCall;
use App\Models\RollCallMark;
use App\Models\Trip;
use App\Services\Ground\LeaderContext;
use App\Services\Ground\Outbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The group leader's phone pages: their people, head counts, incidents and
 * the riyal they collect. Plain Blade rather than the Filament panel, so
 * the page is small enough to cache and works from a local copy with no
 * signal; every write goes through the outbox.
 */
class LeaderController extends Controller
{
    public function __construct(private readonly LeaderContext $context, private readonly Outbox $outbox) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->can('ground.view'), 403);

        return view('ground.index', ['trips' => $this->context->trips($request->user())]);
    }

    public function trip(Request $request, Trip $trip): View
    {
        $user = $request->user();
        abort_unless($user && $this->context->mayWorkOn($user, $trip), 403);

        $travellers = $this->context->travellers($user, $trip);
        $own = $this->context->ownBuses($user, $trip);

        return view('ground.trip', [
            'trip' => $trip,
            'travellers' => $travellers,
            'rollCalls' => $trip->rollCalls()->with(['marks', 'bus'])->when($own !== [] && ! $this->context->seesEverything($user), fn ($q) => $q->where(fn ($w) => $w->whereNull('bus_group_id')->orWhereIn('bus_group_id', $own)))->limit(20)->get(),
            'incidents' => $trip->incidents()->open()->with('bookingPilgrim.pilgrim')->get(),
            'excursions' => ExcursionBooking::query()->whereIn('booking_id', $travellers->pluck('booking_id')->unique())->where('status', ExcursionBooking::OPEN)->where('total_minor', '>', 0)->with(['excursion', 'customer', 'participants.bookingPilgrim.pilgrim'])->get(),
            'buses' => $trip->busGroups()->get(),
            'ownBuses' => $own,
            'mayCount' => $user->can('rollCall.create'),
            'mayMark' => $user->can('rollCall.mark'),
            'mayRaise' => $user->can('incident.create'),
            'mayCollect' => $user->can('payment.create'),
        ]);
    }

    public function count(Request $request, Trip $trip, RollCall $rollCall): View
    {
        $user = $request->user();
        abort_unless($user && $this->context->mayWorkOn($user, $trip), 403);
        abort_unless($rollCall->trip_id === $trip->getKey(), 404);
        $rollCall->load('marks');

        return view('ground.count', [
            'trip' => $trip,
            'rollCall' => $rollCall,
            'travellers' => $rollCall->expected(),
            'marks' => $rollCall->marks->keyBy('booking_pilgrim_id'),
            'states' => RollCallMark::STATES,
            'mayMark' => $user->can('rollCall.mark'),
        ]);
    }

    public function startCount(Request $request, Trip $trip): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $this->context->mayWorkOn($user, $trip) && $user->can('rollCall.create'), 403);

        $data = $request->validate(['moment' => ['required', 'string', 'max:80'], 'bus_group_id' => ['nullable', 'integer']]);
        $bus = filled($data['bus_group_id'] ?? null) ? $trip->busGroups()->find($data['bus_group_id']) : null;

        $rollCall = $trip->rollCalls()->create(['moment' => $data['moment'], 'bus_group_id' => $bus?->getKey()]);

        return redirect()->route('ground.count', [$trip, $rollCall]);
    }

    /**
     * Everything one trip needs to work from a local copy: names, bus and
     * room, counts so far. No passport numbers, no phones, no money: this
     * sits in a phone's storage until somebody clears it.
     */
    public function snapshot(Request $request, Trip $trip): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->context->mayWorkOn($user, $trip), 403);

        $travellers = $this->context->travellers($user, $trip)->map(fn (BookingPilgrim $bp): array => [
            'id' => $bp->getKey(),
            'name' => $bp->pilgrim->name_en,
            'family' => $bp->booking->customer->name,
            'bus' => $bp->busGroup?->name,
            'rooms' => $bp->roomAssignments->map(fn ($a) => $a->room?->hotel?->cityLabel().' '.$a->room?->label)->filter()->values()->all(),
        ])->values();

        return response()->json([
            'trip' => ['id' => $trip->getKey(), 'code' => $trip->code, 'name' => $trip->name, 'departs_on' => $trip->departs_on->toDateString()],
            'travellers' => $travellers,
            'roll_calls' => $trip->rollCalls()->with('marks')->limit(20)->get()->map(fn (RollCall $r): array => [
                'id' => $r->getKey(), 'moment' => $r->moment, 'taken_at' => $r->taken_at->toIso8601String(), 'bus_group_id' => $r->bus_group_id,
                'marks' => $r->marks->mapWithKeys(fn (RollCallMark $m): array => [$m->booking_pilgrim_id => $m->state])->all(),
            ])->values(),
            'taken_at' => now()->toIso8601String(),
        ]);
    }

    /** Everything the phone queued while it had no signal; each item answered by the id the phone gave it. */
    public function sync(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('ground.view'), 403);
        $request->validate(['items' => ['required', 'array', 'max:200'], 'items.*.id' => ['required'], 'items.*.type' => ['required', 'string']]);

        return response()->json(['results' => $this->outbox->apply($request->input('items'), $request->user())]);
    }

    /** The online form for an incident, for a phone whose storage refused the outbox. */
    public function raise(Request $request, Trip $trip): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $this->context->mayWorkOn($user, $trip), 403);

        $data = $request->validate([
            'severity' => ['required', 'in:'.implode(',', array_keys(Incident::SEVERITIES))],
            'category' => ['required', 'in:'.implode(',', array_keys(Incident::CATEGORIES))],
            'summary' => ['required', 'string', 'max:255'],
            'detail' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'booking_pilgrim_id' => ['nullable', 'integer'],
            'client_uuid' => ['nullable', 'string', 'max:36'],
        ]);

        $result = $this->outbox->apply([['id' => 'form', 'type' => Outbox::INCIDENT, 'trip_id' => $trip->getKey(), 'client_uuid' => $data['client_uuid'] ?? (string) Str::uuid()] + $data], $user)[0];

        return redirect()->route('ground.trip', $trip)->with($result['result'] === Outbox::REFUSED ? 'problem' : 'done', $result['reason'] ?? ('Raised '.($result['reference'] ?? '')));
    }
}
