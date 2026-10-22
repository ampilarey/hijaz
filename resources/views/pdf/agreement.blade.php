@extends('pdf._document', [
    'title' => 'Agreement',
    'number' => $booking->reference,
    'date' => $agreement->signed_at ?? now(),
])

@section('meta')
    <tr><td class="k">Customer</td><td class="strong">{{ $booking->customer->name }}@if ($booking->customer->phone) <span class="muted">· {{ $booking->customer->phone }}</span>@endif</td></tr>
    <tr><td class="k">Trip</td><td>{{ $booking->trip->name }} <span class="muted">· {{ $booking->trip->code }} · {{ $booking->trip->datesLabel() }}</span></td></tr>
    <tr><td class="k">Travellers</td><td>{{ $booking->pilgrims->map(fn ($bp) => $bp->pilgrim->name_en)->implode(', ') }}</td></tr>
@endsection

@section('body')
    <h2>Terms</h2>
    <div style="white-space: pre-line;">{{ $agreement->terms }}</div>

    <h2>Signature</h2>
    @if ($agreement->isSigned() && $agreement->method === \App\Models\Agreement::DIGITAL)
        <table class="meta">
            <tr><td class="k">Signed by</td><td class="strong">{{ $agreement->signed_name }}</td></tr>
            <tr><td class="k">On</td><td>{{ $agreement->signed_at->format('j F Y, H:i') }} <span class="muted">· in the customer portal</span></td></tr>
            <tr><td class="k">Signature</td><td>@if ($agreement->signature)<img src="{{ $agreement->signature }}" style="height: 22mm; width: auto; border-bottom: 1px solid #a8a29e;">@endif</td></tr>
        </table>
        <p class="small muted">Signed on screen from {{ $agreement->signed_ip }}. Wording fingerprint {{ substr($agreement->terms_hash, 0, 16) }}.</p>
    @elseif ($agreement->isSigned())
        <p>{{ $agreement->statusLabel() }}. The signed copy is kept with the booking.</p>
    @else
        <table class="meta" style="margin-top: 10mm;">
            <tr><td class="k">Name</td><td style="border-bottom: 1px solid #a8a29e; height: 9mm;"></td></tr>
            <tr><td class="k">Signature</td><td style="border-bottom: 1px solid #a8a29e; height: 14mm;"></td></tr>
            <tr><td class="k">Date</td><td style="border-bottom: 1px solid #a8a29e; height: 9mm;"></td></tr>
        </table>
        <p class="small muted">Sign and return to the office, or sign on screen in the customer portal.</p>
    @endif
@endsection
