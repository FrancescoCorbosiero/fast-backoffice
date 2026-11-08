@extends('area-clienti.layout', ['title' => 'Richiesta · Area clienti'])
@section('content')
    <div class="card">
        <div class="row">
            <h1>{{ $request->isOnboarding() ? 'Collegamento del sito' : $request->websiteName }}</h1>
            <span class="badge {{ $request->status->color() }}">{{ $request->status->label() }}</span>
        </div>
        <p>{{ $request->isOnboarding() ? 'Stiamo collegando il sito alla pipeline delle modifiche: quando è pronto, potrai chiedere cambiamenti da qui.' : $request->status->clientHint() }}</p>
        <p class="muted">Richiesta #{{ $request->id }} · {{ $request->createdAt->isoFormat('D MMMM YYYY, HH:mm') }}</p>
    </div>

    @unless ($request->isOnboarding())
        <div class="card">
            <h2>Cosa hai chiesto</h2>
            <p class="request-text">{{ $request->requestText }}</p>
            @if ($request->attachments !== [])
                <details class="photos">
                    <summary>{{ count($request->attachments) === 1 ? 'La foto che hai allegato' : 'Le '.count($request->attachments).' foto che hai allegato' }}</summary>
                    <ul>
                        @foreach ($request->attachments as $attachment)
                            <li>
                                @if ($attachment->isRemoved())
                                    <span class="photo-gone">Foto rimossa</span>
                                @else
                                    <a href="{{ route('portal.requests.attachment', ['id' => $request->id, 'attachment' => $attachment->id]) }}" target="_blank" rel="noopener">
                                        <img src="{{ route('portal.requests.attachment', ['id' => $request->id, 'attachment' => $attachment->id]) }}" alt="{{ $attachment->originalName }}" loading="lazy" width="{{ $attachment->width }}" height="{{ $attachment->height }}">
                                    </a>
                                @endif
                                <span class="muted">{{ $attachment->originalName }} · {{ $attachment->width }}×{{ $attachment->height }} · {{ $attachment->sizeLabel() }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="muted">Le foto restano qui fino a {{ $keepDays }} giorni dopo la chiusura della richiesta, poi le cancelliamo.</p>
                </details>
            @endif
            @foreach ($request->conversation as $turn)
                @continue($turn === $request->pendingQuestion())
                <div class="turn">
                    <p class="muted">{{ $turn->isAgent() ? 'Ti abbiamo chiesto' : 'Hai risposto' }} · {{ $turn->at->isoFormat('D MMM YYYY, HH:mm') }}</p>
                    <p class="request-text">{{ $turn->text }}</p>
                </div>
            @endforeach
        </div>
    @endunless

    @if (($question = $request->pendingQuestion()) !== null)
        <div class="card">
            <h2>Ci serve una tua risposta</h2>
            <p class="request-text">{{ $question->text }}</p>
            <form method="post" action="{{ route('portal.requests.answer', ['id' => $request->id]) }}" style="margin-top:16px">
                @csrf
                @foreach ($question->questions as $index => $asked)
                    <fieldset class="question">
                        <legend>{{ $asked->question }}</legend>
                        @foreach ($asked->options as $option)
                            <label class="choice"><input type="radio" name="choices[{{ $index }}]" value="{{ $option }}" @checked(old('choices.'.$index) === $option)> <span>{{ $option }}</span></label>
                        @endforeach
                    </fieldset>
                @endforeach
                <div class="field">
                    <label for="note">{{ $question->questions === [] ? 'La tua risposta' : 'Altro da aggiungere' }} @if ($question->questions !== [])<span class="muted">(facoltativo, anche al posto delle scelte)</span>@endif</label>
                    <textarea id="note" name="note" maxlength="{{ $maxAnswer }}" style="min-height:100px">{{ old('note') }}</textarea>
                    @if ($errors_?->has('answer'))<div class="error">{{ $errors_->first('answer') }}</div>@endif
                </div>
                <button class="btn" type="submit">Invia la risposta</button>
            </form>
            @if ($request->availableAttachments() !== [])
                <p class="muted" style="margin-top:12px">Le foto che hai allegato restano con la richiesta: non serve allegarle di nuovo.</p>
            @endif
        </div>
    @endif

    @if ($request->status === \App\Modules\Pipeline\Enums\ChangeRequestStatus::Failed && $request->clientError !== null && ! $request->isOnboarding())
        <div class="card">
            <h2>Cosa è successo</h2>
            <p class="request-text">{{ $request->clientError }}</p>
            <p class="muted">Lo studio ha visto questa richiesta e la riguarderà. Se vuoi, fanne una nuova spiegando meglio cosa ti serve.</p>
        </div>
    @endif

    @if ($request->awaitsDecision() && ! $request->isOnboarding() && $request->previewUrl !== null)
        <div class="card">
            <h2>L'anteprima</h2>
            <p class="muted">Guarda come viene il sito con la modifica. Se va bene, approva e la pubblichiamo; se no, rifiuta e dicci cosa non torna.</p>
            <a class="btn secondary" href="{{ $request->previewUrl }}" target="_blank" rel="noopener">Apri l'anteprima a schermo intero</a>
            <iframe class="preview" src="{{ $request->previewUrl }}" title="Anteprima" loading="lazy" style="margin-top:12px"></iframe>
            @if ($request->touchedPaths !== [])
                <p class="muted" style="margin-top:12px">File cambiati:</p>
                <ul class="paths">
                    @foreach ($request->touchedPaths as $path)<li>{{ $path }}</li>@endforeach
                </ul>
            @endif
        </div>
        <div class="card actions">
            <form method="post" action="{{ route('portal.requests.approve', ['id' => $request->id]) }}">
                @csrf
                <button class="btn" type="submit">Approva e pubblica</button>
            </form>
            <form method="post" action="{{ route('portal.requests.reject', ['id' => $request->id]) }}">
                @csrf
                <div class="field" style="margin-top:12px">
                    <label for="reason">Cosa non va? <span class="muted">(facoltativo)</span></label>
                    <textarea id="reason" name="reason" maxlength="500" style="min-height:80px"></textarea>
                </div>
                <button class="btn danger" type="submit">Rifiuta</button>
            </form>
        </div>
    @endif

    @if ($request->decidedBy !== null)
        <p class="muted">{{ $request->status === \App\Modules\Pipeline\Enums\ChangeRequestStatus::Rejected ? 'Rifiutata' : 'Approvata' }} {{ $request->decidedBy === \App\Modules\Pipeline\Enums\DecidedBy::Client ? 'da te' : 'dallo studio' }}{{ $request->decidedAt !== null ? ' il '.$request->decidedAt->isoFormat('D MMM YYYY, HH:mm') : '' }}{{ $request->decisionReason !== null ? ': '.$request->decisionReason : '' }}</p>
    @endif

    <a class="btn secondary" href="{{ route('portal.requests') }}">Tutte le richieste</a>
@endsection
