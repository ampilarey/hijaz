{{--
    The shell every invoice and receipt is printed in: the brand at the top,
    the issuer beside it, the document title and its number, then whatever
    the page is about, then the footer notes. Rendered by dompdf, so the CSS
    stays to what dompdf does well: tables, simple blocks, no flex or grid.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $number }}</title>
    <style>
        @font-face {
            font-family: 'Faruma';
            src: url('{{ public_path('fonts/A_faruma.ttf') }}') format('truetype');
            font-weight: normal;
            font-style: normal;
        }
        @font-face {
            font-family: 'Faruma';
            src: url('{{ public_path('fonts/A_faruma.ttf') }}') format('truetype');
            font-weight: bold;
            font-style: normal;
        }
        @page { margin: 16mm 15mm 22mm 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: {{ \App\Support\Brand::INK }}; line-height: 1.4; }
        .dv { font-family: 'Faruma', 'DejaVu Sans', sans-serif; direction: rtl; display: block; font-size: 11pt; line-height: 1.2; }
        h1 { font-size: 18pt; color: {{ \App\Support\Brand::GREEN_DARK }}; margin: 0 0 2mm; letter-spacing: .02em; }
        h2 { font-size: 11pt; color: {{ \App\Support\Brand::GREEN_DARK }}; margin: 7mm 0 2mm; border-bottom: 1px solid {{ \App\Support\Brand::GREEN }}; padding-bottom: 1mm; }
        .muted { color: {{ \App\Support\Brand::MUTED }}; }
        .small { font-size: 8pt; }
        .right { text-align: right; }
        .center { text-align: center; }
        .strong { font-weight: bold; }
        .red { color: {{ \App\Support\Brand::RED_DARK }}; }
        .green { color: {{ \App\Support\Brand::GREEN_DARK }}; }
        table { width: 100%; border-collapse: collapse; }
        table.head td { vertical-align: top; padding: 0; }
        table.head .issuer { text-align: right; font-size: 8.5pt; }
        table.head .issuer .name { font-size: 12pt; font-weight: bold; color: {{ \App\Support\Brand::GREEN_DARK }}; }
        table.meta { margin: 4mm 0 2mm; }
        table.meta td { padding: 0.6mm 0; vertical-align: top; }
        table.meta td.k { width: 30mm; color: {{ \App\Support\Brand::MUTED }}; }
        table.lines th { text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .05em; color: {{ \App\Support\Brand::MUTED }}; border-bottom: 1.5px solid {{ \App\Support\Brand::INK }}; padding: 1.5mm 1.5mm; }
        table.lines td { padding: 1.8mm 1.5mm; border-bottom: 0.5px solid #d6d3d1; vertical-align: top; }
        table.lines tr.total td { border-top: 1.5px solid {{ \App\Support\Brand::INK }}; border-bottom: none; font-weight: bold; }
        table.lines tr.sub td { border-bottom: none; color: {{ \App\Support\Brand::MUTED }}; }
        .box { border: 1px solid {{ \App\Support\Brand::GREEN }}; background: {{ \App\Support\Brand::SKY_LIGHT }}; padding: 3mm 4mm; margin-top: 4mm; }
        .due { font-size: 13pt; font-weight: bold; color: {{ \App\Support\Brand::RED_DARK }}; }
        .paid { font-size: 13pt; font-weight: bold; color: {{ \App\Support\Brand::GREEN_DARK }}; }
        .foot { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 7.5pt; color: {{ \App\Support\Brand::MUTED }}; border-top: 0.5px solid #d6d3d1; padding-top: 1.5mm; }
        .stamp { display: inline-block; border: 2px solid {{ \App\Support\Brand::GREEN_DARK }}; color: {{ \App\Support\Brand::GREEN_DARK }}; padding: 1mm 3mm; font-weight: bold; letter-spacing: .1em; text-transform: uppercase; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 46mm;"><img src="{{ public_path('images/brand/hijaz-logo.png') }}" alt="Hijaz Hajj &amp; Umrah" style="width: 40mm; height: auto;"></td>
            <td class="issuer">
                <div class="name">{{ $issuer['name'] }}</div>
                @if ($issuer['registration'])<div>Reg. {{ $issuer['registration'] }}</div>@endif
                @if ($issuer['address'])<div>{{ $issuer['address'] }}</div>@endif
                @if ($issuer['phone'])<div>{{ $issuer['phone'] }}</div>@endif
                @if ($issuer['email'])<div>{{ $issuer['email'] }}</div>@endif
            </td>
        </tr>
    </table>

    <div style="margin-top: 6mm;">
        <h1>{{ $title }}</h1>
        <table class="meta">
            <tr><td class="k">Number</td><td class="strong">{{ $number }}</td></tr>
            <tr><td class="k">Date</td><td>{{ $date->format('j F Y') }}</td></tr>
            @yield('meta')
        </table>
    </div>

    @yield('body')

    <div class="foot">
        @if (config('invoices.tax_note'))<div>{{ config('invoices.tax_note') }}</div>@endif
        @if (config('invoices.terms_note'))<div>{{ config('invoices.terms_note') }}</div>@endif
        <div>{{ $issuer['name'] }} · {{ $number }} · printed {{ now()->format('j M Y H:i') }}</div>
    </div>
</body>
</html>
