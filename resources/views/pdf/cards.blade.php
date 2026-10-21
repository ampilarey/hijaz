{{-- Customer cards: eight per A4, credit-card sized, cut along the lines. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Customer cards</title>
    <style>
        @font-face { font-family: 'Faruma'; src: url('{{ public_path('fonts/A_faruma.ttf') }}') format('truetype'); }
        @page { margin: 10mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; color: {{ \App\Support\Brand::INK }}; font-size: 7.5pt; }
        table.sheet { border-collapse: collapse; width: 100%; }
        table.sheet td.cell { width: 50%; padding: 2mm; border: 0.3px dashed #a8a29e; }
        table.card { width: 100%; border-collapse: collapse; }
        table.card td { vertical-align: top; padding: 0; height: 50mm; }
        .photo { width: 22mm; height: 28mm; object-fit: cover; border: 0.4px solid #d6d3d1; }
        .nophoto { width: 22mm; height: 28mm; border: 0.4px dashed #d6d3d1; color: #a8a29e; font-size: 6pt; text-align: center; padding-top: 11mm; }
        .brand { font-size: 8.5pt; font-weight: bold; color: {{ \App\Support\Brand::GREEN_DARK }}; }
        .trip { font-size: 7pt; color: {{ \App\Support\Brand::MUTED }}; }
        .name { font-size: 10pt; font-weight: bold; margin-top: 1.5mm; }
        .dv { font-family: 'Faruma', 'DejaVu Sans', sans-serif; direction: rtl; font-size: 10pt; }
        .line { margin-top: 0.8mm; }
        .k { color: {{ \App\Support\Brand::MUTED }}; }
        .qr { width: 20mm; height: 20mm; }
        .serial { font-size: 6pt; color: {{ \App\Support\Brand::MUTED }}; text-align: center; }
        .phones { font-size: 6.5pt; color: {{ \App\Support\Brand::RED_DARK }}; margin-top: 1.5mm; }
    </style>
</head>
<body>
    <table class="sheet">
        @foreach (array_chunk($items, 2) as $pair)
            <tr>
                @foreach ($pair as $item)
                    <td class="cell">
                        <table class="card">
                            <tr>
                                <td style="width: 24mm;">
                                    @if ($item['photo'])
                                        <img class="photo" src="{{ $item['photo'] }}" alt="">
                                    @else
                                        <div class="nophoto">no photo</div>
                                    @endif
                                </td>
                                <td>
                                    <table style="width:100%; border-collapse: collapse;">
                                        <tr>
                                            <td style="vertical-align: top;">
                                                <div class="brand">{{ $issuer['name'] }}</div>
                                                <div class="trip">{{ $item['trip']->name }} · {{ $item['trip']->code }}</div>
                                            </td>
                                            <td style="width: 12mm; text-align: right; vertical-align: top;"><img src="{{ public_path('images/brand/hijaz-mark.png') }}" alt="" style="width: 10mm; height: 10mm;"></td>
                                        </tr>
                                    </table>
                                    <div class="name">{{ $item['pilgrim']->name_en }}</div>
                                    @if ($item['pilgrim']->name_dv)<div class="dv">{{ $item['pilgrim']->name_dv }}</div>@endif
                                    <div class="line"><span class="k">ID</span> {{ $item['pilgrim']->id_number ?? '—' }} <span class="k">· Bus</span> {{ $item['bp']->busGroup?->name ?? '—' }}</div>
                                    <div class="line">
                                        @foreach ($item['rooms'] as $room)
                                            <span class="k">{{ $room['city'] }}</span> {{ $room['room'] ?? '—' }}@if (! $loop->last) · @endif
                                        @endforeach
                                    </div>
                                    <div class="line"><span class="k">Family</span> {{ $item['bp']->booking->customer->name }}</div>
                                    <div class="phones">{{ $issuer['name'] }} · {{ $phones }}</div>
                                </td>
                                <td style="width: 21mm; text-align: center; vertical-align: top; padding-top: 12mm;">
                                    <img class="qr" src="{{ $item['qr'] }}" alt="">
                                    <div class="serial">{{ $item['card']->serial }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                @endforeach
                @if (count($pair) === 1)<td class="cell"></td>@endif
            </tr>
        @endforeach
    </table>
</body>
</html>
