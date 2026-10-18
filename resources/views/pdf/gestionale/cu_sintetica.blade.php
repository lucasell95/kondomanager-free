@extends('pdf.base')

@section('title', 'Certificazione Unica ' . ($anno + 1))

@php
    $e = fn ($c) => number_format(((int) $c) / 100, 2, ',', '.');
    $d = fn ($s) => $s ? \Carbon\Carbon::parse($s)->format('d/m/Y') : '';
    $lab = 'font-size: 6.5pt; color: #555; text-transform: uppercase;';
    $val = 'font-size: 9pt; font-weight: bold; color: #111; min-height: 12px;';
    $cell = 'border: 1px solid #9fb3c8; padding: 3px 5px; vertical-align: top;';
    $sost = $sostituto;
    $firm = $sost['firmatario'];
@endphp

@section('content')

<div style="border: 2px solid #1e3a5f; padding: 6px 8px; margin-bottom: 8px;">
    <table style="width: 100%;">
        <tr>
            <td style="width: 70%;">
                <div style="font-size: 14pt; font-weight: bold; color: #1e3a5f;">CERTIFICAZIONE UNICA {{ $anno + 1 }}</div>
                <div style="font-size: 9pt; color: #1e3a5f;">Certificazione lavoro autonomo, provvigioni e redditi diversi — periodo d'imposta {{ $anno }}</div>
                <div style="font-size: 8pt; color: #444;">Copia per il percipiente (modello sintetico)</div>
            </td>
            <td style="text-align: right; font-size: 7pt; color: #7a4a00;">
                Prospetto prodotto dal programma<br>di gestione condominiale
            </td>
        </tr>
    </table>
</div>

<div style="font-size: 7pt; color: #7a4a00; background-color: #fff7e6; border: 1px solid #f0d9a8; padding: 4px 6px; margin-bottom: 8px;">
    Questo documento è prodotto dal programma di gestione del condominio con i dati registrati e riporta i campi della
    sezione «lavoro autonomo» del modello sintetico di Certificazione Unica. Non sostituisce il modello approvato dall'Agenzia
    delle Entrate: fa fede la certificazione trasmessa all'Agenzia. Struttura dei campi ricavata dalle istruzioni CU 2026,
    da verificare sul modello {{ $anno + 1 }}.
</div>

{{-- DATI DEL SOSTITUTO --}}
<div style="font-size: 8.5pt; font-weight: bold; color: #fff; background-color: #1e3a5f; padding: 3px 6px;">DATI RELATIVI AL SOSTITUTO</div>
<table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
    <tr>
        <td style="{{ $cell }} width: 30%;"><div style="{{ $lab }}">Codice fiscale</div><div style="{{ $val }}">{{ $sost['codice_fiscale'] }}</div></td>
        <td style="{{ $cell }}" colspan="3"><div style="{{ $lab }}">Cognome o denominazione</div><div style="{{ $val }}">{{ $sost['denominazione'] }}</div></td>
    </tr>
    <tr>
        <td style="{{ $cell }}"><div style="{{ $lab }}">Comune</div><div style="{{ $val }}">{{ $sost['comune'] }}</div></td>
        <td style="{{ $cell }} width: 10%;"><div style="{{ $lab }}">Prov.</div><div style="{{ $val }}">{{ $sost['provincia'] }}</div></td>
        <td style="{{ $cell }} width: 12%;"><div style="{{ $lab }}">CAP</div><div style="{{ $val }}">{{ $sost['cap'] }}</div></td>
        <td style="{{ $cell }}"><div style="{{ $lab }}">Indirizzo</div><div style="{{ $val }}">{{ $sost['indirizzo'] }}</div></td>
    </tr>
    <tr>
        <td style="{{ $cell }}"><div style="{{ $lab }}">Telefono</div><div style="{{ $val }}">{{ $sost['telefono'] }}</div></td>
        <td style="{{ $cell }}" colspan="3"><div style="{{ $lab }}">Indirizzo di posta elettronica</div><div style="{{ $val }}">{{ $sost['email'] }}</div></td>
    </tr>
</table>

