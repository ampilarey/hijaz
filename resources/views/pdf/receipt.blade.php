@php
    $refund = $payment->isRefund();
    $payable = $payment->payable;
    $customer = $payable?->customer;
@endphp
@extends('pdf._document', [
    'title' => $refund ? 'Refund' : 'Receipt',
    'number' => $payment->reference,
    'date' => $payment->paid_on ?? $payment->created_at,
])

@section('meta')
    <tr><td class="k">{{ $refund ? 'Paid to' : 'Received from' }}</td><td class="strong">{{ $payment->payer_name ?: ($customer?->name ?? '') }}</td></tr>
    <tr><td class="k">For</td><td>{{ $payable?->payableLabel() }}</td></tr>
    <tr><td class="k">Method</td><td>{{ \App\Models\Payment::METHODS[$payment->method] ?? $payment->method }}@if ($payment->account) <span class="muted">· into {{ $payment->account->name }}</span>@endif</td></tr>
    @if ($payment->payer_reference)<tr><td class="k">Bank reference</td><td>{{ $payment->payer_reference }}</td></tr>@endif
    @if ($refund && $payment->refundOf)<tr><td class="k">Refund of</td><td>{{ $payment->refundOf->reference }}</td></tr>@endif
@endsection

@section('body')
    <div class="box">
        <div class="{{ $refund ? 'due' : 'paid' }}">{{ $refund ? 'Refunded' : 'Received' }}: {{ \App\Support\Money::ofMinor(abs($payment->amount_minor), $payment->currency)->format() }}</div>
        @if ($payment->allocations->count() > 1 || $payment->allocations->first()?->currency !== $payment->currency)
            <div class="small muted" style="margin-top: 2mm;">
                Settles
                {{ $payment->allocations->map(fn ($a) => \App\Support\Money::ofMinor(abs($a->amount_minor), $a->currency)->format().($a->rate_snapshot ? ' at 1 '.$payment->currency.' = '.rtrim(rtrim($a->rate_snapshot, '0'), '.').' '.$a->currency : ''))->implode('; ') }}.
            </div>
        @endif
        <div class="small muted" style="margin-top: 2mm;">
            {{ $payment->statusLabel() }}@if ($payment->reviewer) by {{ $payment->reviewer->name }}@endif @if ($payment->reviewed_at) on {{ $payment->reviewed_at->format('j M Y') }}@endif.
        </div>
    </div>

    @if ($payable instanceof \App\Models\Booking)
        <h2>Booking after this {{ $refund ? 'refund' : 'payment' }}</h2>
        <table class="lines">
            <thead><tr><th>Currency</th><th class="right">Total</th><th class="right">Received</th><th class="right">Balance</th></tr></thead>
            <tbody>
            @foreach ($payable->currencies() as $c)
                <tr>
                    <td>{{ $c }}</td>
                    <td class="right">{{ $payable->total($c)->format() }}</td>
                    <td class="right">{{ $payable->paidIn($c)->format() }}</td>
                    <td class="right {{ $payable->balance($c)->minor > 0 ? 'red' : 'green' }}">{{ $payable->balance($c)->format() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @elseif ($payable instanceof \App\Models\ExcursionBooking)
        <h2>After this {{ $refund ? 'refund' : 'payment' }}</h2>
        <table class="lines">
            <thead><tr><th>Trip</th><th class="right">Total</th><th class="right">Received</th><th class="right">Balance</th></tr></thead>
            <tbody>
                <tr>
                    <td>{{ $payable->excursion?->title }}</td>
                    <td class="right">{{ $payable->total()->format() }}</td>
                    <td class="right">{{ $payable->paid()->format() }}</td>
                    <td class="right {{ $payable->balance()->minor > 0 ? 'red' : 'green' }}">{{ $payable->balance()->format() }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    @if ($payment->notes)
        <p class="small muted" style="margin-top: 4mm;">{{ $payment->notes }}</p>
    @endif

    <p style="margin-top: 10mm;"><span class="stamp">{{ $refund ? 'Refunded' : 'Paid' }}</span></p>
@endsection
