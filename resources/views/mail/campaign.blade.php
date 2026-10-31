<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? '' }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Helvetica,Arial,sans-serif;color:#18181b;">
    @if ($preheader !== null)
        <div style="display:none;max-height:0;overflow:hidden;">{{ $preheader }}</div>
    @endif
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f5;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;padding:32px;">
                    <tr>
                        <td style="font-size:16px;line-height:1.5;">
                            {!! $bodyHtml !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-top:32px;font-size:12px;line-height:1.5;color:#71717a;border-top:1px solid #e4e4e7;">
                            Ricevi questa email perché hai dato il consenso a ricevere le nostre comunicazioni.
                            <a href="{{ $unsubscribeUrl }}" style="color:#71717a;">Non ricevere più queste email</a>.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
