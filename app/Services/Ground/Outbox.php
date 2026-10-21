<?php

namespace App\Services\Ground;

use App\Events\IncidentRaised;
use App\Models\Account;
use App\Models\BookingPilgrim;
use App\Models\ExcursionBooking;
use App\Models\Incident;
use App\Models\Payment;
use App\Models\RollCall;
use App\Models\RollCallMark;
use App\Models\Trip;
use App\Models\User;
use App\Services\Payments\Ledger;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applying what a leader did on a phone with no signal. Ported from Rihla.
 *
 * A replay must not become a second record: a mark is keyed on (count,
 * traveller) and the leader's clock decides between two marks; an
 * incident and a cash receipt carry a uuid made on the phone before the
 * first attempt, so a retry is recognised. Nothing is trusted because it
 * came from the outbox: every item is checked against what this person may
 * do and the trip it claims.
 */
final class Outbox
{
    public const MARK = 'mark';

    public const INCIDENT = 'incident';

    public const CASH = 'cash';

    public const APPLIED = 'applied';

    public const DUPLICATE = 'duplicate';

    public const SUPERSEDED = 'superseded';

    public const REFUSED = 'refused';

    public function __construct(private readonly LeaderContext $context, private readonly Ledger $ledger) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{id: mixed, result: string, reason?: string}>
     */
    public function apply(array $items, User $actor): array
    {
        $results = [];

        foreach ($items as $item) {
            try {
                $results[] = DB::transaction(fn (): array => $this->applyOne(is_array($item) ? $item : [], $actor));
            } catch (\Throwable $e) {
                report($e);
                $results[] = ['id' => is_array($item) ? ($item['id'] ?? null) : null, 'result' => self::REFUSED, 'reason' => 'That item could not be read.'];
            }
        }

        return $results;
    }

    /** @param  array<string, mixed>  $item */
    private function applyOne(array $item, User $actor): array
    {
        $id = $item['id'] ?? null;

        return match ($item['type'] ?? null) {
            self::MARK => $this->mark($item, $actor) + ['id' => $id],
            self::INCIDENT => $this->incident($item, $actor) + ['id' => $id],
            self::CASH => $this->cash($item, $actor) + ['id' => $id],
            default => ['id' => $id, 'result' => self::REFUSED, 'reason' => 'Unknown kind of write.'],
        };
    }

    /** @param  array<string, mixed>  $item */
    private function mark(array $item, User $actor): array
    {
        if (! $actor->can('rollCall.mark')) {
            return ['result' => self::REFUSED, 'reason' => 'You may not mark a head count.'];
        }

        $rollCall = RollCall::query()->with('trip')->find($this->id($item['roll_call_id'] ?? null));

        if ($rollCall === null || ! $this->context->mayWorkOn($actor, $rollCall->trip)) {
            return ['result' => self::REFUSED, 'reason' => 'That head count is not yours.'];
        }

        $bp = BookingPilgrim::query()->find($this->id($item['booking_pilgrim_id'] ?? null));

        if ($bp === null || ! $rollCall->expected()->contains('id', $bp->getKey())) {
            return ['result' => self::REFUSED, 'reason' => 'That person is not on this count.'];
        }

        $state = $item['state'] ?? null;

        if (! array_key_exists($state, RollCallMark::STATES)) {
            return ['result' => self::REFUSED, 'reason' => 'That is not a state a mark can be in.'];
        }

        $note = $this->text($item['note'] ?? null);

        if ($note === false) {
            return ['result' => self::REFUSED, 'reason' => 'A note is up to 255 characters.'];
        }

        $markedAt = $this->time($item['marked_at'] ?? null);
        $existing = RollCallMark::query()->where('roll_call_id', $rollCall->getKey())->where('booking_pilgrim_id', $bp->getKey())->first();

        if ($existing !== null && $existing->marked_at->greaterThanOrEqualTo($markedAt)) {
            return ['result' => self::SUPERSEDED];
        }

        RollCallMark::query()->updateOrCreate(
            ['roll_call_id' => $rollCall->getKey(), 'booking_pilgrim_id' => $bp->getKey()],
            ['state' => $state, 'note' => $note, 'marked_by' => $actor->getKey(), 'marked_at' => $markedAt],
        );

        return ['result' => self::APPLIED];
    }

