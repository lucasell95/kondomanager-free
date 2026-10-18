@extends('pdf.base')

@section('title', 'Verbale di assemblea')

@section('content')
@php
    $data = $assemblea->dataSvolgimento();
    $conv = $assemblea->convocazioneEffettiva();
    $tipo = mb_strtolower($assemblea->tipo?->label() ?? 'ordinaria');
    $costituita = $costitutivo['raggiunto'];
    $etichettaEsito = [
        'approvata' => 'APPROVATA',
        'respinta' => 'NON APPROVATA',
        'non_votata' => 'NON VOTATA',
        'senza_votazione' => 'PUNTO SENZA VOTAZIONE',
    ];
@endphp

@include('pdf.assemblee._intestazione')

<h1 style="text-align: center;">
    @if($costituita)
        VERBALE DELL'ASSEMBLEA {{ mb_strtoupper($tipo) }} DEI CONDÒMINI
    @else
        VERBALE DI MANCATA COSTITUZIONE DELL'ASSEMBLEA
    @endif
    @if($assemblea->numeroRegistro())
        <br><span style="font-size: 10pt;">Registro dei verbali n. {{ $assemblea->numeroRegistro() }}</span>
    @endif
</h1>

<p>
    Il giorno {{ $data?->copy()->locale('it')->translatedFormat('l j F Y') }}, alle ore {{ $assemblea->ora_apertura ?: $data?->format('H:i') }},
    @if($assemblea->luogoSvolgimento()) presso {{ $assemblea->luogoSvolgimento() }}, @endif
    @if($assemblea->videoconferenza) e in videoconferenza sulla piattaforma {{ $assemblea->piattaforma_video ?: '—' }}, @endif
    si è riunita in <strong>{{ mb_strtolower($conv->label()) }}</strong> l'assemblea {{ $tipo }} dei condòmini del {{ $condominio->nome }},
    convocata con avviso recante il seguente ordine del giorno:
</p>
<ol>
    @foreach($punti as $voce)
        <li>{{ $voce['punto']->titolo }}</li>
    @endforeach
</ol>

@if($conv->value === 'seconda' && $assemblea->prima_deserta)
    <p>La prima convocazione, fissata per il {{ $assemblea->prima_convocazione_at?->format('d/m/Y') }} alle ore {{ $assemblea->prima_convocazione_at?->format('H:i') }}, è andata deserta per mancanza del numero legale.</p>
@endif

