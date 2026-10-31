<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $done ? 'Fatto' : 'Non ricevere più queste email' }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; background: #f4f4f5; color: #18181b; margin: 0; padding: 48px 16px; }
        main { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; }
        button { background: #18181b; color: #fff; border: 0; border-radius: 6px; padding: 12px 20px; font-size: 16px; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        @if ($done)
            <h1>Fatto</h1>
            <p>{{ $email }} non riceverà più le nostre email.</p>
        @else
            <h1>Non ricevere più queste email</h1>
            <p>Confermi di voler togliere {{ $email }} dalle nostre comunicazioni?</p>
            <form method="post" action="{{ $action }}">
                <input type="hidden" name="List-Unsubscribe" value="One-Click">
                <button type="submit">Sì, non inviatemi più email</button>
            </form>
        @endif
    </main>
</body>
</html>
