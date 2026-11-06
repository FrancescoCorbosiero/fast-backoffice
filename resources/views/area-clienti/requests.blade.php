@extends('area-clienti.layout', ['title' => 'Le mie richieste · Area clienti'])
@section('content')
    <div class="card">
        <div class="row">
            <h1>Le tue richieste</h1>
            <span class="muted">{{ $client->name }}</span>
        </div>
        @if ($requests === [])
            <p>Non hai ancora chiesto modifiche. Quando vuoi cambiare qualcosa sul tuo sito, scrivicelo qui: ci pensiamo noi e ti mostriamo un'anteprima prima di pubblicare.</p>
        @else
            <ul class="list">
                @foreach ($requests as $request)
                    <li>
                        <a href="{{ route('portal.requests.show', ['id' => $request->id]) }}">
                            <div class="row">
                                <strong>{{ $request->isOnboarding() ? 'Collegamento del sito' : $request->websiteName }}</strong>
                                <span class="badge {{ $request->status->color() }}">{{ $request->status->label() }}</span>
                            </div>
                            <div class="muted">{{ $request->isOnboarding() ? $request->websiteName : \Illuminate\Support\Str::limit($request->requestText, 90) }} · {{ $request->createdAt->isoFormat('D MMM YYYY') }}</div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @if ($hasReadyWebsites)
        <a class="btn" href="{{ route('portal.requests.create') }}">Chiedi una modifica</a>
    @else
        <p class="muted">Nessuno dei tuoi siti è ancora collegato alla pipeline delle modifiche: scrivici e lo sistemiamo.</p>
    @endif
@endsection
