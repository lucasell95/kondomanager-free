@extends('pdf.base')

@section('title', 'Prospetto rate unificato')

{{--
    Prospetto rate unificato — tutte le rate dell'esercizio sommate per condòmino e per scadenza.

    Variabili:
    - $prospetto:    array prodotto da ProspettoRateUnificatoService::calcola()
    - $conDettaglio: bool, mostra sotto ogni condòmino una riga per piano

    Importi in centesimi. I crediti sono negativi e vanno mostrati: il totale di colonna li
    include, nasconderli farebbe apparire un totale che non torna con le celle visibili.
--}}


@section('content')

@php
    $fmt = fn ($cents) => '€ ' . number_format(((int) $cents) / 100, 2, ',', '.');
    $scadenze = $prospetto['scadenze'];
    $totali = $prospetto['totali'];
    $etichettaEmissione = ['emessa' => 'emessa', 'bozza' => 'bozza', 'parziale' => 'emessa in parte'];
@endphp

{{-- INTESTAZIONE DOCUMENTO --}}
<div style="margin-bottom: 12px; border-bottom: 2px solid #1e3a5f; padding-bottom: 8px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: #1e3a5f; letter-spacing: 0.5px;">
        PROSPETTO RATE UNIFICATO — PER CONDÒMINO E SCADENZA
    </h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        Esercizio: <strong>{{ $esercizio->nome }}</strong>
        (dal {{ $esercizio->data_inizio->format('d/m/Y') }} al {{ $esercizio->data_fine->format('d/m/Y') }})
        &nbsp;|&nbsp;
        Situazione al <strong>{{ \Illuminate\Support\Carbon::parse($prospetto['oggi'])->format('d/m/Y') }}</strong>
    </div>
    <div style="font-size: 7.5pt; color: #555; margin-top: 3px;">
        Piani rate inclusi:
        @foreach($prospetto['piani'] as $piano)
            <strong>{{ $piano['nome'] }}</strong>
            ({{ $piano['gestione']['nome'] ?? '—' }}, {{ $piano['stato'] === 'approvato' ? 'approvato' : 'bozza' }})@if(!$loop->last); @endif
        @endforeach
    </div>
</div>

@if(empty($prospetto['righe']))
    <p style="color: #888; font-style: italic; text-align: center; margin: 20px 0;">
        Nessuna quota registrata nei piani rate di questo esercizio.
    </p>
@else

{{-- RIEPILOGO --}}
<table style="width: 100%; border-collapse: collapse; font-size: 8pt; margin-bottom: 10px;">
    <tr>
        <td style="padding: 5px 8px; border: 1px solid #b0c4de; background-color: #f5f8fc;">Totale anno<br><strong style="font-size: 10pt;">{{ $fmt($totali['totale']) }}</strong></td>
        <td style="padding: 5px 8px; border: 1px solid #b0c4de; background-color: #f0f9f4;">Incassato<br><strong style="font-size: 10pt; color: #047857;">{{ $fmt($totali['versato']) }}</strong></td>
        <td style="padding: 5px 8px; border: 1px solid #b0c4de; background-color: #fdf4f4;">Da saldare<br><strong style="font-size: 10pt; color: #b91c1c;">{{ $fmt($totali['da_saldare']) }}</strong></td>
        <td style="padding: 5px 8px; border: 1px solid #b0c4de; background-color: #fffbeb;">Scaduto<br><strong style="font-size: 10pt; color: #b45309;">{{ $fmt($totali['scaduto']) }}</strong></td>
    </tr>
</table>

