<?php

namespace App\Services\Assemblee;

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\CategoriaDocumento;
use App\Models\Documento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Registro dei verbali (art. 1130 n. 7 c.c.).
 *
 * Il verbale riceve un numero progressivo per condominio e anno («3/2026»), e il PDF — quello
 * firmato caricato dall'utente, o in mancanza quello generato — entra nell'archivio Documenti nella
 * categoria «Verbali», collegato all'assemblea (`documentable`). Da lì in poi l'assemblea non si
 * modifica: il registro non si riscrive.
 */
class RegistroVerbaliService
{
    public const CATEGORIA = 'Verbali';

    public function __construct(private DocumentiAssembleaService $documenti) {}

    public function prossimoNumero(int $condominioId, int $anno): int
    {
        return (int) Assemblea::where('condominio_id', $condominioId)
            ->where('anno_verbale', $anno)
            ->max('numero_verbale') + 1;
    }

    public function registra(Assemblea $assemblea, int $userId, ?UploadedFile $firmato = null, bool $pubblica = false): Assemblea
    {
        return DB::transaction(function () use ($assemblea, $userId, $firmato, $pubblica) {
            // Rilettura con blocco: due registrazioni contemporanee non devono prendere lo stesso numero.
            Assemblea::where('condominio_id', $assemblea->condominio_id)->lockForUpdate()->get(['id']);

            $anno = (int) ($assemblea->dataSvolgimento() ?? now())->format('Y');
            $numero = $this->prossimoNumero($assemblea->condominio_id, $anno);

            $assemblea->forceFill([
                'numero_verbale' => $numero,
                'anno_verbale' => $anno,
                'verbale_registrato_at' => now(),
                'stato' => StatoAssemblea::VERBALIZZATA,
                'updated_by' => $userId,
            ]);

            if ($firmato) {
                $percorso = $firmato->storeAs('documenti', $firmato->hashName(), 'local');
                $mime = $firmato->getClientMimeType() ?: 'application/pdf';
                $dimensione = $firmato->getSize();
            } else {
                $contenuto = $this->documenti->verbale($assemblea);
                $percorso = 'documenti/'.Str::random(40).'.pdf';
                Storage::disk('local')->put($percorso, $contenuto);
                $mime = 'application/pdf';
                $dimensione = strlen($contenuto);
            }

            $categoria = CategoriaDocumento::firstOrCreate(
                ['name' => self::CATEGORIA],
                ['description' => 'Verbali delle assemblee e riunioni']
            );

            $documento = Documento::create([
                'name' => 'Verbale '.$numero.'/'.$anno.' - '.$assemblea->titoloLeggibile(),
                'description' => 'Registro dei verbali, n. '.$numero.'/'.$anno.'. '
                    .($firmato ? 'Copia firmata caricata.' : 'Copia generata dal programma, da firmare.'),
                'path' => $percorso,
                'mime_type' => $mime,
                'file_size' => $dimensione,
                'created_by' => $userId,
                'category_id' => $categoria->id,
                'is_published' => $pubblica,
                'is_approved' => true,
            ]);
            $documento->forceFill([
                'documentable_type' => Assemblea::class,
                'documentable_id' => $assemblea->id,
            ])->save();
            $documento->condomini()->attach($assemblea->condominio_id);

            $assemblea->forceFill(['documento_verbale_id' => $documento->id])->save();

            return $assemblea;
        });
    }
}
