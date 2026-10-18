<?php

/**
 * Prepara la cache dei font di mPDF durante la costruzione dell'immagine.
 *
 * mPDF, la prima volta che usa un font, ne analizza il file TTF e salva metriche e tabelle
 * OpenType in `vendor/mpdf/mpdf/tmp/mpdf/ttfontdata`. Quella cartella vive nel container:
 * a ogni aggiornamento (Watchtower ricrea il container dalla nuova immagine) si riparte da
 * vuoto e la prima stampa di ogni font paga la ricostruzione, ~100 ms su un Ryzen 3900X e
 * parecchio di più sulla CPU del NAS. Generandola qui, l'immagine arriva con la cache pronta.
 *
 * Il contenuto dei PDF non cambia: sono gli stessi file che mPDF scriverebbe alla prima stampa,
 * con le stesse impostazioni predefinite di `App\Services\PDF\PdfService` (font DejaVu, OTL
 * attivo come da configurazione di mPDF). Se un giorno la cache non servisse più o fosse di un
 * altro formato, mPDF la rigenera da sé: controlla dimensione del font e opzioni a ogni uso.
 *
 * Uso (nel Dockerfile, come www-data): php mpdf-warmup.php [percorso di vendor/autoload.php]
 */

require $argv[1] ?? '/var/www/vendor/autoload.php';

// I font che le stampe usano davvero: `dejavusans` è il predefinito di PdfService e di
// `pdf/styles.blade.php`; `monospace` nelle viste diventa `dejavusansmono` (anche nel modello F24).
// Tutte e quattro le varianti di ciascuno, così non resta nulla da costruire alla prima stampa.
$html = '';
foreach (['dejavusans', 'dejavusansmono'] as $famiglia) {
    foreach (['normal', 'bold'] as $peso) {
        foreach (['normal', 'italic'] as $stile) {
            $html .= sprintf(
                '<p style="font-family: %s; font-weight: %s; font-style: %s;">Àèéìòù € 1.234,56 — fi ffi · • ▲▼ ✉</p>',
                $famiglia,
                $peso,
                $stile
            );
        }
    }
}

$mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'dejavusans']);
$mpdf->WriteHTML($html);
$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

$cartella = (new \Mpdf\Config\ConfigVariables())->getDefaults()['tempDir'] . '/mpdf/ttfontdata';
$file = glob($cartella . '/*') ?: [];
fwrite(STDOUT, sprintf("[mpdf-warmup] cache dei font pronta: %d file in %s\n", count($file), $cartella));
exit(count($file) > 0 ? 0 : 1);
