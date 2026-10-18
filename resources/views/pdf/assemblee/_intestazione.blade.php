<div style="margin-bottom: 12px;">
    <div style="font-size: 12pt; font-weight: bold; color: #1e3a5f;">{{ $condominio->nome }}</div>
    @if($condominio->indirizzo)
        <div style="color: #555;">{{ $condominio->indirizzo }}</div>
    @endif
    @if($condominio->codice_fiscale)
        <div style="color: #555;">C.F. {{ $condominio->codice_fiscale }}</div>
    @endif
</div>
