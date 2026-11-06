@extends('area-clienti.layout', ['title' => 'Accedi · Area clienti'])
@section('content')
    <div class="card">
        @if ($valid)
            <h1>Ci sei quasi</h1>
            <p>Premi il pulsante per entrare nell'area clienti.</p>
            <form method="post" action="{{ $action }}">
                @csrf
                <button class="btn" type="submit">Entra</button>
            </form>
        @else
            <h1>Questo link non vale più</h1>
            <p>I link durano pochi minuti e si usano una volta sola. Richiedine uno nuovo: ci vuole un attimo.</p>
            <a class="btn" href="{{ route('portal.sign-in') }}">Richiedi un nuovo link</a>
        @endif
    </div>
@endsection
