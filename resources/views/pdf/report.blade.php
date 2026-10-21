{{-- A report: the headline figures, then each table. With $letter, a cover letter on the letterhead comes first (the bank USD list). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        @font-face { font-family: 'Faruma'; src: url('{{ public_path('fonts/A_faruma.ttf') }}') format('truetype'); }
        @page { margin: 14mm 12mm 16mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: {{ \App\Support\Brand::INK }}; line-height: 1.4; }
        table.head { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
        table.head td { vertical-align: middle; padding: 0; }
        h1 { font-size: 15pt; color: {{ \App\Support\Brand::GREEN_DARK }}; margin: 0; }
        h2 { font-size: 10.5pt; color: {{ \App\Support\Brand::GREEN_DARK }}; margin: 6mm 0 2mm; border-bottom: 1px solid {{ \App\Support\Brand::GREEN }}; padding-bottom: 1mm; }
        .sub { color: {{ \App\Support\Brand::MUTED }}; margin-top: 1mm; }
        .issuer { text-align: right; font-size: 7.5pt; color: {{ \App\Support\Brand::MUTED }}; }
        table.figures { border-collapse: collapse; margin: 2mm 0; }
        table.figures td { padding: 1.2mm 6mm 1.2mm 0; vertical-align: top; }
        table.figures td.k { color: {{ \App\Support\Brand::MUTED }}; white-space: nowrap; }
        table.figures td.v { font-weight: bold; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: .04em; color: {{ \App\Support\Brand::MUTED }}; border-bottom: 1.2px solid {{ \App\Support\Brand::INK }}; padding: 1.2mm; }
        table.list td { padding: 1.3mm 1.2mm; border-bottom: 0.4px solid #d6d3d1; vertical-align: top; }
        table.list tr:nth-child(even) td { background: #f7f7f6; }
        .notes { margin-top: 4mm; font-size: 7.5pt; color: {{ \App\Support\Brand::MUTED }}; }
        .foot { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: {{ \App\Support\Brand::MUTED }}; }
        .foot .page:after { content: counter(page); }
        .letter { font-size: 10pt; line-height: 1.6; page-break-after: always; }
        .letter p { margin: 0 0 4mm; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 16mm;"><img src="{{ public_path('images/brand/hijaz-mark.png') }}" alt="" style="width: 13mm; height: 13mm;"></td>
            <td>
                <h1>{{ $letter ? $issuer['name'] : $report->title }}</h1>
                <div class="sub">{{ $letter ? ($issuer['address'] ?: '') : $report->subtitle }}</div>
            </td>
            <td class="issuer">{{ $issuer['name'] }}<br>{{ $issuer['phone'] }}<br>{{ $issuer['email'] }}<br>{{ now()->format('j M Y') }}</td>
        </tr>
    </table>

    @if ($letter)
        <div class="letter">
            <p>{{ now()->format('j F Y') }}</p>
            <p>The Manager<br>Foreign Exchange Department</p>
            <p><strong>Request for USD allocation for Umrah pilgrims · {{ $report->subtitle }}</strong></p>
            <p>Dear Sir or Madam,</p>
            <p>{{ $issuer['name'] }}{{ $issuer['registration'] ? ' (registration '.$issuer['registration'].')' : '' }} is taking the pilgrims listed on the following page{{ count($report->sections[0]->rows ?? []) === 1 ? '' : 's' }} for Umrah. We request the allocation of US dollars for each of them as set out in the list, in total {{ $report->figures[1]['value'] ?? '' }} for {{ $report->figures[0]['value'] ?? '' }} pilgrims.</p>
            <p>Copies of ID cards and passports are available on request.</p>
            <p>Yours faithfully,</p>
            <p style="margin-top: 14mm;">______________________<br>{{ $issuer['name'] }}<br>{{ $issuer['phone'] }}</p>
        </div>
        <h1>{{ $report->title }}</h1>
        <div class="sub">{{ $report->subtitle }}</div>
    @endif

    @if ($report->figures !== [])
        <table class="figures">
            @foreach ($report->figures as $f)
                <tr><td class="k">{{ $f['label'] }}</td><td class="v">{{ $f['value'] }}</td></tr>
            @endforeach
        </table>
    @endif

    @foreach ($report->sections as $section)
        <h2>{{ $section->title }}@if ($section->subtitle !== '') <span class="sub" style="font-weight: normal; font-size: 8pt;">· {{ $section->subtitle }}</span>@endif</h2>
        <table class="list">
            <thead><tr>@foreach ($section->columns as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse ($section->rows as $row)
                <tr>@foreach ($section->columns as $key => $heading)<td>{{ $row[$key] ?? '' }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($section->columns) }}" style="color: {{ \App\Support\Brand::MUTED }};">Nothing to show.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endforeach

    @if ($report->notes !== [])
        <div class="notes">@foreach ($report->notes as $note)<div>{{ $note }}</div>@endforeach</div>
    @endif

    <div class="foot">{{ $issuer['name'] }} · {{ $report->title }} · {{ $report->subtitle }} · page <span class="page"></span></div>
</body>
</html>
