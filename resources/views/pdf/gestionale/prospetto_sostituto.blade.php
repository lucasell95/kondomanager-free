@extends('pdf.base')

@section('title', 'Prospetto CU e 770 ' . $prospetto['anno'])

@php
    $e = fn ($c) => number_format(((int) $c) / 100, 2, ',', '.');
    $d = fn ($s) => $s ? \Carbon\Carbon::parse($s)->format('d/m/Y') : '—';
    $th = 'padding: 4px 5px; border: 1px solid #b0c4de; background-color: #dce6f1; color: #1e3a5f; font-size: 7.5pt;';
    $td = 'padding: 3px 5px; border: 1px solid #d5dfeb; font-size: 7.5pt;';
    $num = $td . ' text-align: right;';
    $s = $prospetto['sostituto'];
    $statoLabel = [
        'regolare' => 'Versata nei termini',
        'tardivo' => 'Versata in ritardo',
        'omesso' => 'Non versata, scaduta',
        'da_versare' => 'Da versare',
        'eccedenza' => 'Versato in eccesso',
    ];
@endphp

@section('content')

<div style="margin-bottom: 10px; border-bottom: 2px solid #1e3a5f; padding-bottom: 6px;">
    <h2 style="margin: 0; font-size: 13pt; color: #1e3a5f;">
        PROSPETTO DEL SOSTITUTO D'IMPOSTA — ANNO {{ $prospetto['anno'] }}
    </h2>
    <div style="font-size: 8pt; color: #444; margin-top: 3px;">
        Ritenute operate nell'anno solare {{ $prospetto['anno'] }} (principio di cassa: data dei pagamenti), per la
        Certificazione Unica {{ $prospetto['anno'] + 1 }} e il modello 770/{{ $prospetto['anno'] + 1 }}.
        Elaborato il {{ $d($prospetto['generato_il']) }}.
    </div>
</div>

<div style="font-size: 7.5pt; color: #7a4a00; background-color: #fff7e6; border: 1px solid #f0d9a8; padding: 5px 7px; margin-bottom: 10px;">
    <strong>Documento prodotto dal programma sui dati registrati, da usare per compilare e controllare CU e 770.</strong>
    Non è una dichiarazione e non sostituisce i modelli ufficiali.
    @foreach($prospetto['avvertenze'] as $a) <br>• {{ $a }} @endforeach
</div>

{{-- SOSTITUTO --}}
<h3 style="font-size: 10pt; color: #1e3a5f; margin: 8px 0 4px;">Sostituto d'imposta e firmatario</h3>
<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="{{ $td }} width: 50%;">
            <strong>{{ $s['denominazione'] }}</strong> — C.F. {{ $s['codice_fiscale'] ?: '—' }}<br>
            {{ $s['indirizzo'] }} {{ $s['cap'] }} {{ $s['comune'] }} {{ $s['provincia'] ? '('.$s['provincia'].')' : '' }}<br>
            Email: {{ $s['email'] ?: '—' }} — Tel.: {{ $s['telefono'] ?: '—' }}
        </td>
        <td style="{{ $td }}">
            Firmatario: <strong>{{ trim(($s['firmatario']['cognome'] ?? '').' '.($s['firmatario']['nome'] ?? '')) ?: '—' }}</strong>
            — C.F. {{ $s['firmatario']['codice_fiscale'] ?: '—' }}<br>
            Codice carica: {{ $s['firmatario']['codice_carica'] ?: '—' }} {{ $s['firmatario']['descrizione_carica'] ? '('.$s['firmatario']['descrizione_carica'].')' : '' }}
            — in carica dal {{ $d($s['firmatario']['data_carica']) }}
        </td>
    </tr>
</table>
@foreach($s['anomalie'] as $a)
    <div style="font-size: 7.5pt; color: #b42318;">⚠ {{ $a }}</div>
@endforeach