<div class="section">
    <div class="section-title">Regolarità della convocazione</div>
    @if(count($destinatari) === 0)
        <p>Non sono registrati i destinatari dell'avviso.</p>
    @else
        <table class="table-report" style="width: 100%;">
            <thead><tr><th>Destinatario</th><th>Mezzo</th><th>Ricevuto il</th><th>Esito</th></tr></thead>
            <tbody>
            @foreach($destinatari as $d)
                <tr>
                    <td>{{ $d['nominativo'] }}</td>
                    <td>{{ \App\Enums\Assemblee\MezzoConvocazione::tryFrom((string) $d['mezzo'])?->label() }}</td>
                    <td>{{ $d['data_ricezione'] ? \Illuminate\Support\Carbon::parse($d['data_ricezione'])->format('d/m/Y') : '—' }}</td>
                    <td>{{ $d['stato']['messaggio'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="section">
    <div class="section-title">Presenti</div>
    <table class="table-report" style="width: 100%;">
        <thead><tr><th>Condòmino (unità)</th><th style="text-align: right;">Millesimi</th><th>Presenza</th></tr></thead>
        <tbody>
        @foreach($costitutivo['teste'] as $t)
            <tr>
                <td>{{ $t['etichetta'] }}</td>
                <td style="text-align: right;">{{ $t['valore_testo'] }}</td>
                <td>{{ $t['presente'] ? ($t['rappresentante'] ?? 'in proprio') : 'assente' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p>{!! nl2br(e($costitutivo['spiegazione'])) !!}</p>
</div>

@if($costituita)
    <p>
        Assume la presidenza {{ $assemblea->presidente ?: '____________________' }}
        e funge da segretario {{ $assemblea->segretario ?: '____________________' }}.
        Il presidente, constatata la regolare costituzione dell'assemblea, apre la discussione sui punti all'ordine del giorno.
    </p>

    @foreach($punti as $i => $voce)
        @php
            $p = $voce['punto'];
            $e = $voce['esito'];
        @endphp
        <div class="section" style="page-break-inside: avoid;">
            <div class="section-title">Punto {{ $i + 1 }} — {{ $p->titolo }}</div>

            @if($p->discussione)
                <p>{!! nl2br(e($p->discussione)) !!}</p>
            @endif

            @if($p->testo_delibera)
                <p><em>{!! nl2br(e($p->testo_delibera)) !!}</em></p>
            @endif

            @if($e['esito'] !== 'senza_votazione')
                <table class="grid">
                    <tr><td class="label">Maggioranza richiesta:</td><td class="value">{{ $e['regola'] }} ({{ $e['norma'] }})</td></tr>
                    <tr><td class="label">Intervenuti al voto:</td><td class="value">{{ $e['intervenuti']['teste'] }} condòmini, {{ $e['intervenuti']['valore_testo'] }} millesimi</td></tr>
                    @foreach(['favorevoli' => 'Favorevoli', 'contrari' => 'Contrari', 'astenuti' => 'Astenuti', 'non_espressi' => 'Senza voto espresso'] as $chiave => $nome)
                        @if($e[$chiave]['teste'] > 0 || $chiave !== 'non_espressi')
                        <tr>
                            <td class="label">{{ $nome }}:</td>
                            <td class="value">
                                {{ $e[$chiave]['teste'] }} condòmini, {{ $e[$chiave]['valore_testo'] }} millesimi
                                @if($e[$chiave]['teste'] > 0)
                                    <br><span style="color: #555;">
                                    @foreach($e[$chiave]['nomi'] as $n){{ $n['etichetta'] }} – {{ $n['valore_testo'] }}‰@if(! $loop->last); @endif @endforeach
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @endif
                    @endforeach
                </table>
                <p><strong>Esito: {{ $etichettaEsito[$e['esito']] ?? $e['esito'] }}.</strong></p>
                <p style="font-size: 8pt; color: #444;">{!! nl2br(e($e['spiegazione'])) !!}</p>
            @else
                <p><strong>Punto informativo: nessuna votazione.</strong></p>
            @endif

            @foreach($voce['dichiarazioni'] as $dich)
                <p style="margin-left: 12px;">Dichiarazione a verbale di {{ $dich->dichiarante }}: «{{ $dich->testo }}»</p>
            @endforeach
        </div>
    @endforeach

    @if($dichiarazioni_generali->isNotEmpty())
        <div class="section">
            <div class="section-title">Dichiarazioni a verbale</div>
            @foreach($dichiarazioni_generali as $dich)
                <p>{{ $dich->dichiarante }}: «{{ $dich->testo }}»</p>
            @endforeach
        </div>
    @endif

    <p>
        Esauriti i punti all'ordine del giorno, l'assemblea è sciolta alle ore {{ $assemblea->ora_chiusura ?: '______' }}.
        Il presente verbale è letto, approvato e sottoscritto.
    </p>
@else
    <p>
        Non essendo raggiunto il quorum costitutivo richiesto dall'art. 1136 c.c., l'assemblea non è validamente costituita
        e non può deliberare. Della mancata costituzione si dà atto nel registro dei verbali (art. 1130 n. 7 c.c.).
    </p>
@endif

<p style="font-size: 8pt; color: #444; margin-top: 10px;">
    Le delibere possono essere impugnate entro trenta giorni (art. 1137 c.c.): per i dissenzienti e gli astenuti dalla data della delibera
    @if($scadenzaPresenti) (termine indicativo: {{ \Illuminate\Support\Carbon::parse($scadenzaPresenti)->format('d/m/Y') }})@endif,
    per gli assenti dalla comunicazione del presente verbale.
</p>

<table style="width: 100%; margin-top: 30px;">
    <tr>
        <td style="width: 50%; text-align: center;">Il Presidente<br><br>______________________________<br>{{ $assemblea->presidente }}</td>
        <td style="width: 50%; text-align: center;">Il Segretario<br><br>______________________________<br>{{ $assemblea->segretario }}</td>
    </tr>
</table>
@endsection
