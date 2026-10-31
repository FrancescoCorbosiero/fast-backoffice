@php($text = trim(html_entity_decode(strip_tags(preg_replace(['/<br\s*\/?>/i', '/<\/p>/i'], ["\n", "\n\n"], $bodyHtml) ?? ''), ENT_QUOTES, 'UTF-8')))
{{ $text }}

--
Per non ricevere più queste email: {{ $unsubscribeUrl }}
