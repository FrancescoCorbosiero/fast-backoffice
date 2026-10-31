<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 22mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #222; font-size: 10pt; }
        h1 { font-size: 17pt; margin: 0 0 2pt; }
        .muted { color: #666; }
        .small { font-size: 8.5pt; }
        .right { text-align: right; }
        table { border-collapse: collapse; width: 100%; }
        table.parties td { vertical-align: top; width: 50%; padding: 0 0 14pt; }
        .label { color: #666; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.5pt; margin-bottom: 2pt; }
        table.lines th, table.lines td { padding: 5pt 4pt; border-bottom: 1px solid #ddd; vertical-align: top; }
        table.lines th { background: #f3f3f3; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.5pt; }
        table.totals { width: 55%; margin-left: 45%; margin-top: 12pt; }
        table.totals td { padding: 3pt 6pt; }
        table.totals tr.total td { font-weight: bold; font-size: 12pt; border-top: 2px solid #222; padding-top: 6pt; }
        .notes { margin-top: 18pt; }
        .footer { margin-top: 28pt; padding-top: 6pt; border-top: 1px solid #eee; color: #888; font-size: 8pt; }
    </style>
</head>
<body>
    <table class="parties">
        <tr>
            <td>
                <h1>{{ $title }}</h1>
                <div class="muted">del {{ $date->isoFormat('D MMMM YYYY') }}</div>
                @if ($subtitle !== null)
                    <div class="muted">{{ $subtitle }}</div>
                @endif
                @if ($relatedTitle !== null)
                    <div class="muted">a storno di {{ $relatedTitle }}</div>
                @endif
            </td>
            <td class="right">
                <div class="label">Emessa da</div>
                <strong>{{ $seller->name }}</strong><br>
                @if ($seller->vatNumber !== null) P. IVA {{ $seller->vatNumber->number }}<br> @endif
                @if ($seller->taxCode !== null) C.F. {{ $seller->taxCode }}<br> @endif
                {{ $seller->addressLine() }}<br>
                @if ($seller->email !== null) {{ $seller->email }}<br> @endif
                @if ($seller->pec !== null) PEC {{ $seller->pec }} @endif
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Intestata a</div>
                <strong>{{ $customer->name }}</strong><br>
                @if ($customer->vatNumber !== null) P. IVA {{ $customer->vatNumber->country }} {{ $customer->vatNumber->number }}<br> @endif
                @if ($customer->taxCode !== null) C.F. {{ $customer->taxCode }}<br> @endif
                {{ $customer->addressLine() }}<br>
                @if ($customer->sdiCode !== null) Codice destinatario {{ $customer->sdiCode }}<br> @endif
                @if ($customer->pec !== null) PEC {{ $customer->pec }} @endif
            </td>
            <td class="right">
                @if ($dueOn !== null)
                    <div class="label">{{ $dueLabel }}</div>
                    <div>{{ $dueOn->isoFormat('D MMMM YYYY') }}</div>
                @endif
                @if ($seller->iban !== null)
                    <div class="label" style="margin-top:6pt">Bonifico su IBAN</div>
                    <div>{{ $seller->iban }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Descrizione</th>
                <th class="right">Quantità</th>
                <th class="right">Prezzo</th>
                <th class="right">IVA</th>
                <th class="right">Importo</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>{!! nl2br(e($line->description)) !!}</td>
                <td class="right">{{ str_replace('.', ',', rtrim(rtrim($line->quantity->toDecimalString(), '0'), '.')) }}</td>
                <td class="right">{{ $line->unitPrice->format() }}</td>
                <td class="right">{{ $line->natura !== null ? $line->natura->value : $line->vatRate->toPercentString().'%' }}</td>
                <td class="right">{{ $line->net()->format() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Imponibile</td><td class="right">{{ $totals->subtotal->format() }}</td></tr>
        @if ($totals->hasInps())
            <tr><td>Rivalsa INPS {{ intdiv($totals->inpsBasisPoints, 100) }}%</td><td class="right">{{ $totals->inps->format() }}</td></tr>
        @endif
        @foreach ($totals->summary as $group)
            @if (! $group->vat->isZero())
                <tr><td>IVA {{ $group->vatRate->toPercentString() }}%</td><td class="right">{{ $group->vat->format() }}</td></tr>
            @endif
        @endforeach
        @if ($totals->stampDutyCharged->isPositive())
            <tr><td>Imposta di bollo</td><td class="right">{{ $totals->stampDutyCharged->format() }}</td></tr>
        @endif
        <tr class="total"><td>Totale</td><td class="right">{{ $totals->total->format() }}</td></tr>
    </table>

    @if ($legalWording !== null || $totals->stampDutyDue)
        <div class="notes small muted">
            @if ($legalWording !== null)<p>{{ $legalWording }}</p>@endif
            @if ($totals->stampDutyDue)<p>Imposta di bollo da € 2,00 assolta in modo virtuale ai sensi del D.M. 17 giugno 2014.</p>@endif
        </div>
    @endif

    @if ($notes !== null)
        <div class="notes">{!! nl2br(e($notes)) !!}</div>
    @endif

    <div class="footer">
        @if ($footer !== null)
            {{ $footer }}
        @elseif ($isFiscal)
            Copia di cortesia: il documento fiscale è la fattura elettronica trasmessa al Sistema di Interscambio, disponibile nell'area riservata dell'Agenzia delle Entrate.
        @else
            Documento non fiscale: la fattura viene emessa al ricevimento del pagamento.
        @endif
    </div>
</body>
</html>
