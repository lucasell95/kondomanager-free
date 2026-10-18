@extends('pdf.base')

@section('title', 'Foglio presenze')

@section('content')
@php
    $data = $assemblea->dataSvolgimento();
@endphp

<h1>Foglio presenze</h1>

<p>
    {{ $assemblea->titoloLeggibile() }} —
    {{ $assemblea->convocazioneEffettiva()->label() }}
    @if($data) del {{ $data->format('d/m/Y') }} ore {{ $data->format('H:i') }}@endif
    @if($assemblea->luogoSvolgimento()) — {{ $assemblea->luogoSvolgimento() }}@endif
</p>

<table class="table-report" style="width: 100%;">
    <thead>
        <tr>
            <th style="width: 34%;">Condòmino (unità)</th>
            <th style="width: 12%; text-align: right;">Millesimi</th>
            <th style="width: 24%;">Presente / delega a</th>
            <th style="width: 10%;">Ora</th>
            <th style="width: 20%;">Firma</th>
        </tr>
    </thead>
    <tbody>
        @foreach($apertura['teste'] as $testa)
            <tr>
                <td>{{ $testa['etichetta'] }}</td>
                <td style="text-align: right;">{{ $testa['valore_testo'] }}</td>
                <td>
                    @if($testa['presente'])
                        {{ $testa['rappresentante'] ?? 'in proprio' }}
                    @endif
                </td>
                <td></td>
                <td style="height: 22px;"></td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td><strong>Totale</strong> ({{ $apertura['teste_totali'] }} condòmini)</td>
            <td style="text-align: right;"><strong>{{ $apertura['valore_totale_testo'] }}</strong></td>
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>

@if($apertura['teste_presenti'] > 0)
    <div class="section" style="margin-top: 12px;">
        <div class="section-title">Quorum costitutivo</div>
        <p>{!! nl2br(e($apertura['spiegazione'])) !!}</p>
    </div>
@endif

<div class="section" style="margin-top: 12px;">
    <div class="section-title">Persone titolari e deleghe registrate</div>
    <table class="table-report" style="width: 100%;">
        <thead>
            <tr><th>Persona</th><th>Unità</th><th>Ruolo</th><th>Presenza</th></tr>
        </thead>
        <tbody>
            @foreach($persone as $persona)
                <tr>
                    <td>{{ $persona['nome'] }}</td>
                    <td>{{ implode(', ', $persona['unita']) }}</td>
                    <td>{{ implode(', ', $persona['ruoli']) }}</td>
                    <td>
                        @if($persona['modo'] === 'presente') presente @if($persona['ora_ingresso']) (ore {{ $persona['ora_ingresso'] }})@endif
                        @elseif($persona['modo'] === 'delegato') per delega a {{ $persona['delegato'] }}
                        @elseif($persona['modo'] === 'assente') assente
                        @else &nbsp;
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