<table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
    <thead>
        <tr style="background-color: #dce6f1; color: #1e3a5f; text-align: center;">
            <th style="padding: 5px 6px; border: 1px solid #b0c4de; text-align: left; min-width: 130px;">
                Condòmino
            </th>
            @foreach($scadenze as $i => $col)
                <th style="padding: 5px 4px; border: 1px solid #b0c4de; text-align: center;">
                    {{ $i + 1 }}ª scadenza<br>
                    <span style="font-weight: normal; font-size: 6.5pt;">
                        {{ $col['data'] ? \Illuminate\Support\Carbon::parse($col['data'])->format('d/m/Y') : 'senza data' }}
                        · {{ $etichettaEmissione[$col['emissione']] ?? $col['emissione'] }}
                    </span>
                </th>
            @endforeach
            <th style="padding: 5px 4px; border: 1px solid #b0c4de; background-color: #c8d8ee; text-align: right;">TOTALE ANNO</th>
            <th style="padding: 5px 4px; border: 1px solid #b0c4de; text-align: right;">VERSATO</th>
            <th style="padding: 5px 4px; border: 1px solid #b0c4de; text-align: right;">DA SALDARE</th>
            <th style="padding: 5px 4px; border: 1px solid #b0c4de; text-align: right;">SCADUTO</th>
        </tr>
    </thead>
    <tbody>
        @foreach($prospetto['righe'] as $idx => $riga)
            @php $sfondo = $idx % 2 === 0 ? '#ffffff' : '#f7f9fb'; @endphp
            <tr style="background-color: {{ $sfondo }};">
                <td style="padding: 4px 6px; border: 1px solid #dce3ea; font-weight: 600; color: #1e1e1e;">
                    {{ $riga['anagrafica']['nome'] }}
                </td>
                @foreach($scadenze as $col)
                    @php $cella = $riga['celle'][$col['chiave']] ?? null; @endphp
                    <td style="padding: 4px; text-align: right; border: 1px solid #dce3ea; font-weight: 600;">
                        @if($cella !== null)
                            <span style="color: {{ $cella['importo'] < 0 ? '#1d4ed8' : '#1e1e1e' }};">{{ $fmt($cella['importo']) }}</span>
                            @if($cella['stato'] === 'pagata')
                                <br><span style="font-weight: normal; font-size: 6pt; color: #047857;">saldata</span>
                            @elseif($cella['stato'] === 'parzialmente_pagata')
                                <br><span style="font-weight: normal; font-size: 6pt; color: #b45309;">resta {{ $fmt($cella['residuo']) }}</span>
                            @elseif($cella['stato'] === 'credito')
                                <br><span style="font-weight: normal; font-size: 6pt; color: #1d4ed8;">credito</span>
                            @endif
                        @else
                            <span style="color: #bbb;">—</span>
                        @endif
                    </td>
                @endforeach
                <td style="padding: 4px; text-align: right; border: 1px solid #b0c4de; font-weight: bold; background-color: #edf2f8; color: #1e3a5f;">{{ $fmt($riga['totale']) }}</td>
                <td style="padding: 4px; text-align: right; border: 1px solid #dce3ea; color: #047857;">{{ $fmt($riga['versato']) }}</td>
                <td style="padding: 4px; text-align: right; border: 1px solid #dce3ea; font-weight: bold; color: {{ $riga['da_saldare'] < 0 ? '#1d4ed8' : '#b91c1c' }};">{{ $fmt($riga['da_saldare']) }}</td>
                <td style="padding: 4px; text-align: right; border: 1px solid #dce3ea; color: #b45309;">{{ $riga['scaduto'] != 0 ? $fmt($riga['scaduto']) : '—' }}</td>
            </tr>

            @if($conDettaglio && count($riga['sottorighe']) > 1)
                @foreach($riga['sottorighe'] as $sotto)
                    <tr style="background-color: {{ $sfondo }}; color: #666; font-size: 6.5pt;">
                        <td style="padding: 2px 6px 2px 16px; border: 1px solid #eef1f5;">
                            di cui {{ $sotto['piano_nome'] }}
                            <span style="color: #999;">({{ $sotto['gestione_nome'] ?? '—' }})</span>
                        </td>
                        @foreach($scadenze as $col)
                            @php $pc = $sotto['celle'][$col['chiave']] ?? null; @endphp
                            <td style="padding: 2px 4px; text-align: right; border: 1px solid #eef1f5;">
                                {{ $pc !== null ? $fmt($pc['importo']) : '—' }}
                            </td>
                        @endforeach
                        <td style="padding: 2px 4px; text-align: right; border: 1px solid #eef1f5;">{{ $fmt($sotto['totale']) }}</td>
                        <td style="padding: 2px 4px; text-align: right; border: 1px solid #eef1f5;">{{ $fmt($sotto['versato']) }}</td>
                        <td style="padding: 2px 4px; text-align: right; border: 1px solid #eef1f5;">{{ $fmt($sotto['da_saldare']) }}</td>
                        <td style="padding: 2px 4px; text-align: right; border: 1px solid #eef1f5;">{{ $sotto['scaduto'] != 0 ? $fmt($sotto['scaduto']) : '—' }}</td>
                    </tr>
                @endforeach
            @endif
        @endforeach
    </tbody>
    <tfoot>
        <tr style="background-color: #dce6f1; font-weight: bold; color: #1e3a5f;">
            <td style="padding: 5px 6px; border: 1px solid #b0c4de; text-align: right; font-size: 8pt;">TOTALI</td>
            @foreach($scadenze as $col)
                <td style="padding: 5px 4px; text-align: right; border: 1px solid #b0c4de;">{{ $fmt($col['importo']) }}</td>
            @endforeach
            <td style="padding: 5px 4px; text-align: right; border: 2px solid #1e3a5f; background-color: #c8d8ee;">{{ $fmt($totali['totale']) }}</td>
            <td style="padding: 5px 4px; text-align: right; border: 1px solid #b0c4de;">{{ $fmt($totali['versato']) }}</td>
            <td style="padding: 5px 4px; text-align: right; border: 1px solid #b0c4de;">{{ $fmt($totali['da_saldare']) }}</td>
            <td style="padding: 5px 4px; text-align: right; border: 1px solid #b0c4de;">{{ $fmt($totali['scaduto']) }}</td>
        </tr>
    </tfoot>
</table>

<div style="font-size: 7pt; color: #666; margin-top: 4px;">
    * Ogni importo è la somma delle rate di tutti i piani dell'esercizio con la stessa scadenza; gli importi negativi sono crediti.
    Le gestioni restano distinte in contabilità: il prospetto è solo una vista riepilogativa.
    «Da saldare» = totale anno − versato. «Scaduto» = quanto resta da versare sulle scadenze già arrivate, senza compensare i crediti.
</div>

@endif

{{-- NOTE LEGALI --}}
<div style="margin-top: 12px; font-size: 7pt; color: #666; border-top: 1px solid #ccc; padding-top: 6px;">
    Documento redatto ai sensi dell'art. 1135 c.c. e dell'art. 63 disp. att. c.c.
    (riscossione dei contributi condominiali). Il mancato pagamento entro la scadenza indicata può comportare
    l'applicazione degli interessi legali ai sensi dell'art. 1284 c.c.
</div>

@endsection
