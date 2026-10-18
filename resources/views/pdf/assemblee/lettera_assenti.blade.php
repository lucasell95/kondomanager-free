@extends('pdf.base')

@section('title', 'Comunicazione del verbale')

@section('content')
@php
    $data = $assemblea->dataSvolgimento();
    $riceventi = count($assenti) > 0 ? $assenti : [null];
@endphp

@foreach($riceventi as $assente)
    @if(! $loop->first)
        <pagebreak />
    @endif

    @include('pdf.assemblee._intestazione')

    <table style="width: 100%; margin-bottom: 14px;">
        <tr>
            <td style="width: 50%;"></td>
            <td style="width: 50%;">
                @if($assente)
                    Spett.le <strong>{{ $assente['nome'] }}</strong><br>
                    <span style="color: #555;">{{ implode(', ', $assente['unita']) }}</span>
                @else
                    Ai signori condòmini assenti
                @endif
            </td>
        </tr>
    </table>

    <h2>Oggetto: comunicazione del verbale dell'assemblea del {{ $data?->format('d/m/Y') }}</h2>

    <p>
        Si trasmette in allegato copia del verbale dell'assemblea {{ mb_strtolower($assemblea->tipo?->label() ?? 'ordinaria') }}
        dei condòmini del {{ $condominio->nome }} tenutasi il {{ $data?->copy()->locale('it')->translatedFormat('l j F Y') }}
        @if($assemblea->numeroRegistro()), iscritto al n. {{ $assemblea->numeroRegistro() }} del registro dei verbali @endif.
    </p>

    <p>
        Ai sensi dell'art. 1137 c.c., il condòmino assente può impugnare le delibere entro trenta giorni dalla data
        di ricezione della presente comunicazione.
    </p>

    <p style="margin-top: 24px;">
        {{ now()->format('d/m/Y') }}
        <span style="float: right;">{{ $assemblea->amministratore_nome ?: ($assemblea->convocante ?: "L'amministratore") }}</span>
    </p>
@endforeach
@endsection
