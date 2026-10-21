{{-- A printable list: compact header, one table, page numbers. Landscape when the columns need it. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $list->title }}</title>
    <style>
        @font-face { font-family: 'Faruma'; src: url('{{ public_path('fonts/A_faruma.ttf') }}') format('truetype'); }
        @page { margin: 12mm 10mm 14mm 10mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; color: {{ \App\Support\Brand::INK }}; }
        .dv { font-family: 'Faruma', 'DejaVu Sans', sans-serif; direction: rtl; }
        table.head { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
        table.head td { vertical-align: middle; padding: 0; }
        h1 { font-size: 14pt; color: {{ \App\Support\Brand::GREEN_DARK }}; margin: 0; }
        .sub { color: {{ \App\Support\Brand::MUTED }}; font-size: 8.5pt; margin-top: 1mm; }
        .issuer { text-align: right; font-size: 7.5pt; color: {{ \App\Support\Brand::MUTED }}; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { text-align: left; font-size: 7pt; text-transform: uppercase; letter-spacing: .04em; color: {{ \App\Support\Brand::MUTED }}; border-bottom: 1.2px solid {{ \App\Support\Brand::INK }}; padding: 1.2mm 1.2mm; }
        table.list td { padding: 1.4mm 1.2mm; border-bottom: 0.4px solid #d6d3d1; vertical-align: top; }
        table.list tr:nth-child(even) td { background: #f7f7f6; }
        .notes { margin-top: 3mm; font-size: 7.5pt; color: {{ \App\Support\Brand::MUTED }}; }
        .foot { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 7pt; color: {{ \App\Support\Brand::MUTED }}; }
        .foot .page:after { content: counter(page); }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 16mm;"><img src="{{ public_path('images/brand/hijaz-mark.png') }}" alt="" style="width: 13mm; height: 13mm;"></td>
            <td>
                <h1>{{ $list->title }}</h1>
                <div class="sub">{{ $list->subtitle }}</div>
            </td>
            <td class="issuer">{{ $issuer['name'] }}<br>{{ $issuer['phone'] }}<br>{{ now()->format('j M Y H:i') }}</td>
        </tr>
    </table>

    <table class="list">
        <thead>
            <tr>
                @foreach ($list->columns as $key => $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($list->rows as $row)
                <tr>
                    @foreach ($list->columns as $key => $heading)
                        <td>{{ $row[$key] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($list->columns) }}" style="color: {{ \App\Support\Brand::MUTED }};">Nobody on this list yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($list->notes !== [])
        <div class="notes">
            @foreach ($list->notes as $note)<div>{{ $note }}</div>@endforeach
        </div>
    @endif

    <div class="foot">{{ $issuer['name'] }} · {{ $list->title }} · {{ $list->subtitle }} · page <span class="page"></span></div>
</body>
</html>
