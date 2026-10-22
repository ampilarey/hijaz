{{--
    Travel acceptance letter, one page per traveller. A working layout until
    the office's own sample replaces it: the facts it carries (who, which
    flights, which hotels) stay the same.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Travel acceptance letter</title>
    <style>
        @page { margin: 16mm 15mm 22mm 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: {{ \App\Support\Brand::INK }}; line-height: 1.45; }
        h1 { font-size: 16pt; color: {{ \App\Support\Brand::GREEN_DARK }}; margin: 6mm 0 3mm; }
        h2 { font-size: 10.5pt; color: {{ \App\Support\Brand::GREEN_DARK }}; margin: 6mm 0 2mm; border-bottom: 1px solid {{ \App\Support\Brand::GREEN }}; padding-bottom: 1mm; }
        .muted { color: {{ \App\Support\Brand::MUTED }}; }
        .small { font-size: 8pt; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 0.8mm 0; }
        td.k { width: 34mm; color: {{ \App\Support\Brand::MUTED }}; }
        table.flights th { text-align: left; font-size: 8pt; text-transform: uppercase; color: {{ \App\Support\Brand::MUTED }}; border-bottom: 1px solid {{ \App\Support\Brand::INK }}; padding: 1mm; }
        table.flights td { padding: 1.5mm 1mm; border-bottom: 0.5px solid #d6d3d1; }
        .head td.issuer { text-align: right; font-size: 8.5pt; }
        .issuer .name { font-size: 12pt; font-weight: bold; color: {{ \App\Support\Brand::GREEN_DARK }}; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
    </style>
</head>
<body>
@forelse ($travellers as $bp)
    @php($out = $bp->flightOut())
    @php($back = $bp->flightBack())
    <div class="page">
        <table class="head">
            <tr>
                <td style="width: 46mm;"><img src="{{ public_path('images/brand/hijaz-logo.png') }}" alt="Hijaz Hajj &amp; Umrah" style="width: 40mm; height: auto;"></td>
                <td class="issuer">
                    <div class="name">{{ $issuer['name'] }}</div>
                    @if ($issuer['address'])<div>{{ $issuer['address'] }}</div>@endif
                    @if ($issuer['phone'])<div>{{ $issuer['phone'] }}</div>@endif
                    @if ($issuer['email'])<div>{{ $issuer['email'] }}</div>@endif
                </td>
            </tr>
        </table>

        <h1>Travel acceptance letter</h1>
        <p class="muted">{{ now()->format('j F Y') }} · {{ $bp->booking->reference }}</p>

        <p>Dear {{ $bp->pilgrim->name_en }},</p>
        <p>We are pleased to confirm that you have been accepted to travel with {{ $issuer['name'] }} on <strong>{{ $bp->booking->trip->name }}</strong>@if ($bp->group), in <strong>{{ $bp->group->name }}</strong>@endif. Your travel details are below.</p>

        <h2>Traveller</h2>
        <table>
            <tr><td class="k">Name</td><td><strong>{{ $bp->pilgrim->name_en }}</strong></td></tr>
            @if ($bp->pilgrim->passport_number)<tr><td class="k">Passport</td><td>{{ $bp->pilgrim->passport_number }}@if ($bp->pilgrim->passport_expires_on) <span class="muted">· valid to {{ $bp->pilgrim->passport_expires_on->format('j M Y') }}</span>@endif</td></tr>@endif
            <tr><td class="k">Booking</td><td>{{ $bp->booking->reference }} · {{ $bp->booking->customer->name }}</td></tr>
            @if ($bp->busGroup)<tr><td class="k">Bus</td><td>{{ $bp->busGroup->name }}@if ($bp->busGroup->leaderLabel()) <span class="muted">· leader {{ $bp->busGroup->leaderLabel() }}@if ($bp->busGroup->leader_phone), {{ $bp->busGroup->leader_phone }}@endif</span>@endif</td></tr>@endif
        </table>

        <h2>Flights</h2>
        <table class="flights">
            <thead><tr><th></th><th>Flight</th><th>From – to</th><th>Departs</th><th>Arrives</th></tr></thead>
            <tbody>
            @foreach (['Out' => $out, 'Home' => $back] as $label => $f)
                @if ($f)
                    <tr><td>{{ $label }}</td><td>{{ $f->airline }} {{ $f->flight_number }}</td><td>{{ $f->from_airport }} – {{ $f->to_airport }}</td><td>{{ $f->departs_at->format('j M Y, H:i') }}</td><td>{{ $f->arrives_at?->format('j M Y, H:i') ?? '' }}</td></tr>
                @endif
            @endforeach
            </tbody>
        </table>
        <p class="small muted">Times are local, as printed on the ticket. Please be at the airport three hours before departure.</p>

        @if ($bp->booking->trip->hotels->isNotEmpty())
            <h2>Hotels</h2>
            <table>
                @foreach ($bp->booking->trip->hotels as $h)
                    <tr><td class="k">{{ $h->cityLabel() }}</td><td>{{ $h->name }} <span class="muted">· {{ $h->check_in_on->format('j M') }} – {{ $h->check_out_on->format('j M Y') }}</span></td></tr>
                @endforeach
            </table>
        @endif

        <p style="margin-top: 8mm;">May Allah accept your Umrah. For anything at all, call us on {{ $issuer['phone'] }}.</p>
        <p style="margin-top: 10mm;">{{ $issuer['name'] }}</p>
    </div>
@empty
    <p>No traveller here has a confirmed booking and a flight yet, so there is no letter to print.</p>
@endforelse
</body>
</html>
