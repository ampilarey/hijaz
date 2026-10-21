@php
    use App\Models\BookingLine;
    use App\Support\Money;

    $byCurrency = $booking->lines->groupBy('currency');
@endphp
@extends('pdf._document', [
    'title' => 'Invoice',
    'number' => $booking->reference,
    'date' => now(),
])

@section('meta')
    <tr><td class="k">Customer</td><td class="strong">{{ $booking->customer->name }}@if ($booking->customer->phone) <span class="muted">· {{ $booking->customer->phone }}</span>@endif</td></tr>
    <tr><td class="k">Trip</td><td>{{ $booking->trip->name }} <span class="muted">· {{ $booking->trip->code }} · {{ $booking->trip->departs_on->format('j M') }} – {{ $booking->trip->returns_on->format('j M Y') }}</span></td></tr>
    <tr><td class="k">Status</td><td>{{ $booking->statusLabel() }}</td></tr>
@endsection

@section('body')
    <h2>Travellers</h2>
    <table class="lines">
        <thead><tr><th style="width: 6mm;">#</th><th>Name</th><th>Room</th><th>Fare</th><th class="right">Amount</th></tr></thead>
        <tbody>
        @foreach ($booking->pilgrims as $i => $bp)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $bp->pilgrim->name_en }}@if ($bp->pilgrim->name_dv) <span class="dv muted">{{ $bp->pilgrim->name_dv }}</span>@endif</td>
                <td>{{ \App\Models\PriceTier::OCCUPANCIES[$bp->occupancy] ?? ucfirst($bp->occupancy) }}</td>
                <td>{{ \App\Models\PriceTier::PAX_TYPES[$bp->pax_type] ?? ucfirst($bp->pax_type) }}</td>
                <td class="right">{{ $bp->money()->format() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @foreach ($byCurrency as $currency => $lines)
        <h2>Charges in {{ $currency }}</h2>
        <table class="lines">
            <thead><tr><th>Description</th><th class="right" style="width: 14mm;">Qty</th><th class="right" style="width: 30mm;">Unit</th><th class="right" style="width: 32mm;">Amount</th></tr></thead>
            <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="right">{{ $line->quantity }}</td>
                    <td class="right">{{ Money::ofMinor($line->unit_amount_minor, $line->currency)->format() }}</td>
                    <td class="right{{ $line->amount_minor < 0 ? ' green' : '' }}">{{ $line->money()->format() }}</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="3" class="right">Total {{ $currency }}</td><td class="right">{{ $booking->total($currency)->format() }}</td></tr>
            <tr class="sub"><td colspan="3" class="right">Received</td><td class="right">{{ $booking->paidIn($currency)->format() }}</td></tr>
            <tr class="total"><td colspan="3" class="right">{{ $booking->balance($currency)->minor > 0 ? 'Balance due' : 'Balance' }}</td><td class="right {{ $booking->balance($currency)->minor > 0 ? 'red' : 'green' }}">{{ $booking->balance($currency)->format() }}</td></tr>
            </tbody>
        </table>
    @endforeach

    @if ($payments->isNotEmpty())
        <h2>Payments received</h2>
        <table class="lines">
            <thead><tr><th>Reference</th><th>Date</th><th>Method</th><th>Settles</th><th class="right">Amount</th></tr></thead>
            <tbody>
            @foreach ($payments as $p)
                <tr>
                    <td>{{ $p->reference }}@if ($p->payer_reference) <span class="muted small">· {{ $p->payer_reference }}</span>@endif</td>
                    <td>{{ $p->paid_on?->format('j M Y') }}</td>
                    <td>{{ \App\Models\Payment::METHODS[$p->method] ?? $p->method }}</td>
                    <td>{{ $p->allocations->map(fn ($a) => $a->money()->format())->implode(', ') }}</td>
                    <td class="right">{{ $p->money()->format() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if ($booking->schedule->isNotEmpty())
        <h2>Payment schedule</h2>
        <table class="lines">
            <thead><tr><th>Instalment</th><th>Due</th><th class="right">Amount</th></tr></thead>
            <tbody>
            @foreach ($booking->schedule as $s)
                <tr><td>{{ $s->label }}</td><td>{{ $s->due_on->format('j M Y') }}</td><td class="right">{{ $s->money()->format() }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @php $due = array_filter($booking->balances(), fn (Money $m) => $m->minor > 0); @endphp
    <div class="box">
        @if ($due === [])
            <div class="paid">Paid in full. Thank you.</div>
        @else
            <div class="due">Due: {{ implode(' and ', array_map(fn (Money $m) => $m->format(), $due)) }}</div>
            @if ($bankAccounts->isNotEmpty())
                <div style="margin-top: 2mm;">Please transfer to:</div>
                <table style="margin-top: 1mm;">
                    @foreach ($bankAccounts as $a)
                        <tr>
                            <td style="width: 60mm;" class="strong">{{ $a->bank ?: $a->name }} ({{ $a->currency }})</td>
                            <td>{{ $a->account_name }} · {{ $a->account_number }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
            <div class="small muted" style="margin-top: 2mm;">Write <span class="strong">{{ $booking->reference }}</span> in the transfer remarks so the payment is matched to this booking.</div>
        @endif
    </div>
@endsection
