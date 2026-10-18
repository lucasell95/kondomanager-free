@extends('pdf.base')

@section('title', 'Avviso di convocazione')

@section('content')
@php
    $riceventi = $destinatari->isNotEmpty() ? $destinatari : collect([null]);
    $dataLunga = fn ($d) => $d ? $d->copy()->locale('it')->translatedFormat('l j F Y') : '';
    $ora = fn ($d) => $d ? $d->format('H:i') : '';
    $tipo = mb_strtoupper($assemblea->tipo?->label() ?? 'Ordinaria');
    $convocante = $assemblea->convocante ?: ($assemblea->amministratore_nome ?: "L'amministratore");
@endphp

@foreach($riceventi as $destinatario)
    @if(! $loop->first)
        <pagebreak />
    @endif

    @include('pdf.assemblee._intestazione')

    <table style="width: 100%; margin-bottom: 14px;">
        <tr>
            <td style="width: 50%;"></td>
            <td style="width: 50%;">
                @if($destinatario)
                    Spett.le <strong>{{ $destinatario->nominativo }}</strong><br>
                    @if($destinatario->recapito){{ $destinatario->recapito }}<br>@endif
                    <span style="color: #555;">Mezzo: {{ $destinatario->mezzo?->label() }}</span>
                @else
                    Ai signori condòmini
                @endif
            </td>
        </tr>
    </table>

    <h1 style="text-align: center;">AVVISO DI CONVOCAZIONE<br>DELL'ASSEMBLEA {{ $tipo }} DEI CONDÒMINI</h1>

    <p>
        {{ $convocante }} convoca l'assemblea {{ mb_strtolower($assemblea->tipo?->label() ?? 'ordinaria') }}
        dei condòmini del {{ $condominio->nome }}:
    </p>

    <table class="grid" style="margin: 8px 0 12px 0;">
        <tr>
            <td class="label">In prima convocazione:</td>
            <td class="value"><strong>{{ $dataLunga($assemblea->prima_convocazione_at) }}, ore {{ $ora($assemblea->prima_convocazione_at) }}</strong>
                @if($assemblea->prima_luogo)<br>presso {{ $assemblea->prima_luogo }}@endif
            </td>
        </tr>
        @if($assemblea->seconda_convocazione_at)
        <tr>
            <td class="label">In seconda convocazione:</td>
            <td class="value"><strong>{{ $dataLunga($assemblea->seconda_convocazione_at) }}, ore {{ $ora($assemblea->seconda_convocazione_at) }}</strong>
                @if($assemblea->seconda_luogo ?: $assemblea->prima_luogo)<br>presso {{ $assemblea->seconda_luogo ?: $assemblea->prima_luogo }}@endif
            </td>
        </tr>
        @endif
        @if($assemblea->videoconferenza)
        <tr>
            <td class="label">In videoconferenza:</td>
            <td class="value">piattaforma {{ $assemblea->piattaforma_video ?: '—' }}
                @if($assemblea->link_video)<br>collegamento: {{ $assemblea->link_video }}@endif
            </td>
        </tr>
        @endif
    </table>

    <p>per discutere e deliberare sul seguente</p>

    <div class="section">
        <div class="section-title">ORDINE DEL GIORNO</div>
        <ol>
            @foreach($assemblea->punti as $punto)
                <li style="margin-bottom: 6px;">
                    <strong>{{ $punto->titolo }}</strong>
                    @if($punto->testo)<br>{!! nl2br(e($punto->testo)) !!}@endif
                </li>
            @endforeach
        </ol>
    </div>

    @if(count($assemblea->elencoAllegati()) > 0)
        <div class="section">
            <div class="section-title">Allegati</div>
            <ul>
                @foreach($assemblea->elencoAllegati() as $allegato)
                    <li>{{ $allegato }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="section" style="font-size: 8pt; color: #444;">
        <div class="section-title">Avvertenze</div>
        <ul>
            <li>Il condòmino può farsi rappresentare da un delegato con delega scritta (art. 67 c. 1 disp. att. c.c.). Il modulo di delega è in fondo a questo avviso.</li>
            <li>All'amministratore non possono essere conferite deleghe (art. 67 c. 5 disp. att. c.c.).</li>
            <li>Se i condòmini sono più di venti, il delegato non può rappresentare più di un quinto dei condòmini e del valore dell'edificio.</li>
            <li>I documenti relativi ai punti all'ordine del giorno possono essere consultati prima dell'assemblea rivolgendosi a chi convoca.</li>
            @if($assemblea->seconda_convocazione_at)
                <li>Se la prima convocazione non raggiunge il numero legale, l'assemblea si terrà in seconda convocazione nel giorno e nell'ora indicati.</li>
            @endif
        </ul>
    </div>

    <p style="margin-top: 18px;">
        {{ now()->format('d/m/Y') }}
        <span style="float: right;">{{ $convocante }}</span>
    </p>
@endforeach

<pagebreak />

@include('pdf.assemblee._intestazione')

<h1 style="text-align: center;">DELEGA</h1>

<p>Il/La sottoscritto/a ______________________________________________,
condòmino dell'unità ____________________ del {{ $condominio->nome }},</p>

<p style="text-align: center; font-weight: bold;">DELEGA</p>

<p>il/la sig./sig.ra ______________________________________________
a rappresentarlo/a all'assemblea {{ mb_strtolower($assemblea->tipo?->label() ?? 'ordinaria') }} convocata
per il {{ $dataLunga($assemblea->prima_convocazione_at) }} in prima convocazione
@if($assemblea->seconda_convocazione_at)
    e per il {{ $dataLunga($assemblea->seconda_convocazione_at) }} in seconda convocazione,
@endif
con facoltà di intervenire e votare su tutti i punti all'ordine del giorno.</p>

<p style="font-size: 8pt; color: #444;">La delega non può essere conferita all'amministratore (art. 67 c. 5 disp. att. c.c.).
Ogni limite o condizione apposto alla delega si considera non apposto (art. 67 c. 4 disp. att. c.c.).</p>

<table style="width: 100%; margin-top: 30px;">
    <tr>
        <td style="width: 50%;">Data ____________________</td>
        <td style="width: 50%; text-align: right;">Firma ______________________________</td>
    </tr>
</table>
@endsection
