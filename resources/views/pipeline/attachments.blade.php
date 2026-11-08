{{--
    The photos of a change request in the panel: thumbnails through the
    owner's authorised route, never a file URL (docs/pipeline.md §10).
    Inline styles: the panel has no compiled theme.
--}}
<div style="display:flex;flex-wrap:wrap;gap:16px">
    @foreach ($attachments as $attachment)
        <figure style="margin:0;width:180px">
            @if ($attachment['url'] === null)
                <div style="height:120px;display:flex;align-items:center;justify-content:center;text-align:center;padding:8px;border:1px dashed rgba(127,127,127,.5);border-radius:8px;font-size:13px;opacity:.75">{{ $attachment['removed'] }}</div>
            @else
                <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener" title="Apri la foto in una nuova scheda">
                    <img src="{{ $attachment['url'] }}" alt="{{ $attachment['name'] }}" loading="lazy" style="display:block;width:180px;height:120px;object-fit:cover;border-radius:8px;border:1px solid rgba(127,127,127,.3)">
                </a>
            @endif
            <figcaption style="font-size:12px;line-height:1.4;margin-top:6px;overflow-wrap:anywhere">
                {{ $attachment['position'] }}. {{ $attachment['name'] }}<br>
                <span style="opacity:.75">{{ $attachment['details'] }}</span>
            </figcaption>
        </figure>
    @endforeach
</div>
