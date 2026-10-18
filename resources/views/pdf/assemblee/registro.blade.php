@extends('pdf.base')

@section('title', 'Registro dei verbali')

@section('content')
<h1>Registro dei verbali delle assemblee</h1>
<p style="color: #555;">Art. 1130 n. 7 c.c. — {{ $condominio->nome }}</p>

@if($verbali->isEmpty())
    <p>Nessun verbale registrato.</p>
@else
    <table class="table-report" style="width: 100%;">
        <thead>
            <tr>
                <th style="width: 10%;">N.</th>
                <th style="width: 14%;">Data</th>
                <th style="width: 22%;">Assemblea</th>
                <th>Punti all'ordine del giorno</th>
                <th style="width: 14%;">Registrato il</th>
            </tr>
        </thead>
        <tbody>
            @foreach($verbali as $v)
                <tr>
                    <td>{{ $v->numeroRegistro() }}</td>
                    <td>{{ $v->dataSvolgimento()?->format('d/m/Y') }}</td>
                    <td>{{ $v->titoloLeggibile() }}</td>
                    <td>
                        @foreach($v->punti as $p){{ $loop->iteration }}. {{ $p->titolo }}@if(! $loop->last)<br>@endif @endforeach
                    </td>
                    <td>{{ $v->verbale_registrato_at?->format('d/m/Y') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
@endsection
