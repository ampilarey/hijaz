{{-- Luggage tags: two per A4, large print, a cut line between. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Luggage tags</title>
    <style>
        @font-face { font-family: 'Faruma'; src: url('{{ public_path('fonts/A_faruma.ttf') }}') format('truetype'); }
        @page { margin: 10mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; color: {{ \App\Support\Brand::INK }}; }
        .tag { height: 128mm; border: 0.5px dashed #a8a29e; padding: 6mm; page-break-inside: avoid; }
        .tag + .tag { margin-top: 6mm; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 0; }
        .brand { font-size: 16pt; font-weight: bold; color: {{ \App\Support\Brand::GREEN_DARK }}; }
        .trip { font-size: 11pt; color: {{ \App\Support\Brand::MUTED }}; margin-top: 1mm; }
        .name { font-size: 26pt; font-weight: bold; margin-top: 8mm; line-height: 1.1; }
        .dv { font-family: 'Faruma', 'DejaVu Sans', sans-serif; direction: rtl; font-size: 22pt; margin-top: 2mm; }
        .bus { font-size: 20pt; font-weight: bold; color: {{ \App\Support\Brand::RED_DARK }}; margin-top: 6mm; }
        .family { font-size: 11pt; margin-top: 3mm; }
        .phones { font-size: 12pt; margin-top: 8mm; }
        .k { color: {{ \App\Support\Brand::MUTED }}; }
        .qr { width: 38mm; height: 38mm; }
        .serial { font-size: 8pt; color: {{ \App\Support\Brand::MUTED }}; text-align: center; margin-top: 1mm; }
    </style>
</head>
<body>
    @foreach ($items as $item)
        <div class="tag">
            <table>
                <tr>
                    <td style="width: 26mm;"><img src="{{ public_path('images/brand/hijaz-mark.png') }}" alt="" style="width: 22mm; height: 22mm;"></td>
                    <td>
                        <div class="brand">{{ $issuer['name'] }}</div>
                        <div class="trip">{{ $item['trip']->name }} · {{ $item['trip']->code }} · {{ $item['trip']->departs_on->format('j M Y') }}</div>
                    </td>
                    <td style="width: 42mm; text-align: center;">
                        <img class="qr" src="{{ $item['qr'] }}" alt="">
                        <div class="serial">{{ $item['card']->serial }}</div>
                    </td>
                </tr>
            </table>
            <div class="name">{{ $item['pilgrim']->name_en }}</div>
            @if ($item['pilgrim']->name_dv)<div class="dv">{{ $item['pilgrim']->name_dv }}</div>@endif
            <div class="bus">{{ $item['bp']->busGroup?->name ?? 'Bus —' }}</div>
            <div class="family"><span class="k">Family</span> {{ $item['bp']->booking->customer->name }}
                @foreach ($item['rooms'] as $room)
                    <span class="k">· {{ $room['city'] }}</span> {{ $room['room'] ?? '—' }}
                @endforeach
            </div>
            <div class="phones"><span class="k">If found, please call</span> {{ $phones }}</div>
        </div>
    @endforeach
</body>
</html>
