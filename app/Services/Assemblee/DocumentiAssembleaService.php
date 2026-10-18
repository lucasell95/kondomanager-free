<?php

namespace App\Services\Assemblee;

use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Services\PDF\PdfService;
use Mpdf\Mpdf;

/**
 * Le stampe del modulo: avviso di convocazione, foglio presenze, verbale, lettera agli assenti,
 * registro dei verbali. Tutte con `PdfService` e le viste in `resources/views/pdf/assemblee`.
 *
 * Restituiscono il PDF come stringa: lo usano sia i controller (download) sia il registro
 * (archiviazione nel modulo Documenti).
 */
class DocumentiAssembleaService
{
    public function __construct(
        private PdfService $pdf,
        private RiepilogoAssembleaService $riepilogo,
        private TesteAssembleaService $teste,
    ) {}

    public function avviso(Assemblea $assemblea): string
    {
        $assemblea->loadMissing(['condominio', 'punti', 'destinatari']);

        return $this->genera('pdf.assemblee.avviso', [
            'condominio' => $assemblea->condominio,
            'assemblea' => $assemblea,
            'destinatari' => $assemblea->destinatari,
        ], 'Avviso di convocazione');
    }

    public function foglioPresenze(Assemblea $assemblea): string
    {
        $assemblea->loadMissing('condominio');
        $apertura = $this->riepilogo->costitutivo($assemblea);
        $presenze = $this->teste->presenze($assemblea);

        $persone = collect($this->teste->persone($assemblea))->map(function ($persona) use ($presenze) {
            $p = $presenze->get($persona['anagrafica_id']);

            return $persona + [
                'modo' => $p?->modo?->value,
                'delegato' => $p?->nomeDelegato(),
                'ora_ingresso' => $p?->ora_ingresso,
            ];
        });

        return $this->genera('pdf.assemblee.presenze', [
            'condominio' => $assemblea->condominio,
            'assemblea' => $assemblea,
            'apertura' => $apertura,
            'persone' => $persone,
        ], 'Foglio presenze');
    }

    public function verbale(Assemblea $assemblea): string
    {
        $assemblea->loadMissing('condominio');

        return $this->genera('pdf.assemblee.verbale', [
            'condominio' => $assemblea->condominio,
            'assemblea' => $assemblea,
            'scadenzaPresenti' => $this->riepilogo->scadenzaPresenti($assemblea),
        ] + $this->riepilogo->perVerbale($assemblea), 'Verbale di assemblea');
    }

    public function letteraAssenti(Assemblea $assemblea): string
    {
        $assemblea->loadMissing('condominio');

        return $this->genera('pdf.assemblee.lettera_assenti', [
            'condominio' => $assemblea->condominio,
            'assemblea' => $assemblea,
            'assenti' => $this->riepilogo->assenti($assemblea),
        ], 'Comunicazione del verbale');
    }

    public function registro(Condominio $condominio): string
    {
        $verbali = $condominio->assemblee()
            ->whereNotNull('numero_verbale')
            ->with('punti')
            ->orderBy('anno_verbale')
            ->orderBy('numero_verbale')
            ->get();

        return $this->genera('pdf.assemblee.registro', [
            'condominio' => $condominio,
            'verbali' => $verbali,
        ], 'Registro dei verbali');
    }

    private function genera(string $vista, array $dati, string $titolo): string
    {
        $mpdf = $this->pdf->generate($vista, $dati, ['orientation' => 'P', 'margin_top' => 32]);

        if (isset($dati['condominio'])) {
            $mpdf->SetHeader($dati['condominio']->nome.'||'.$titolo);
        }

        return $mpdf->Output('', 'S');
    }
}
