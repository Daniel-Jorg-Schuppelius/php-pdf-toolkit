<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BarcodeHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\Data\XmlHelper;
use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * Strichcodes und QR-Codes lesen (zbarimg aus zbar-tools): auf einem Bild
 * oder auf den gerasterten Seiten einer PDF. Trennblätter mit Barcode sind
 * der Scan-Standard in Kanzleien; der Aufteiler trennt daran.
 */
final class BarcodeHelper {
    use ErrorLog;

    /** Auflösung der Seitenraster: grob genug für Tempo, fein genug für Code 128 und QR */
    public const PAGE_DPI = 200;

    /**
     * Codes auf einem Bild.
     *
     * @return list<array{type: string, data: string}> leer ohne Code oder bei Fehler
     */
    public static function scanImage(string $imagePath): array {
        $config = Config::getInstance();
        if (!$config->isExecutableAvailable('zbarimg')) {
            self::logError('zbarimg ist nicht konfiguriert oder nicht verfügbar');
            return [];
        }
        if (!File::exists($imagePath)) {
            self::logError('Bild fehlt', ['path' => $imagePath]);
            return [];
        }
        $command = $config->buildCommand('zbarimg', ['[IMAGE]' => $imagePath]);
        if ($command === null) {
            self::logError('Konnte zbarimg-Befehl nicht erstellen');
            return [];
        }

        $output = [];
        $returnCode = 0;
        // zbarimg endet mit 4, wenn es keinen Code findet (zbar 0.23, Ubuntu wie Debian) -
        // das ist kein Fehler: die Shell macht daraus 0, jeder andere Code bleibt ein Fehler
        if (!Shell::executeShellCommand($command . ' 2>/dev/null || [ $? -eq 4 ]', $output, $returnCode, timeout: ToolTimeout::PAGE)) {
            self::logError('zbarimg fehlgeschlagen', ['returnCode' => $returnCode, 'output' => implode("\n", $output)]);
            return [];
        }

        return self::parseXml(implode("\n", $output));
    }

    /**
     * Codes je Seite einer PDF; Seiten ohne Code fehlen im Ergebnis.
     *
     * Gelesen wird seit 0.32.1 nur die sichtbare Seite (CropBox): Ein Code
     * außerhalb, den kein Betrachter zeigt, zählt nicht. Die Pixelgrenze von
     * {@see PDFPageHelper::renderPages()} gilt für den ganzen Seitenbereich -
     * eine übergroße Seite darin senkt die Auflösung aller.
     *
     * @param list<int>|null $pages Seiten (1-basiert); null = alle
     * @return array<int, list<array{type: string, data: string}>>
     */
    public static function scanPages(string $pdfPath, ?array $pages = null, int $dpi = self::PAGE_DPI): array {
        $pageCount = PDFHelper::getPageCount($pdfPath);
        if ($pageCount < 1) {
            self::logError('Seitenzahl der PDF nicht lesbar', ['path' => $pdfPath]);
            return [];
        }
        $first = $pages === null || $pages === [] ? 1 : max(1, min($pages));
        $last = $pages === null || $pages === [] ? $pageCount : min($pageCount, max($pages));
        $wanted = $pages === null ? null : array_flip($pages);

        $dir = TempPath::directory('barcode');
        try {
            $rendered = PDFPageHelper::renderPages($pdfPath, $dir, 'png', $dpi, $first, $last, cropBox: true);
            $found = [];
            foreach ($rendered as $index => $image) {
                $page = $first + $index;
                if ($wanted !== null && !isset($wanted[$page])) {
                    continue;
                }
                $codes = self::scanImage($image);
                if ($codes !== []) {
                    $found[$page] = $codes;
                }
            }
        } finally {
            Folder::delete($dir, true);
        }

        self::logInfo('Barcodes gelesen', ['input' => $pdfPath, 'pagesWithCodes' => count($found)]);

        return $found;
    }

    public static function isAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('zbarimg');
    }

    /**
     * Liest die XML-Ausgabe von zbarimg --xml.
     *
     * Seit 0.32.1 mit einem XML-Parser statt regulärer Ausdrücke. Vorher ergab
     * ein Code, dessen Inhalt wie XML aussieht ("</data><symbol type='QR-Code'>
     * <data>BCD…"), einen zweiten, erfundenen Code, und ein Symbol mit
     * base64-Daten bekam die Daten des nächsten Symbols. zbar schreibt die Daten
     * als CDATA und base64-kodiert (format='base64', length = Bytes), sobald sie
     * Steuer- oder C1-Bytes enthalten - darunter die UTF-8-Folgebytes von Ä, Ö,
     * Ü, ß und € -, "]]>" oder einen XML-Kopf; solche Daten kommen dekodiert
     * zurück. Leerraum am Rand fällt wie bisher weg. Geladen wird ohne Netz und
     * ohne Entitäten ({@see XmlHelper::safeLoadString()}); eine Ausgabe mit
     * DOCTYPE schreibt zbarimg nie, sie wird verworfen.
     *
     * @return list<array{type: string, data: string}> leer ohne Code oder bei unlesbarer Ausgabe
     */
    public static function parseXml(string $xml): array {
        if (trim($xml) === '') {
            return [];
        }
        // zbarimg setzt den Dateinamen ungeschützt in <source href='...'> (zbar 0.23):
        // ein & oder ' im Pfad machte das XML ungültig. Gebraucht wird der Name nicht.
        $xml = (string) preg_replace("/\\A(\\s*<barcodes\\b[^>]*>\\s*)<source\\s+href='[^\\n]*'>/", '$1<source>', $xml, 1);
        // Ungültiges UTF-8 (Bytes ab 0xA0 lässt zbar als Text durch) bräche das Laden ab
        $xml = mb_scrub($xml, 'UTF-8');

        $root = XmlHelper::safeLoadString($xml);
        if ($root === false) {
            return self::logWarningAndReturn([], 'Ausgabe von zbarimg ist kein lesbares XML');
        }
        if (dom_import_simplexml($root)->ownerDocument?->doctype !== null) {
            return self::logWarningAndReturn([], 'Ausgabe von zbarimg mit DOCTYPE verworfen');
        }

        $codes = [];
        // Mit und ohne Namensraum (zbar: http://zbar.sourceforge.net/2008/barcode)
        foreach ($root->xpath('//*[local-name()="symbol"]') ?: [] as $symbol) {
            $type = trim((string) $symbol['type']);
            $data = ($symbol->xpath('./*[local-name()="data"]') ?: [])[0] ?? null;
            if ($type === '' || $data === null) {
                continue;
            }

            $text = (string) $data;
            if (strtolower(trim((string) $data['format'])) === 'base64') {
                $decoded = base64_decode((string) preg_replace('/\s+/', '', $text), true);
                $length = trim((string) $data['length']);
                if ($decoded === false || ($length !== '' && $length !== (string) strlen($decoded))) {
                    self::logWarning('zbarimg: base64-Daten eines Codes nicht lesbar, Code übergangen', ['type' => $type]);
                    continue;
                }
                $text = $decoded;
            }
            $codes[] = ['type' => $type, 'data' => trim($text)];
        }

        return $codes;
    }
}
