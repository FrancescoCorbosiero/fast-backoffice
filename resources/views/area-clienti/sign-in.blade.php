@extends('area-clienti.layout', ['title' => 'Accedi · Area clienti'])
@section('content')
    <div class="card">
        @if ($sent)
            <h1>Controlla la posta</h1>
            <p>Se l'indirizzo è tra quelli dei nostri clienti, ti abbiamo appena inviato un link per entrare. Vale per {{ config('pipeline.portal.link_minutes') }} minuti.</p>
            <p class="muted">Non lo trovi? Guarda nello spam, oppure <a href="{{ route('portal.sign-in') }}">richiedi un altro link</a>.</p>
        @else
            <h1>Entra nell'area clienti</h1>
            <p>Scrivi l'email con cui lavori con noi: ti mandiamo un link per entrare. Niente password da ricordare.</p>
            <form method="post" action="{{ route('portal.sign-in.send') }}">
                @csrf
                <div class="field">
                    <label for="email">La tua email</label>
                    <input type="email" id="email" name="email" required autocomplete="email" inputmode="email" placeholder="nome@azienda.it">
                </div>
                <button class="btn" type="submit">Inviami il link</button>
            </form>
        @endif
    </div>
@endsection
