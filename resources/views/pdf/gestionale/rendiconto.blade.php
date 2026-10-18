@extends('pdf.base')

@section('title', 'Rendiconto condominiale')

{{--
    Rendiconto condominiale annuale (art. 1130-bis c.c.).

    Variabili:
    - $r:       array di RendicontoService::calcola()
    - $nota:    array di RendicontoService::nota() (usa `compilata`, già con i numeri)
    - $sezione: 'tutto' | 'riepilogo' | 'registro' | 'consuntivo' | 'riparto' | 'nota'

    Importi in centesimi. Le parti in tabella larga (riparto e conguagli) vanno in A4 orizzontale,
    il resto in verticale: `pagebreak orientation` cambia l'orientamento a metà documento.
--}}

@section('content')
@php
    $fmt = fn ($cents) => '€ ' . number_format(((int) $cents) / 100, 2, ',', '.');
    $fmtV = fn ($cents) => ((int) $cents) === 0 ? '—' : $fmt($cents);
    $data = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—';
    $perc = fn ($p) => $p === null ? '—' : number_format($p, 1, ',', '.') . '%';
    $tutto = $sezione === 'tutto';
    $mostra = fn (string $s) => $tutto || $sezione === $s;
    $es = $r['esercizio'];
    $rie = $r['riepilogo'];
    $navy = '#1e3a5f';
    $bordo = '#b0c4de';
    $th = "padding: 4px 5px; border: 1px solid {$bordo}; background-color: #dce6f1; color: {$navy}; font-weight: bold;";
    $td = "padding: 3px 5px; border: 1px solid #d5dde8;";
    $tdR = $td . ' text-align: right;';
    $tot = "padding: 4px 5px; border: 1px solid {$bordo}; background-color: #edf2f8; font-weight: bold;";
    $totR = $tot . ' text-align: right;';
    $h2 = "margin: 0 0 6px 0; font-size: 12pt; color: {$navy}; border-bottom: 2px solid {$navy}; padding-bottom: 3px;";
    $h3 = "margin: 10px 0 4px 0; font-size: 10pt; color: {$navy};";
    $orient = 'P';
    $primaPagina = true;
    $etichettaRuolo = ['proprietario' => 'P', 'inquilino' => 'I', 'usufruttuario' => 'U', 'nuda_proprietario' => 'NP', 'nudo_proprietario' => 'NP'];
@endphp