{{-- PERCIPIENTI --}}
<h3 style="font-size: 10pt; color: #1e3a5f; margin: 12px 0 4px;">Percipienti: dati per la Certificazione Unica</h3>
<table style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr>
            <th style="{{ $th }}">Percipiente</th>
            <th style="{{ $th }}">Causale (1)</th>
            <th style="{{ $th }}">Lordo (4)</th>
            <th style="{{ $th }}">Cod. (6)</th>
            <th style="{{ $th }}">Non soggette (7)</th>
            <th style="{{ $th }}">Imponibile (8)</th>
            <th style="{{ $th }}">Rit. acconto (9)</th>
            <th style="{{ $th }}">Rit. imposta (10)</th>
            <th style="{{ $th }}">Versate</th>
            <th style="{{ $th }}">Differenza</th>
        </tr>
    </thead>
    <tbody>
        @forelse($prospetto['percipienti'] as $p)
            @php $a = $p['anagrafica']; @endphp
            <tr>
                <td style="{{ $td }}">
                    <strong>{{ $a['persona_fisica'] && $a['cognome'] ? $a['cognome'].' '.$a['nome'] : $a['denominazione'] }}</strong><br>
                    C.F. {{ $a['codice_fiscale'] ?: '—' }}
                    @if($a['persona_fisica'])
                        — {{ $a['sesso'] ?: '?' }}, nato/a il {{ $d($a['data_nascita']) }} a {{ $a['comune_nascita'] ?: '—' }} ({{ $a['provincia_nascita'] ?: '—' }})
                    @endif
                    <br>Domicilio: {{ $a['domicilio']['indirizzo'] }} {{ $a['domicilio']['cap'] }} {{ $a['domicilio']['comune'] }} ({{ $a['domicilio']['provincia'] }})
                </td>
                <td style="{{ $td }} text-align: center;">{{ $p['punti']['1'] ?? '—' }}</td>
                <td style="{{ $num }}">{{ $e($p['punti']['4']) }}</td>
                <td style="{{ $td }} text-align: center;">{{ $p['punti']['6'] ?? '' }}</td>
                <td style="{{ $num }}">{{ $e($p['punti']['7']) }}</td>
                <td style="{{ $num }}">{{ $e($p['punti']['8']) }}</td>
                <td style="{{ $num }}">{{ $e($p['punti']['9']) }}</td>
                <td style="{{ $num }}">{{ $e($p['punti']['10']) }}</td>
                <td style="{{ $num }}">{{ $e($p['ritenute_versate']) }}</td>
                <td style="{{ $num }} {{ $p['differenza'] !== 0 ? 'color: #b42318; font-weight: bold;' : '' }}">{{ $e($p['differenza']) }}</td>
            </tr>
            @foreach($p['anomalie'] as $an)
                <tr><td colspan="10" style="{{ $td }} color: #b42318;">⚠ {{ $an }}</td></tr>
            @endforeach
        @empty
            <tr><td colspan="10" style="{{ $td }} text-align: center; color: #666;">Nessun pagamento con ritenuta nell'anno.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- DETTAGLIO PAGAMENTI --}}
<h3 style="font-size: 10pt; color: #1e3a5f; margin: 12px 0 4px;">Dettaglio dei pagamenti con ritenuta</h3>
<table style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr>
            <th style="{{ $th }}">Data</th>
            <th style="{{ $th }}">Percipiente</th>
            <th style="{{ $th }}">Fatture</th>
            <th style="{{ $th }}">Lordo con IVA</th>
            <th style="{{ $th }}">Compenso</th>
            <th style="{{ $th }}">Non soggette</th>
            <th style="{{ $th }}">Imponibile</th>
            <th style="{{ $th }}">Ritenuta</th>
            <th style="{{ $th }}">Cod. trib.</th>
            <th style="{{ $th }}">Scadenza</th>
            <th style="{{ $th }}">Versato il</th>
            <th style="{{ $th }}">Stato</th>
        </tr>
    </thead>
    <tbody>
        @foreach($prospetto['percipienti'] as $p)
            @foreach($p['pagamenti'] as $r)
                <tr>
                    <td style="{{ $td }}">{{ $d($r['data_pagamento']) }}</td>
                    <td style="{{ $td }}">{{ $p['anagrafica']['denominazione'] }}</td>
                    <td style="{{ $td }}">{{ collect($r['fatture'])->map(fn ($f) => $f['numero'])->implode(', ') }}</td>
                    <td style="{{ $num }}">{{ $e($r['lordo_con_iva']) }}</td>
                    <td style="{{ $num }}">{{ $e($r['compenso']) }}</td>
                    <td style="{{ $num }}">{{ $e($r['non_soggette_totale']) }}</td>
                    <td style="{{ $num }}">{{ $e($r['imponibile']) }}</td>
                    <td style="{{ $num }}">{{ $e($r['ritenuta']) }}</td>
                    <td style="{{ $td }} text-align: center;">{{ $r['codice_tributo'] }}</td>
                    <td style="{{ $td }}">{{ $d($r['scadenza_legale']) }}</td>
                    <td style="{{ $td }}">{{ $d($r['data_versamento']) }}</td>
                    <td style="{{ $td }}">{{ $statoLabel[$r['stato_versamento']] ?? $r['stato_versamento'] }}{{ $r['giorni_ritardo'] ? ' ('.$r['giorni_ritardo'].' gg)' : '' }}</td>
                </tr>
            @endforeach
        @endforeach
    </tbody>
