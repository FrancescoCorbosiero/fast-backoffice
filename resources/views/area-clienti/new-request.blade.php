@extends('area-clienti.layout', ['title' => 'Nuova richiesta · Area clienti'])
@section('content')
    <div class="card">
        <h1>Cosa vuoi cambiare?</h1>
        @if ($websites === [])
            <p>Nessuno dei tuoi siti è ancora collegato alla pipeline delle modifiche. Scrivici e lo colleghiamo.</p>
            <a class="btn secondary" href="{{ route('portal.requests') }}">Torna alle richieste</a>
        @else
            <p class="muted">Scrivi con parole tue, come lo diresti a voce: cosa va aggiunto, tolto o cambiato, e dove. Ti mostriamo un'anteprima prima di pubblicare.</p>
            <form method="post" action="{{ route('portal.requests.store') }}">
                @csrf
                <div class="field">
                    <label for="website_id">Il sito</label>
                    <select id="website_id" name="website_id" required>
                        @foreach ($websites as $website)
                            <option value="{{ $website->id }}" @selected((string) $old['website_id'] === (string) $website->id || (count($websites) === 1))>{{ $website->name }} · {{ $website->host }}</option>
                        @endforeach
                    </select>
                    @if ($errors_?->has('website_id'))<div class="error">{{ $errors_->first('website_id') }}</div>@endif
                </div>
                <div class="field">
                    <label for="request_text">La modifica</label>
                    <textarea id="request_text" name="request_text" required maxlength="4000" placeholder="Esempio: il sabato chiudiamo alle 13, aggiorna gli orari in fondo alla pagina.">{{ $old['request_text'] }}</textarea>
                    @if ($errors_?->has('request_text'))<div class="error">{{ $errors_->first('request_text') }}</div>@endif
                </div>
                <button class="btn" type="submit">Invia la richiesta</button>
            </form>
        @endif
    </div>
@endsection
