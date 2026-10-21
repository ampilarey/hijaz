<div style="display:grid; gap:.5rem;">
    <div style="display:flex; flex-wrap:wrap; gap:.5rem;">
        @foreach ($items as $item)
            <span class="fi-badge" style="display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .6rem; border-radius:.5rem; font-size:.8rem; font-weight:600;
                background: {{ match($item['status']) { 'verified' => 'var(--success-50,#ecfdf5)', 'pending' => 'var(--warning-50,#fffbeb)', 'rejected' => 'var(--danger-50,#fef2f2)', default => 'var(--gray-100,#f5f5f4)' } }};
                color: {{ match($item['status']) { 'verified' => 'var(--success-700,#047857)', 'pending' => 'var(--warning-700,#b45309)', 'rejected' => 'var(--danger-700,#b91c1c)', default => 'var(--gray-600,#57534e)' } }};">
                {{ $item['label'] }}: {{ $item['text'] }}
            </span>
        @endforeach
    </div>
    @if ($mismatches !== [])
        <div style="padding:.6rem .75rem; border-radius:.5rem; background:var(--danger-50,#fef2f2); color:var(--danger-700,#b91c1c); font-size:.875rem;">
            <strong>The ID card and passport disagree:</strong>
            <ul style="margin:.25rem 0 0; padding-left:1rem;">
                @foreach ($mismatches as $m)
                    <li>{{ ucfirst(str_replace('_', ' ', $m['field'])) }}: ID card says <em>{{ $m['id_card'] }}</em>, passport says <em>{{ $m['passport'] }}</em>.</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if ($passport)
        <p class="fi-color-gray" style="font-size:.8rem;">{{ $passport }}</p>
    @endif
</div>
