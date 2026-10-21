<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit="run">
            {{ $this->form }}
        </form>
    </x-filament::section>

    @if ($problem)
        <x-filament::section><p class="fi-color-gray">{{ $problem }}</p></x-filament::section>
    @elseif ($report)
        <x-filament::section>
            <x-slot name="heading">{{ $report->title }}</x-slot>
            <x-slot name="description">{{ $report->subtitle }}</x-slot>
            <x-slot name="headerEnd">
                <div style="display:flex; gap:.5rem;">
                    <x-filament::button tag="a" href="{{ $this->url('pdf') }}" target="_blank" rel="noopener" size="sm">PDF</x-filament::button>
                    <x-filament::button tag="a" href="{{ $this->url('csv') }}" color="gray" size="sm">CSV</x-filament::button>
                </div>
            </x-slot>
            @if ($report->figures !== [])
                <dl style="display:grid; grid-template-columns:repeat(auto-fill, minmax(14rem, 1fr)); gap:.75rem; margin-bottom:1rem;">
                    @foreach ($report->figures as $f)
                        <div><dt class="fi-color-gray" style="font-size:.75rem;">{{ $f['label'] }}</dt><dd style="font-weight:600;">{{ $f['value'] }}</dd></div>
                    @endforeach
                </dl>
            @endif
            @foreach ($report->sections as $section)
                <h3 style="font-weight:600; margin:1rem 0 .25rem;">{{ $section->title }} <span class="fi-color-gray" style="font-weight:400; font-size:.875rem;">{{ $section->subtitle }}</span></h3>
                @if (count($section->columns) > 3)<p class="hz-swipe-hint">Swipe sideways for the other columns; the first stays put.</p>@endif
                <div class="hz-report-table" style="overflow-x:auto;">
                    <table class="fi-ta-table" style="width:100%; font-size:.875rem; border-collapse:collapse;">
                        <thead><tr>@foreach ($section->columns as $heading)<th style="text-align:left; padding:.375rem .5rem; border-bottom:1px solid var(--gray-300,#d6d3d1); white-space:nowrap;">{{ $heading }}</th>@endforeach</tr></thead>
                        <tbody>
                        @forelse ($section->rows as $row)
                            <tr>@foreach ($section->columns as $key => $heading)<td style="padding:.375rem .5rem; border-bottom:1px solid var(--gray-200,#e7e5e4); vertical-align:top;">{{ $row[$key] ?? '' }}</td>@endforeach</tr>
                        @empty
                            <tr><td colspan="{{ count($section->columns) }}" class="fi-color-gray" style="padding:.5rem;">Nothing to show.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            @endforeach
            @foreach ($report->notes as $note)
                <p class="fi-color-gray" style="font-size:.8rem; margin-top:.75rem;">{{ $note }}</p>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
