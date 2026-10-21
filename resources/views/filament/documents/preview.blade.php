<div style="display:grid; gap:.75rem;">
    @if ($isPdf)
        <iframe src="{{ $url }}" title="Scan" style="width:100%; height:55vh; border:1px solid var(--gray-200,#e7e5e4); border-radius:.5rem; background:#fff;"></iframe>
    @else
        <a href="{{ $url }}" target="_blank" rel="noopener"><img src="{{ $url }}" alt="Scan" style="width:100%; max-height:55vh; object-fit:contain; border:1px solid var(--gray-200,#e7e5e4); border-radius:.5rem; background:#fff;"></a>
    @endif
    @foreach ($notes as $note)
        <p style="font-size:.875rem; {{ str_contains($note, 'FAILED') ? 'color:var(--danger-600,#b91c1c); font-weight:600;' : '' }}">{{ $note }}</p>
    @endforeach
    @if ($reader)
        <p class="fi-color-gray" style="font-size:.75rem;">Read by {{ $reader }}. Every field is a proposal until you accept it.</p>
    @endif
</div>