    /** @param  array<string, mixed>  $item */
    private function incident(array $item, User $actor): array
    {
        if (! $actor->can('incident.create')) {
            return ['result' => self::REFUSED, 'reason' => 'You may not raise an incident.'];
        }

        $uuid = $item['client_uuid'] ?? null;

        if (! is_string($uuid) || $uuid === '' || strlen($uuid) > 36) {
            return ['result' => self::REFUSED, 'reason' => 'A queued incident needs an id made on the phone.'];
        }

        if (Incident::query()->where('client_uuid', $uuid)->exists()) {
            return ['result' => self::DUPLICATE];
        }

        $trip = Trip::query()->find($this->id($item['trip_id'] ?? null));

        if ($trip === null || ! $this->context->mayWorkOn($actor, $trip)) {
            return ['result' => self::REFUSED, 'reason' => 'That trip is not yours.'];
        }

        if (! array_key_exists($item['severity'] ?? '', Incident::SEVERITIES)) {
            return ['result' => self::REFUSED, 'reason' => 'That is not a severity.'];
        }

        $summary = $this->text($item['summary'] ?? null);
        $location = $this->text($item['location'] ?? null);

        if ($summary === false || $location === false || trim((string) $summary) === '') {
            return ['result' => self::REFUSED, 'reason' => 'An incident needs a line saying what happened, up to 255 characters.'];
        }

        $incident = Incident::query()->create([
            'client_uuid' => $uuid,
            'trip_id' => $trip->getKey(),
            'booking_pilgrim_id' => $this->travellerOn($item['booking_pilgrim_id'] ?? null, $trip),
            'severity' => $item['severity'],
            'category' => array_key_exists($item['category'] ?? '', Incident::CATEGORIES) ? $item['category'] : 'other',
            'summary' => trim((string) $summary),
            'detail' => is_string($item['detail'] ?? null) ? mb_substr($item['detail'], 0, 5000) : null,
            'location' => $location,
            'happened_at' => $this->time($item['happened_at'] ?? null),
            'recorded_by' => $actor->getKey(),
        ]);

        DB::afterCommit(fn () => IncidentRaised::dispatch($incident));

        return ['result' => self::APPLIED, 'reference' => $incident->reference];
    }

    /**
     * Riyal handed to the leader for an additional trip: a cash payment
     * into the leader's own float, verified at once because the leader is
     * the one holding the money.
     *
     * @param  array<string, mixed>  $item
     */
    private function cash(array $item, User $actor): array
    {
        if (! $actor->can('payment.create')) {
            return ['result' => self::REFUSED, 'reason' => 'You may not record payments.'];
        }

        $uuid = $item['client_uuid'] ?? null;

        if (! is_string($uuid) || $uuid === '' || strlen($uuid) > 36) {
            return ['result' => self::REFUSED, 'reason' => 'A queued receipt needs an id made on the phone.'];
        }

        if (Payment::query()->where('payer_reference', 'phone:'.$uuid)->exists()) {
            return ['result' => self::DUPLICATE];
        }

        $xb = ExcursionBooking::query()->with(['booking.trip', 'excursion'])->find($this->id($item['excursion_booking_id'] ?? null));

        if ($xb === null || ! $this->context->mayWorkOn($actor, $xb->booking->trip)) {
            return ['result' => self::REFUSED, 'reason' => 'That additional trip is not yours.'];
        }

        if ($xb->status === ExcursionBooking::CANCELLED) {
            return ['result' => self::REFUSED, 'reason' => 'That booking was cancelled.'];
        }

        $minor = $this->id($item['amount_minor'] ?? null) ?? $xb->balance()->minor;

        if ($minor <= 0) {
            return ['result' => self::REFUSED, 'reason' => 'Nothing is due on that booking.'];
        }

        $float = Account::query()->where('kind', Account::LEADER_CASH)->where('currency', $xb->currency)->where('holder_user_id', $actor->getKey())->where('is_active', true)->first()
            ?? Account::query()->create(['name' => $actor->name.' '.$xb->currency.' float', 'kind' => Account::LEADER_CASH, 'currency' => $xb->currency, 'holder_user_id' => $actor->getKey(), 'sort_order' => 50]);

        $payment = $this->ledger->record($xb, Money::ofMinor($minor, $xb->currency), Payment::CASH, [
            'paid_on' => $this->time($item['paid_at'] ?? null)->toDateString(),
            'account_id' => $float->getKey(),
            'payer_name' => $xb->customer?->name,
            'payer_reference' => 'phone:'.$uuid,
            'notes' => 'Cash to '.$actor->name.' for '.($xb->excursion?->title ?? 'an additional trip'),
        ]);
        $this->ledger->verify($payment, null, 'Cash received by the leader', $actor);

        return ['result' => self::APPLIED, 'reference' => $payment->reference];
    }

    private function travellerOn(mixed $id, Trip $trip): ?int
    {
        $id = $this->id($id);

        if ($id === null) {
            return null;
        }

        return BookingPilgrim::query()->whereKey($id)->whereHas('booking', fn ($b) => $b->where('trip_id', $trip->getKey()))->exists() ? $id : null;
    }

    /** A phone's time, or now; never the future, which nothing could supersede. */
    private function time(mixed $value): Carbon
    {
        if (! is_string($value) || $value === '') {
            return now();
        }

        try {
            $t = Carbon::parse($value);
        } catch (\Throwable) {
            return now();
        }

        return $t->isFuture() ? now() : $t;
    }

    private function id(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private function text(mixed $value): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) && mb_strlen($value) <= 255 ? $value : false;
    }
}
