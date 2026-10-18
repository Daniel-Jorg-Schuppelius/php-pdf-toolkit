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
            $rendered = PDFPageHelper::renderPages($pdfPath, $dir, 'png', $dpi, $first, $last);
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
     * @return list<array{type: string, data: string}>
     */
    public static function parseXml(string $xml): array {
        $codes = [];
        // zbarimg schreibt Attribute in einfachen Anfuehrungszeichen, aeltere Fassungen in doppelten
        // zwischen <symbol> und <data> steht bei neueren Fassungen ein <polygon/>
        if (preg_match_all('#<symbol\s+type=["\']([^"\']+)["\'].*?<data>(.*?)</data>#s', $xml, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }
        foreach ($matches as $m) {
            $data = $m[2];
            if (preg_match('/^<!\[CDATA\[(.*)\]\]>$/s', trim($data), $cdata) === 1) {
                $data = $cdata[1];
            } else {
                $data = html_entity_decode($data, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $codes[] = ['type' => $m[1], 'data' => trim($data)];
        }

        return $codes;
    }
}
