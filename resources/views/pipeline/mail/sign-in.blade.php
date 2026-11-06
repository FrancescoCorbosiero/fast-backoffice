<!DOCTYPE html>
<html lang="it">
<head><meta charset="utf-8"><title>Il tuo link per l'area clienti</title></head>
<body style="font-family: Helvetica, Arial, sans-serif; color: #18181b; line-height: 1.5;">
    <p>Ciao {{ $name }},</p>
    <p>ecco il link per entrare nell'area clienti. Vale per {{ $minutes }} minuti e si usa una volta sola.</p>
    <p><a href="{{ $link }}" style="display: inline-block; background: #18181b; color: #fff; padding: 12px 20px; border-radius: 8px; text-decoration: none;">Entra nell'area clienti</a></p>
    <p style="color: #71717a; font-size: 13px;">Se non hai chiesto tu questo link, ignora questa email: non succede niente.</p>
</body>
</html>