</table>

{{-- QUADRATURA --}}
<h3 style="font-size: 10pt; color: #1e3a5f; margin: 12px 0 4px;">Controllo: ritenute operate e versate per mese e codice tributo</h3>
<table style="width: 60%; border-collapse: collapse;">
    <thead>
        <tr>
            <th style="{{ $th }}">Periodo</th>
            <th style="{{ $th }}">Codice tributo</th>
            <th style="{{ $th }}">Operate</th>
            <th style="{{ $th }}">Versate</th>
            <th style="{{ $th }}">Differenza</th>
        </tr>
    </thead>
    <tbody>
        @forelse($prospetto['quadratura']['righe'] as $c)
            <tr>
                <td style="{{ $td }}">{{ $c['periodo'] }}</td>
                <td style="{{ $td }} text-align: center;">{{ $c['codice_tributo'] }}</td>
                <td style="{{ $num }}">{{ $e($c['operate']) }}</td>
                <td style="{{ $num }}">{{ $e($c['versate']) }}</td>
                <td style="{{ $num }} {{ $c['differenza'] !== 0 ? 'color: #b42318; font-weight: bold;' : '' }}">{{ $e($c['differenza']) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="{{ $td }} text-align: center; color: #666;">Nessuna ritenuta nell'anno.</td></tr>
        @endforelse
    </tbody>
</table>

@if(count($prospetto['quadratura']['ravvedimenti']) > 0)
    <h4 style="font-size: 9pt; color: #b42318; margin: 8px 0 3px;">Ravvedimenti da valutare (stima indicativa, da verificare)</h4>
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="{{ $th }}">Pagamento</th>
                <th style="{{ $th }}">Cod. trib.</th>
                <th style="{{ $th }}">Ritenuta</th>
                <th style="{{ $th }}">Scadenza</th>
                <th style="{{ $th }}">Versata il</th>
                <th style="{{ $th }}">Giorni</th>
                <th style="{{ $th }}">Interessi (stima)</th>
                <th style="{{ $th }}">Sanzione ridotta (stima)</th>
                <th style="{{ $th }}">Annotato</th>
            </tr>
        </thead>
        <tbody>
            @foreach($prospetto['quadratura']['ravvedimenti'] as $rv)
                <tr>
                    <td style="{{ $td }}">{{ $d($rv['data_pagamento']) }}</td>
                    <td style="{{ $td }} text-align: center;">{{ $rv['codice_tributo'] }}</td>
                    <td style="{{ $num }}">{{ $e($rv['ritenuta']) }}</td>
                    <td style="{{ $td }}">{{ $d($rv['scadenza_legale']) }}</td>
                    <td style="{{ $td }}">{{ $rv['stato'] === 'omesso' ? 'non versata' : $d($rv['data_versamento']) }}</td>
                    <td style="{{ $num }}">{{ $rv['giorni_ritardo'] }}</td>
                    <td style="{{ $num }}">{{ $e($rv['stima']['interessi'] ?? 0) }}</td>
                    <td style="{{ $num }}">{{ $e($rv['stima']['sanzione'] ?? 0) }} ({{ number_format($rv['stima']['percentuale_sanzione'] ?? 0, 3, ',', '.') }}%)</td>
                    <td style="{{ $td }}">{{ $rv['ravvedimento_registrato'] ? 'sì' : 'no' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- QUADRO ST --}}
<h3 style="font-size: 10pt; color: #1e3a5f; margin: 12px 0 4px;">Modello 770 — quadro ST, sezione I (erario)</h3>
<div style="font-size: 7pt; color: #666; margin-bottom: 3px;">Colonne indicate per contenuto: la numerazione va verificata sulle istruzioni del modello dell'anno.</div>
<table style="width: 100%; border-collapse: collapse;">
    <thead>
        <tr>
            <th style="{{ $th }}">Periodo di riferimento</th>
            <th style="{{ $th }}">Ritenute operate</th>
            <th style="{{ $th }}">Interessi</th>
            <th style="{{ $th }}">Ravvedimento</th>
            <th style="{{ $th }}">Importo versato</th>
            <th style="{{ $th }}">Codice tributo</th>
            <th style="{{ $th }}">Data versamento</th>
            <th style="{{ $th }}">Deleghe</th>
        </tr>
    </thead>
    <tbody>
        @forelse($prospetto['st'] as $r)
            <tr>
                <td style="{{ $td }}">{{ $r['periodo'] }}</td>
                <td style="{{ $num }}">{{ $e($r['ritenute_operate']) }}</td>
                <td style="{{ $num }}">{{ $e($r['interessi']) }}</td>
                <td style="{{ $td }} text-align: center;">{{ $r['ravvedimento'] ? 'X' : '' }}</td>
                <td style="{{ $num }}">{{ $e($r['importo_versato']) }}</td>
                <td style="{{ $td }} text-align: center;">{{ $r['codice_tributo'] }}</td>
                <td style="{{ $td }}">{{ $d($r['data_versamento']) }}</td>
                <td style="{{ $td }}">{{ implode(', ', $r['deleghe']) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" style="{{ $td }} text-align: center; color: #666;">Nessun versamento registrato per l'anno.</td></tr>
        @endforelse
    </tbody>
</table>

@if(count($prospetto['sx']) > 0)
    <h3 style="font-size: 10pt; color: #1e3a5f; margin: 12px 0 4px;">Quadro SX — versamenti in eccesso da valutare</h3>
    <table style="width: 70%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="{{ $th }}">Periodo</th>
                <th style="{{ $th }}">Codice tributo</th>
                <th style="{{ $th }}">Versato</th>
                <th style="{{ $th }}">Operate</th>
                <th style="{{ $th }}">Eccedenza</th>
                <th style="{{ $th }}">Data versamento</th>
            </tr>
        </thead>
        <tbody>
            @foreach($prospetto['sx'] as $x)
                <tr>
                    <td style="{{ $td }}">{{ $x['periodo'] }}</td>
                    <td style="{{ $td }} text-align: center;">{{ $x['codice_tributo'] }}</td>
                    <td style="{{ $num }}">{{ $e($x['versato']) }}</td>
                    <td style="{{ $num }}">{{ $e($x['operate']) }}</td>
                    <td style="{{ $num }}">{{ $e($x['eccedenza']) }}</td>
                    <td style="{{ $td }}">{{ $d($x['data_versamento']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div style="font-size: 7pt; color: #666;">Un versamento in eccesso può diventare credito da utilizzare in compensazione (quadro SX): da verificare con le istruzioni.</div>
@endif

@if(count($prospetto['forfetari']) > 0)
    <h3 style="font-size: 10pt; color: #1e3a5f; margin: 12px 0 4px;">Compensi a fornitori in regime forfetario (senza ritenuta)</h3>
    <div style="font-size: 7pt; color: #666; margin-bottom: 3px;">Dal periodo d'imposta 2024 esonerati dalla CU (art. 4 c. 6-septies DPR 322/1998), salvo casi residui: da verificare.</div>
    <table style="width: 70%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="{{ $th }}">Fornitore</th>
                <th style="{{ $th }}">Codice fiscale</th>
                <th style="{{ $th }}">Pagamenti</th>
                <th style="{{ $th }}">Compenso netto IVA</th>
            </tr>
        </thead>
        <tbody>
            @foreach($prospetto['forfetari'] as $f)
                <tr>
                    <td style="{{ $td }}">{{ $f['denominazione'] }}</td>
                    <td style="{{ $td }}">{{ $f['codice_fiscale'] }}</td>
                    <td style="{{ $num }}">{{ $f['pagamenti'] }}</td>
                    <td style="{{ $num }}">{{ $e($f['compenso']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<h3 style="font-size: 10pt; color: #1e3a5f; margin: 12px 0 4px;">Scadenze (da verificare sui provvedimenti dell'anno)</h3>
<table style="width: 70%; border-collapse: collapse;">
    @foreach($prospetto['scadenze'] as $sc)
        <tr>
            <td style="{{ $td }}">{{ $sc['adempimento'] }}</td>
            <td style="{{ $td }}">{{ $d($sc['data']) }}</td>
            <td style="{{ $td }} color: #666;">{{ $sc['nota'] }}</td>
        </tr>
    @endforeach
</table>

@endsection