@foreach($certificazioni as $c)
    @php $a = $c['anagrafica']; @endphp

    {{-- DATI DEL PERCIPIENTE --}}
    <div style="font-size: 8.5pt; font-weight: bold; color: #fff; background-color: #1e3a5f; padding: 3px 6px;">DATI RELATIVI AL PERCIPIENTE DELLE SOMME</div>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
        <tr>
            <td style="{{ $cell }} width: 30%;"><div style="{{ $lab }}">Codice fiscale</div><div style="{{ $val }}">{{ $a['codice_fiscale'] }}</div></td>
            <td style="{{ $cell }}" colspan="2"><div style="{{ $lab }}">Cognome o denominazione</div><div style="{{ $val }}">{{ $a['persona_fisica'] ? ($a['cognome'] ?: $a['denominazione']) : $a['denominazione'] }}</div></td>
            <td style="{{ $cell }}" colspan="2"><div style="{{ $lab }}">Nome</div><div style="{{ $val }}">{{ $a['persona_fisica'] ? $a['nome'] : '' }}</div></td>
        </tr>
        @if($a['persona_fisica'])
            <tr>
                <td style="{{ $cell }}"><div style="{{ $lab }}">Sesso (M o F)</div><div style="{{ $val }}">{{ $a['sesso'] }}</div></td>
                <td style="{{ $cell }}"><div style="{{ $lab }}">Data di nascita</div><div style="{{ $val }}">{{ $d($a['data_nascita']) }}</div></td>
                <td style="{{ $cell }}" colspan="2"><div style="{{ $lab }}">Comune (o Stato estero) di nascita</div><div style="{{ $val }}">{{ $a['comune_nascita'] }}</div></td>
                <td style="{{ $cell }}"><div style="{{ $lab }}">Prov. nascita</div><div style="{{ $val }}">{{ $a['provincia_nascita'] }}</div></td>
            </tr>
        @endif
        <tr>
            <td style="{{ $cell }}" colspan="2"><div style="{{ $lab }}">Domicilio fiscale — Comune</div><div style="{{ $val }}">{{ $a['domicilio']['comune'] }}</div></td>
            <td style="{{ $cell }}"><div style="{{ $lab }}">Prov.</div><div style="{{ $val }}">{{ $a['domicilio']['provincia'] }}</div></td>
            <td style="{{ $cell }}" colspan="2"><div style="{{ $lab }}">Indirizzo</div><div style="{{ $val }}">{{ $a['domicilio']['indirizzo'] }} {{ $a['domicilio']['cap'] }}</div></td>
        </tr>
    </table>

    {{-- DATI FISCALI --}}
    <div style="font-size: 8.5pt; font-weight: bold; color: #fff; background-color: #1e3a5f; padding: 3px 6px;">
        CERTIFICAZIONE LAVORO AUTONOMO, PROVVIGIONI E REDDITI DIVERSI — DATI FISCALI
    </div>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 4px;">
        <tr>
            <td style="{{ $cell }} width: 34%;"><div style="{{ $lab }}">1 · Causale</div><div style="{{ $val }}">{{ $c['punti']['1'] }}</div></td>
            <td style="{{ $cell }} width: 33%;"><div style="{{ $lab }}">2 · Anno</div><div style="{{ $val }}"></div></td>
            <td style="{{ $cell }}"><div style="{{ $lab }}">3 · Anticipazione</div><div style="{{ $val }}"></div></td>
        </tr>
        <tr>
            <td style="{{ $cell }}"><div style="{{ $lab }}">4 · Ammontare lordo corrisposto</div><div style="{{ $val }}">{{ $e($c['punti']['4']) }}</div></td>
            <td style="{{ $cell }}"><div style="{{ $lab }}">5 · Somme non soggette a ritenuta per regime convenzionale</div><div style="{{ $val }}">{{ $c['punti']['5'] ? $e($c['punti']['5']) : '' }}</div></td>
            <td style="{{ $cell }}"><div style="{{ $lab }}">6 · Codice</div><div style="{{ $val }}">{{ $c['punti']['6'] }}</div></td>
        </tr>
        <tr>
            <td style="{{ $cell }}"><div style="{{ $lab }}">7 · Altre somme non soggette a ritenuta</div><div style="{{ $val }}">{{ $c['punti']['7'] ? $e($c['punti']['7']) : '' }}</div></td>
            <td style="{{ $cell }}"><div style="{{ $lab }}">8 · Imponibile</div><div style="{{ $val }}">{{ $e($c['punti']['8']) }}</div></td>
            <td style="{{ $cell }}"><div style="{{ $lab }}">9 · Ritenute a titolo d'acconto</div><div style="{{ $val }}">{{ $c['punti']['9'] ? $e($c['punti']['9']) : '' }}</div></td>
        </tr>
        <tr>
            <td style="{{ $cell }}"><div style="{{ $lab }}">10 · Ritenute a titolo d'imposta</div><div style="{{ $val }}">{{ $c['punti']['10'] ? $e($c['punti']['10']) : '' }}</div></td>
            <td style="{{ $cell }}" colspan="2"><div style="{{ $lab }}">Descrizione causale</div><div style="font-size: 7.5pt;">{{ $c['causale_descrizione'] }}</div></td>
        </tr>
    </table>
    @if(count($c['non_soggette_per_codice']) > 1)
        <div style="font-size: 7pt; color: #b42318;">
            Somme non soggette con più codici:
            @foreach($c['non_soggette_per_codice'] as $codice => $importo) {{ $codice }} = {{ $e($importo) }}; @endforeach
            da ripartire secondo le istruzioni (verificare).
        </div>
    @endif

    <div style="font-size: 7pt; color: #444; margin: 2px 0 6px;">
        Importi in euro. Ritenute operate su pagamenti effettuati nel {{ $anno }}:
        @foreach($c['pagamenti'] as $r)
            {{ $d($r['data_pagamento']) }} ({{ $e($r['ritenuta']) }}){{ $loop->last ? '.' : ',' }}
        @endforeach
    </div>
@endforeach

<table style="width: 100%; margin-top: 16px;">
    <tr>
        <td style="width: 50%; font-size: 8pt;">Data, {{ now()->format('d/m/Y') }}</td>
        <td style="font-size: 8pt; text-align: center;">
            Firma del sostituto d'imposta<br>
            @if($firm['cognome'])
                <span style="font-size: 7.5pt; color: #444;">{{ $firm['cognome'] }} {{ $firm['nome'] }}{{ $firm['descrizione_carica'] ? ', '.mb_strtolower($firm['descrizione_carica']) : '' }}</span>
            @endif
            <div style="border-bottom: 1px solid #333; height: 26px; margin: 0 30px;"></div>
        </td>
    </tr>
</table>

@endsection
