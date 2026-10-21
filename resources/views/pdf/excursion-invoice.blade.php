@extends('pdf._document', [
    'title' => 'Invoice',
    'number' => $xb->reference,
    'date' => now(),
])

@section('meta')
    <tr><td class="k">Customer</td><td class="strong">{{ $xb->customer->name }}</td></tr>
    <tr><td class="k">Additional trip</td><td>{{ $xb->excursion->title }}@if ($xb->excursion->scheduled_on) <span class="muted">· {{ $xb->excursion->scheduled_on->format('j F Y') }}</span>@endif</td></tr>
    <tr><td class="k">Umrah trip</td><td>{{ $xb->booking->trip->name }} <span class="muted">· {{ $xb->booking->reference }}</span></td></tr>
    <tr><td class="k">Status</td><td>{{ ucfirst($xb->status) }}</td></tr>
@endsection

@section('body')
    <h2>Participants</h2>
    <table class="lines">
        <thead><tr><th style="width: 6mm;">#</th><th>Name</th><th class="right">Amount</th></tr></thead>
        <tbody>
        @foreach ($xb->participants as $i => $p)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $p->bookingPilgrim?->pilgrim?->name_en }}</td>
                <td class="right">{{ \App\Support\Money::ofMinor($p->amount_minor, $xb->currency)->format() }}</td>
            </tr>
        @endforeach
        <tr class="total"><td colspan="2" class="right">Total</td><td class="right">{{ $xb->total()->format() }}</td></tr>
        <tr class="sub"><td colspan="2" class="right">Received</td><td class="right">{{ $xb->paid()->format() }}</td></tr>
        <tr class="total"><td colspan="2" class="right">{{ $xb->balance()->minor > 0 ? 'Balance due' : 'Balance' }}</td><td class="right {{ $xb->balance()->minor > 0 ? 'red' : 'green' }}">{{ $xb->balance()->format() }}</td></tr>
        </tbody>
    </table>

    @if ($payments->isNotEmpty())
        <h2>Payments received</h2>
        <table class="lines">
            <thead><tr><th>Reference</th><th>Date</th><th>Method</th><th class="right">Amount</th></tr></thead>
            <tbody>
            @foreach ($payments as $p)
                <tr>
                    <td>{{ $p->reference }}</td>
                    <td>{{ $p->paid_on?->format('j M Y') }}</td>
                    <td>{{ \App\Models\Payment::METHODS[$p->method] ?? $p->method }}</td>
                    <td class="right">{{ $p->money()->format() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="box">
        @if ($xb->balance()->minor > 0)
            <div class="due">Due: {{ $xb->balance()->format() }}</div>
            <div class="small muted" style="margin-top: 1mm;">Paid in Saudi riyal to the group leader before the trip.</div>
        @else
            <div class="paid">Paid in full. Thank you.</div>
        @endif
    </div>
@endsection