{{-- ═══ COPERTINA ═════════════════════════════════════════════════════════════════════ --}}
@if($tutto)
    <div style="margin-bottom: 14px; border-bottom: 2px solid {{ $navy }}; padding-bottom: 8px;">
        <h1 style="margin: 0; font-size: 15pt; color: {{ $navy }}; border: none;">RENDICONTO CONDOMINIALE</h1>
        <div style="font-size: 9pt; color: #444; margin-top: 3px;">
            ai sensi dell'art. 1130-bis del codice civile — esercizio <strong>{{ $es['nome'] }}</strong>
            (dal {{ $data($es['data_inizio']) }} al {{ $data($es['data_fine']) }})
        </div>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-bottom: 12px;">
        <tr>
            <td style="{{ $td }} background-color: #f5f8fc;">Saldo iniziale cassa e banca<br><strong style="font-size: 10pt;">{{ $fmt($rie['totali']['saldo_iniziale']) }}</strong></td>
            <td style="{{ $td }} background-color: #f0f9f4;">Entrate<br><strong style="font-size: 10pt;">{{ $fmt($rie['totali']['entrate']) }}</strong></td>
            <td style="{{ $td }} background-color: #fdf4f4;">Uscite<br><strong style="font-size: 10pt;">{{ $fmt($rie['totali']['uscite']) }}</strong></td>
            <td style="{{ $td }} background-color: #f5f8fc;">Saldo finale cassa e banca<br><strong style="font-size: 10pt;">{{ $fmt($rie['totali']['saldo_finale']) }}</strong></td>
        </tr>
    </table>

    <div style="font-size: 9pt; margin-bottom: 8px;"><strong>Il rendiconto si compone di:</strong></div>
    <ol style="font-size: 8.5pt; margin-top: 0;">
        <li>Riepilogo finanziario: saldi di cassa, banca e fondi, entrate e uscite per categoria, crediti, debiti e situazione patrimoniale.</li>
        <li>Registro di contabilità: movimenti di entrata e di uscita in ordine cronologico.</li>
        <li>Consuntivo per voce di spesa confrontato con il preventivo, per ciascuna gestione.</li>
        <li>Riparto consuntivo delle spese e conguagli per condòmino, per ciascuna gestione.</li>
        <li>Nota sintetica esplicativa della gestione.</li>
    </ol>

    <div style="font-size: 8pt; margin-top: 10px;">
        <strong>Verifiche interne:</strong>
        @if($r['esito_verifiche'] === 'ok')
            <span style="color: #047857;">tutti i controlli sono superati.</span>
        @elseif($r['esito_verifiche'] === 'avviso')
            <span style="color: #b45309;">controlli superati, con avvisi (vedi l'ultima pagina).</span>
        @else
            <span style="color: #b91c1c;">alcuni controlli NON sono superati (vedi l'ultima pagina): il rendiconto va corretto prima dell'approvazione.</span>
        @endif
    </div>
    @php $primaPagina = false; @endphp
@endif

{{-- ═══ 1. RIEPILOGO FINANZIARIO ══════════════════════════════════════════════════════ --}}
@if($mostra('riepilogo'))
    @if(! $primaPagina)<pagebreak orientation="P" />@endif
    @php $primaPagina = false; $orient = 'P'; @endphp
    <h2 style="{{ $h2 }}">1. Riepilogo finanziario</h2>

    <h3 style="{{ $h3 }}">Cassa, banca e fondi</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
        <thead><tr>
            <th style="{{ $th }} text-align: left;">Conto</th>
            <th style="{{ $th }} text-align: right;">Saldo al {{ $data($es['data_inizio']) }}</th>
            <th style="{{ $th }} text-align: right;">Entrate</th>
            <th style="{{ $th }} text-align: right;">Uscite</th>
            <th style="{{ $th }} text-align: right;">Saldo al {{ $data($es['data_fine']) }}</th>
            <th style="{{ $th }} text-align: right;">Estratto conto</th>
        </tr></thead>
        <tbody>
        @foreach($rie['conti'] as $c)
            <tr>
                <td style="{{ $td }}">{{ $c['nome'] }}@if($c['sottotipo_label'])<br><span style="font-size: 6.5pt; color: #666;">{{ $c['sottotipo_label'] }}@if($c['vincolo']) — {{ $c['vincolo'] }}@endif</span>@endif</td>
                <td style="{{ $tdR }}">{{ $fmt($c['saldo_iniziale']) }}</td>
                <td style="{{ $tdR }}">{{ $fmt($c['entrate']) }}</td>
                <td style="{{ $tdR }}">{{ $fmt($c['uscite']) }}</td>
                <td style="{{ $tdR }} font-weight: bold;">{{ $fmt($c['saldo_finale']) }}</td>
                <td style="{{ $tdR }}">{{ $c['estratto_conto'] === null ? '—' : $fmt($c['estratto_conto']) }}@if($c['differenza_estratto'])<br><span style="color: #b91c1c; font-size: 6.5pt;">diff. {{ $fmt($c['differenza_estratto']) }}</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr>
            <td style="{{ $tot }}">Totale</td>
            <td style="{{ $totR }}">{{ $fmt($rie['totali']['saldo_iniziale']) }}</td>
            <td style="{{ $totR }}">{{ $fmt($rie['totali']['entrate']) }}</td>
            <td style="{{ $totR }}">{{ $fmt($rie['totali']['uscite']) }}</td>
            <td style="{{ $totR }}">{{ $fmt($rie['totali']['saldo_finale']) }}</td>
            <td style="{{ $tot }}"></td>
        </tr></tfoot>
    </table>
    <div style="font-size: 6.5pt; color: #666; margin-top: 2px;">I fondi sono partizioni del conto corrente: i giroconti fra conto e fondi compaiono come uscita da un conto ed entrata nell'altro e nel totale si elidono.</div>

    <h3 style="{{ $h3 }}">Entrate e uscite per categoria</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
        <thead><tr>
            <th style="{{ $th }} text-align: left;">Categoria</th>
            <th style="{{ $th }} text-align: right;">Entrate</th>
            <th style="{{ $th }} text-align: right;">Uscite</th>
            <th style="{{ $th }} text-align: right;">Netto</th>
        </tr></thead>
        <tbody>
        @forelse($rie['categorie'] as $c)
            <tr>
                <td style="{{ $td }}{{ $c['interna'] ? ' color: #666; font-style: italic;' : '' }}">{{ $c['nome'] }}</td>
                <td style="{{ $tdR }}">{{ $fmtV($c['entrate']) }}</td>
                <td style="{{ $tdR }}">{{ $fmtV($c['uscite']) }}</td>
                <td style="{{ $tdR }}">{{ $fmt($c['netto']) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" style="{{ $td }} color: #888; font-style: italic;">Nessun movimento di cassa nell'esercizio.</td></tr>
        @endforelse
        </tbody>
        <tfoot><tr>
            <td style="{{ $tot }}">Totale</td>
            <td style="{{ $totR }}">{{ $fmt($rie['totali']['entrate']) }}</td>
            <td style="{{ $totR }}">{{ $fmt($rie['totali']['uscite']) }}</td>
            <td style="{{ $totR }}">{{ $fmt($rie['totali']['entrate'] - $rie['totali']['uscite']) }}</td>
        </tr></tfoot>
    </table>

    <h3 style="{{ $h3 }}">Fondi e riserve</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
        <tr><td style="{{ $td }}">Fondo speciale per lavori straordinari (art. 1135 n. 4 c.c.)</td><td style="{{ $tdR }} width: 22%;">{{ $fmt($rie['fondo_speciale']) }}</td></tr>
        @foreach($rie['fondi'] as $f)
            @if(($f['sottotipo_fondo'] ?? null) !== 'vincolato_lavori')
                <tr><td style="{{ $td }}">{{ $f['nome'] }} ({{ $f['sottotipo_label'] }})</td><td style="{{ $tdR }}">{{ $fmt($f['saldo_finale']) }}</td></tr>
            @endif
        @endforeach
        @foreach($rie['fondi_passivi'] as $f)
            <tr><td style="{{ $td }}">{{ $f['codice'] }} {{ $f['nome'] }} <span style="color: #666;">(conto patrimoniale)</span></td><td style="{{ $tdR }}">{{ $fmt($f['saldo']) }}</td></tr>
        @endforeach
    </table>

    <h3 style="{{ $h3 }}">Crediti verso i condòmini al {{ $data($es['data_fine']) }}</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
        <thead><tr>
            <th style="{{ $th }} text-align: left;">Condòmino</th>
            <th style="{{ $th }} text-align: right;">Rate non pagate</th>
            <th style="{{ $th }} text-align: right;">Anticipi</th>
            <th style="{{ $th }} text-align: right;">Saldo</th>
        </tr></thead>
        <tbody>
        @forelse($rie['crediti']['righe'] as $c)
            <tr>
                <td style="{{ $td }}">{{ $c['nome'] }}</td>
                <td style="{{ $tdR }}">{{ $fmtV($c['crediti']) }}</td>
                <td style="{{ $tdR }}">{{ $fmtV($c['anticipi']) }}</td>
                <td style="{{ $tdR }}{{ $c['saldo'] > 0 ? ' color: #b91c1c;' : '' }}">{{ $fmt($c['saldo']) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" style="{{ $td }} color: #888; font-style: italic;">Nessun credito aperto.</td></tr>
        @endforelse
        @if($rie['crediti']['anticipazioni_ad_personam'] !== 0)
            <tr><td style="{{ $td }}" colspan="3">Spese ad personam anticipate e non ancora addebitate in rata</td><td style="{{ $tdR }}">{{ $fmt($rie['crediti']['anticipazioni_ad_personam']) }}</td></tr>
        @endif
        </tbody>
        <tfoot><tr>
            <td style="{{ $tot }}" colspan="3">Totale (positivo = dovuto dai condòmini)</td>
            <td style="{{ $totR }}">{{ $fmt($rie['crediti']['totale'] + $rie['crediti']['anticipazioni_ad_personam']) }}</td>
        </tr></tfoot>
    </table>

    <h3 style="{{ $h3 }}">Debiti al {{ $data($es['data_fine']) }}</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
        <thead><tr>
            <th style="{{ $th }} text-align: left;">Fornitore</th>
            <th style="{{ $th }} text-align: left;">Documento</th>
            <th style="{{ $th }} text-align: left;">Data</th>
            <th style="{{ $th }} text-align: right;">Da pagare</th>
        </tr></thead>
        <tbody>
        @forelse($rie['debiti']['fatture'] as $d)
            <tr>
                <td style="{{ $td }}">{{ $d['fornitore'] }}</td>
                <td style="{{ $td }}">{{ $d['numero'] }}</td>
                <td style="{{ $td }}">{{ $data($d['data']) }}</td>
                <td style="{{ $tdR }}">{{ $fmt($d['residuo']) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" style="{{ $td }} color: #888; font-style: italic;">Nessuna fattura da pagare.</td></tr>
        @endforelse
        <tr><td style="{{ $td }}" colspan="3">Ritenute d'acconto operate e da versare</td><td style="{{ $tdR }}">{{ $fmt($rie['debiti']['ritenute_da_versare']) }}</td></tr>
        </tbody>
    </table>

    @php $sp = $rie['stato_patrimoniale']; @endphp
    <h3 style="{{ $h3 }}">Situazione patrimoniale al {{ $data($es['data_fine']) }}</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 4px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr><th style="{{ $th }} text-align: left;" colspan="2">Attività</th></tr>
                    @foreach($sp['attivo']['voci'] as $v)
                        <tr><td style="{{ $td }}">{{ $v['codice'] }} {{ $v['nome'] }}</td><td style="{{ $tdR }}">{{ $fmt($v['saldo']) }}</td></tr>
                    @endforeach
                    @if($sp['liquidita_non_contabilizzata'] !== 0)
                        <tr><td style="{{ $td }}">Saldi di apertura non ancora a giornale</td><td style="{{ $tdR }}">{{ $fmt($sp['liquidita_non_contabilizzata']) }}</td></tr>
                    @endif
                    <tr><td style="{{ $tot }}">Totale attività</td><td style="{{ $totR }}">{{ $fmt($sp['attivo']['totale']) }}</td></tr>
                </table>
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 4px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr><th style="{{ $th }} text-align: left;" colspan="2">Passività e fondi</th></tr>
                    @foreach($sp['passivo']['voci'] as $v)
                        <tr><td style="{{ $td }}">{{ $v['codice'] }} {{ $v['nome'] }}</td><td style="{{ $tdR }}">{{ $fmt($v['saldo']) }}</td></tr>
                    @endforeach
                    <tr><td style="{{ $td }}">Risultato dell'esercizio (entrate − costi)</td><td style="{{ $tdR }}">{{ $fmt($sp['risultato_esercizio']) }}</td></tr>
                    @if($sp['risultato_esercizi_precedenti'] !== 0)
                        <tr><td style="{{ $td }}">Risultato degli esercizi precedenti</td><td style="{{ $tdR }}">{{ $fmt($sp['risultato_esercizi_precedenti']) }}</td></tr>
                    @endif
                    <tr><td style="{{ $tot }}">Totale</td><td style="{{ $totR }}">{{ $fmt($sp['passivo']['totale'] + $sp['risultato_esercizio'] + $sp['risultato_esercizi_precedenti']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
    <div style="font-size: 6.5pt; color: #666; margin-top: 2px;">Le rate non sono ricavi: confluiscono nel conto Gestione rate (fondo), dal quale le spese dell'esercizio vengono coperte. Il conguaglio per condòmino è nel riparto consuntivo.</div>
@endif

{{-- ═══ 2. REGISTRO DI CONTABILITÀ ════════════════════════════════════════════════════ --}}
@if($mostra('registro'))
    @if(! $primaPagina)<pagebreak orientation="P" />@endif
    @php $primaPagina = false; $orient = 'P'; $reg = $r['registro']; @endphp
    <h2 style="{{ $h2 }}">2. Registro di contabilità</h2>
    <div style="font-size: 7.5pt; color: #444; margin-bottom: 4px;">Movimenti di entrata e di uscita di cassa, banca e fondi dal {{ $data($es['data_inizio']) }} al {{ $data($es['data_fine']) }}, in ordine cronologico.</div>
    <table style="width: 100%; border-collapse: collapse; font-size: 6.8pt;">
        <thead><tr>
            {{-- Protocollo al 14% e senza a capo: all'11% «RIM-2026-00009» si spezzava su due righe
                 (collaudo 1, E4). In mPDF il nowrap non basta se la colonna è stretta: servono
                 le larghezze giuste (Data 10%, provate con DejaVu Sans a 6,8pt e margini 12 mm). --}}
            <th style="{{ $th }} text-align: left; width: 10%;">Data</th>
            <th style="{{ $th }} text-align: left; width: 14%; white-space: nowrap;">Protocollo</th>
            <th style="{{ $th }} text-align: left;">Descrizione</th>
            <th style="{{ $th }} text-align: left; width: 12%;">Conto</th>
            <th style="{{ $th }} text-align: right; width: 10%;">Entrate</th>
            <th style="{{ $th }} text-align: right; width: 10%;">Uscite</th>
        </tr></thead>
        <tbody>
        @forelse($reg['righe'] as $m)
            <tr>
                <td style="{{ $td }} white-space: nowrap;">{{ $data($m['data']) }}</td>
                <td style="{{ $td }} white-space: nowrap;">{{ $m['protocollo'] }}</td>
                <td style="{{ $td }}">{{ $m['descrizione'] }}@if($m['gestione'] !== '—')<span style="color: #666;"> · {{ $m['gestione'] }}</span>@endif @if($m['annullata'])<span style="color: #b91c1c;"> [annullata]</span>@endif</td>
                <td style="{{ $td }}">{{ $m['cassa'] }}</td>
                <td style="{{ $tdR }} white-space: nowrap;">{{ $m['entrata'] ? $fmt($m['entrata']) : '' }}</td>
                <td style="{{ $tdR }} white-space: nowrap;">{{ $m['uscita'] ? $fmt($m['uscita']) : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="{{ $td }} color: #888; font-style: italic;">Nessun movimento.</td></tr>
        @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td style="{{ $tot }}" colspan="4">Totale movimenti</td>
                <td style="{{ $totR }}">{{ $fmt($reg['totali']['entrate']) }}</td>
                <td style="{{ $totR }}">{{ $fmt($reg['totali']['uscite']) }}</td>
            </tr>
            <tr>
                <td style="{{ $tot }}" colspan="4">Saldo iniziale {{ $fmt($reg['totali']['saldo_iniziale']) }} + entrate − uscite = saldo finale</td>
                <td style="{{ $totR }}" colspan="2">{{ $fmt($reg['totali']['saldo_finale']) }}</td>
            </tr>
        </tfoot>
    </table>
@endif

{{-- ═══ 3. CONSUNTIVO E PREVENTIVO ════════════════════════════════════════════════════ --}}
@if($mostra('consuntivo'))
    @if(! $primaPagina)<pagebreak orientation="P" />@endif
    @php $primaPagina = false; $orient = 'P'; @endphp
    <h2 style="{{ $h2 }}">3. Consuntivo per voce di spesa e confronto con il preventivo</h2>
    @forelse($r['consuntivo']['gestioni'] as $g)
        @php $t = $g['totali']; @endphp
        <h3 style="{{ $h3 }}">{{ $g['nome'] }} <span style="font-weight: normal; color: #666; font-size: 8pt;">(gestione {{ $g['tipo'] }})</span></h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt; page-break-inside: auto;">
            <thead><tr>
                <th style="{{ $th }} text-align: left;">Voce di spesa</th>
                <th style="{{ $th }} text-align: right; width: 15%;">Preventivo</th>
                <th style="{{ $th }} text-align: right; width: 15%;">Consuntivo</th>
                <th style="{{ $th }} text-align: right; width: 15%;">Scostamento</th>
                <th style="{{ $th }} text-align: right; width: 9%;">%</th>
            </tr></thead>
            <tbody>
            @foreach($g['voci'] as $v)
                <tr>
                    <td style="{{ $td }} padding-left: {{ 5 + $v['livello'] * 12 }}px;{{ $v['ha_figli'] ? ' font-weight: bold;' : '' }}">{{ $v['nome'] }}@if($v['tecnico'])<span style="color: #666;"> (fuori preventivo)</span>@endif</td>
                    <td style="{{ $tdR }}">{{ $fmt($v['preventivo']) }}</td>
                    <td style="{{ $tdR }}">{{ $fmt($v['consuntivo']) }}</td>
                    <td style="{{ $tdR }}{{ $v['scostamento'] > 0 ? ' color: #b91c1c;' : '' }}">{{ $fmt($v['scostamento']) }}</td>
                    <td style="{{ $tdR }}">{{ $perc($v['scostamento_perc']) }}</td>
                </tr>
            @endforeach
            @if($g['sopravvenienze'] !== [])
                <tr><td colspan="5" style="{{ $td }} background-color: #f7f9fb; font-weight: bold;">Spese fuori preventivo (sopravvenienze)</td></tr>
                @foreach($g['sopravvenienze'] as $v)
                    <tr>
                        <td style="{{ $td }} padding-left: {{ 5 + $v['livello'] * 12 }}px;">{{ $v['nome'] }}</td>
                        <td style="{{ $tdR }}">—</td>
                        <td style="{{ $tdR }}">{{ $fmt($v['consuntivo']) }}</td>
                        <td style="{{ $tdR }}">{{ $fmt($v['scostamento']) }}</td>
                        <td style="{{ $tdR }}">—</td>
                    </tr>
                @endforeach
            @endif
            @if($g['ad_personam'] !== [])
                <tr><td colspan="5" style="{{ $td }} background-color: #f7f9fb; font-weight: bold;">Spese individuali (addebitate alla singola unità)</td></tr>
                @foreach($g['ad_personam'] as $a)
                    <tr><td style="{{ $td }} padding-left: 17px;">{{ $a['nome'] }}</td><td style="{{ $tdR }}">—</td><td style="{{ $tdR }}">{{ $fmt($a['importo']) }}</td><td style="{{ $tdR }}">{{ $fmt($a['importo']) }}</td><td style="{{ $tdR }}">—</td></tr>
                @endforeach
            @endif
            @if($g['senza_voce'] !== [])
                <tr><td colspan="5" style="{{ $td }} background-color: #fff7ed; font-weight: bold;">Costi senza voce di spesa (non ripartiti)</td></tr>
                @foreach($g['senza_voce'] as $a)
                    <tr><td style="{{ $td }} padding-left: 17px;">{{ $a['conto'] }}</td><td style="{{ $tdR }}">—</td><td style="{{ $tdR }}">{{ $fmt($a['importo']) }}</td><td style="{{ $tdR }}">{{ $fmt($a['importo']) }}</td><td style="{{ $tdR }}">—</td></tr>
                @endforeach
            @endif
            </tbody>
            <tfoot><tr>
                <td style="{{ $tot }}">Totale {{ $g['nome'] }}</td>
                <td style="{{ $totR }}">{{ $fmt($t['preventivo']) }}</td>
                <td style="{{ $totR }}">{{ $fmt($t['consuntivo']) }}</td>
                <td style="{{ $totR }}">{{ $fmt($t['scostamento']) }}</td>
                <td style="{{ $totR }}">{{ $perc($t['scostamento_perc']) }}</td>
            </tr></tfoot>
        </table>
    @empty
        <p style="color: #888; font-style: italic;">Nessuna gestione con preventivo o spese.</p>
    @endforelse
    <div style="font-size: 6.5pt; color: #666; margin-top: 4px;">Importi IVA inclusa, per competenza: una fattura dell'esercizio è spesa anche se pagata dopo. Scostamento = consuntivo − preventivo (positivo = spesa superiore al preventivo).</div>
@endif

{{-- ═══ 4. RIPARTO CONSUNTIVO E CONGUAGLI (A4 orizzontale) ═══════════════════════════ --}}
@if($mostra('riparto'))
    @foreach($r['riparto'] as $rip)
        @if(! $primaPagina)<pagebreak orientation="L" />@endif
        @php $primaPagina = false; $orient = 'L'; $t = $rip['totali']; @endphp
        <h2 style="{{ $h2 }}">4. Riparto consuntivo e conguagli — {{ $rip['gestione'] }}</h2>
        <div style="font-size: 7.5pt; color: #444; margin-bottom: 4px;">
            Spese effettive dell'esercizio ripartite con le tabelle millesimali e i criteri di ciascuna voce
            (P = proprietario, I = inquilino, U = usufruttuario). Conguaglio = saldo iniziale + quota consuntiva − già versato − versato nell'esercizio:
            positivo a debito del condòmino, negativo a suo credito.
            @if($rip['tipo'] !== 'ordinaria')
                Per una gestione straordinaria il saldo a credito è, di norma, quanto versato e non ancora speso (fondo lavori, art. 1135 n. 4 c.c.) e si riporta sulla stessa gestione.
            @endif
        </div>

        <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
            <thead><tr>
                <th style="{{ $th }} text-align: left;">Condòmino</th>
                <th style="{{ $th }} text-align: left;">Unità</th>
                <th style="{{ $th }} text-align: right;">Saldo iniziale</th>
                <th style="{{ $th }} text-align: right;">Quota consuntiva</th>
                @if($t['contributi'] !== 0)<th style="{{ $th }} text-align: right;">Già versato</th>@endif
                <th style="{{ $th }} text-align: right;">Versato nell'esercizio</th>
                <th style="{{ $th }} text-align: right;">Conguaglio</th>
                <th style="{{ $th }} text-align: center;">a</th>
            </tr></thead>
            <tbody>
            @forelse($rip['righe'] as $riga)
                <tr>
                    <td style="{{ $td }}">{{ $riga['nome'] }}</td>
                    <td style="{{ $td }} font-size: 6.5pt;">{{ implode(', ', array_map(fn ($u) => $u['nome'] . ($u['ruolo'] ? ' (' . ($etichettaRuolo[$u['ruolo']] ?? $u['ruolo']) . ')' : ''), $riga['unita'])) }}</td>
                    <td style="{{ $tdR }}">{{ $fmtV($riga['saldo_iniziale']) }}</td>
                    <td style="{{ $tdR }}">{{ $fmt($riga['quota']) }}</td>
                    @if($t['contributi'] !== 0)<td style="{{ $tdR }}">{{ $fmtV($riga['contributi']) }}</td>@endif
                    <td style="{{ $tdR }}">{{ $fmt($riga['versato']) }}</td>
                    <td style="{{ $tdR }} font-weight: bold;{{ $riga['conguaglio'] > 0 ? ' color: #b91c1c;' : ($riga['conguaglio'] < 0 ? ' color: #047857;' : '') }}">{{ $fmt(abs($riga['conguaglio'])) }}</td>
                    <td style="{{ $td }} text-align: center; font-size: 6.5pt;">{{ $riga['conguaglio'] > 0 ? 'debito' : ($riga['conguaglio'] < 0 ? 'credito' : '—') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="{{ $td }} color: #888; font-style: italic;">Nessuna spesa né versamento in questa gestione.</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr>
                <td style="{{ $tot }}" colspan="2">Totale</td>
                <td style="{{ $totR }}">{{ $fmt($t['saldo_iniziale']) }}</td>
                <td style="{{ $totR }}">{{ $fmt($t['ripartito']) }}</td>
                @if($t['contributi'] !== 0)<td style="{{ $totR }}">{{ $fmt($t['contributi']) }}</td>@endif
                <td style="{{ $totR }}">{{ $fmt($t['versato']) }}</td>
                <td style="{{ $totR }}">{{ $fmt($t['conguaglio']) }}</td>
                <td style="{{ $tot }}"></td>
            </tr></tfoot>
        </table>
        <div style="font-size: 7pt; margin-top: 3px;">
            Conguagli a debito: <strong>{{ $fmt($t['a_debito']) }}</strong> · a credito: <strong>{{ $fmt(abs($t['a_credito'])) }}</strong>
            · Spese da ripartire {{ $fmt($t['spese']) }}, ripartite {{ $fmt($t['ripartito']) }}@if($t['non_ripartito'] !== 0)<span style="color: #b91c1c;"> — NON RIPARTITE {{ $fmt($t['non_ripartito']) }}</span>@endif.
        </div>

        {{-- Dettaglio della quota per capitolo, a blocchi di colonne --}}
        @if(count($rip['colonne']) > 0 && count($rip['righe']) > 0)
            @foreach(array_chunk($rip['colonne'], 8) as $blocco)
                <h3 style="{{ $h3 }}">Dettaglio della quota consuntiva per capitolo{{ count($rip['colonne']) > 8 ? ' (' . $loop->iteration . '/' . ceil(count($rip['colonne']) / 8) . ')' : '' }}</h3>
                <table style="width: 100%; border-collapse: collapse; font-size: 6.8pt;">
                    <thead><tr>
                        <th style="{{ $th }} text-align: left;">Condòmino</th>
                        @foreach($blocco as $col)<th style="{{ $th }} text-align: right;">{{ $col['nome'] }}</th>@endforeach
                        @if($loop->last)<th style="{{ $th }} text-align: right; background-color: #c8d8ee;">Totale</th>@endif
                    </tr></thead>
                    <tbody>
                    @foreach($rip['righe'] as $riga)
                        <tr>
                            <td style="{{ $td }}">{{ $riga['nome'] }}</td>
                            @foreach($blocco as $col)<td style="{{ $tdR }}">{{ $fmtV($riga['per_capitolo'][$col['chiave']] ?? 0) }}</td>@endforeach
                            @if($loop->parent->last)<td style="{{ $tdR }} font-weight: bold;">{{ $fmt($riga['quota']) }}</td>@endif
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr>
                        <td style="{{ $tot }}">Totale</td>
                        @foreach($blocco as $col)<td style="{{ $totR }}">{{ $fmt($col['totale']) }}</td>@endforeach
                        @if($loop->last)<td style="{{ $totR }}">{{ $fmt($t['ripartito']) }}</td>@endif
                    </tr></tfoot>
                </table>
            @endforeach
        @endif
    @endforeach
@endif

{{-- ═══ 5. NOTA SINTETICA ═════════════════════════════════════════════════════════════ --}}
@if($mostra('nota'))
    @if(! $primaPagina)<pagebreak orientation="P" />@endif
    @php $primaPagina = false; $orient = 'P'; @endphp
    <h2 style="{{ $h2 }}">5. Nota sintetica esplicativa della gestione</h2>
    <div style="font-size: 8.5pt; line-height: 1.5;">
        @foreach(preg_split('/\R{2,}/', trim($nota['compilata'])) as $paragrafo)
            @php $righeParagrafo = preg_split('/\R/', $paragrafo); $primaRiga = $righeParagrafo[0] ?? ''; @endphp
            @if(count($righeParagrafo) > 1 && $primaRiga === mb_strtoupper($primaRiga) && mb_strlen($primaRiga) < 80 && preg_match('/\p{L}/u', $primaRiga))
                <div style="font-weight: bold; color: {{ $navy }}; margin-top: 8px;">{{ $primaRiga }}</div>
                <div style="margin-bottom: 4px;">{!! nl2br(e(implode("\n", array_slice($righeParagrafo, 1)))) !!}</div>
            @else
                <div style="margin: 6px 0;">{!! nl2br(e($paragrafo)) !!}</div>
            @endif
        @endforeach
    </div>
@endif

{{-- ═══ VERIFICHE ═════════════════════════════════════════════════════════════════════ --}}
@if($tutto)
    <pagebreak orientation="P" />
    <h2 style="{{ $h2 }}">Verifiche interne del programma</h2>
    <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
        <thead><tr>
            <th style="{{ $th }} text-align: left; width: 32%;">Verifica</th>
            <th style="{{ $th }} text-align: center; width: 10%;">Esito</th>
            <th style="{{ $th }} text-align: left;">Dettaglio</th>
        </tr></thead>
        <tbody>
        @foreach($r['verifiche'] as $v)
            <tr>
                <td style="{{ $td }}">{{ $v['titolo'] }}</td>
                <td style="{{ $td }} text-align: center; font-weight: bold; color: {{ $v['esito'] === 'ok' ? '#047857' : ($v['esito'] === 'avviso' ? '#b45309' : '#b91c1c') }};">{{ strtoupper($v['esito']) }}</td>
                <td style="{{ $td }}">{{ $v['dettaglio'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="margin-top: 30px; font-size: 8.5pt;">
        <table style="width: 100%;"><tr>
            <td style="width: 50%;">Luogo e data ______________________</td>
            <td style="width: 50%; text-align: right;">L'amministratore ______________________</td>
        </tr></table>
    </div>
@endif

@endsection
