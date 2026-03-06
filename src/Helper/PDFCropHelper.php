<?php
/*
 * Created on   : Thu Mar 06 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFCropHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use PDFToolkit\Config\Config;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Helper-Klasse für PDF-Zuschnitt (Cropping).
 * 
 * Nutzt Ghostscript um PDF-Seiten auf definierte Bereiche zuzuschneiden.
 * Typische Anwendung: Versandetiketten aus A4-Seiten extrahieren.
 */
final class PDFCropHelper {
    use ErrorLog;

    /**
     * Schneidet eine PDF-Seite auf einen definierten Bereich zu.
     * 
     * Die CropBox wird in PDF-Punkten (1pt = 1/72 Zoll) angegeben.
     * Koordinatenursprung ist unten links.
     * 
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param float $x Linke Kante in Punkten
     * @param float $y Untere Kante in Punkten
     * @param float $width Breite in Punkten
     * @param float $height Höhe in Punkten
     * @param int $page Seitennummer (1-basiert, 0 = alle Seiten)
     * @return bool true bei Erfolg
     */
    public static function cropToBox(
        string $inputPath,
        string $outputPath,
        float $x,
        float $y,
        float $width,
        float $height,
        int $page = 1
    ): bool {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return false;
        }

        if (!self::isAvailable()) {
            self::logError('Ghostscript (gs-crop) ist nicht konfiguriert oder nicht verfügbar');
            return false;
        }

        // PostScript-Befehl für PageOffset
        $postscript = sprintf(
            '<</PageOffset [%s %s]>> setpagedevice',
            number_format(-$x, 2, '.', ''),
            number_format(-$y, 2, '.', '')
        );

        // Seitenauswahl aufbauen (leere Platzhalter werden vom CommandBuilder übersprungen)
        $firstPage = $page > 0 ? sprintf('-dFirstPage=%d', $page) : '';
        $lastPage = $page > 0 ? sprintf('-dLastPage=%d', $page) : '';

        $config = Config::getInstance();
        $command = $config->buildCommand('gs-crop', [
            '[WIDTH]' => number_format($width, 2, '.', ''),
            '[HEIGHT]' => number_format($height, 2, '.', ''),
            '[FIRST-PAGE]' => $firstPage,
            '[LAST-PAGE]' => $lastPage,
            '[OUTPUT]' => $outputPath,
            '[POSTSCRIPT]' => $postscript,
            '[INPUT]' => $inputPath,
        ]);

        if ($command === null) {
            self::logError('Konnte gs-crop Befehl nicht erstellen');
            return false;
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode) || $returnCode !== 0) {
            self::logError('Ghostscript-Cropping fehlgeschlagen', [
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return false;
        }

        if (!File::exists($outputPath)) {
            self::logError('Cropped PDF wurde nicht erstellt', ['path' => $outputPath]);
            return false;
        }

        self::logInfo('PDF erfolgreich zugeschnitten', [
            'input' => $inputPath,
            'output' => $outputPath,
            'box' => sprintf('%.0f,%.0f %.0fx%.0f', $x, $y, $width, $height),
        ]);

        return true;
    }

    /**
     * Schneidet die obere Hälfte einer PDF-Seite aus.
     * 
     * Typisch für DHL-Paketmarken: Etikett oben, Kundeninfo unten.
     * Nutzt die tatsächlichen Seitenmaße der PDF.
     * 
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param int $page Seitennummer (1-basiert)
     * @return bool true bei Erfolg
     */
    public static function cropUpperHalf(string $inputPath, string $outputPath, int $page = 1): bool {
        return self::cropUpperPercent($inputPath, $outputPath, 50.0, $page);
    }

    /**
     * Schneidet die untere Hälfte einer PDF-Seite aus.
     * 
     * Nutzt die tatsächlichen Seitenmaße der PDF.
     * 
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param int $page Seitennummer (1-basiert)
     * @return bool true bei Erfolg
     */
    public static function cropLowerHalf(string $inputPath, string $outputPath, int $page = 1): bool {
        return self::cropLowerPercent($inputPath, $outputPath, 50.0, $page);
    }

    /**
     * Schneidet den oberen Bereich einer Seite mit prozentualer Angabe aus.
     * 
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param float $percent Prozent der Seite von oben (z.B. 50.0 = obere Hälfte)
     * @param int $page Seitennummer (1-basiert)
     * @return bool true bei Erfolg
     */
    public static function cropUpperPercent(
        string $inputPath,
        string $outputPath,
        float $percent,
        int $page = 1
    ): bool {
        $dimensions = self::getPageDimensions($inputPath);
        if ($dimensions === null) {
            return false;
        }

        $cropHeight = $dimensions['height'] * ($percent / 100);
        $yOffset = $dimensions['height'] - $cropHeight;

        return self::cropToBox($inputPath, $outputPath, 0, $yOffset, $dimensions['width'], $cropHeight, $page);
    }

    /**
     * Schneidet den unteren Bereich einer Seite mit prozentualer Angabe aus.
     * 
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param float $percent Prozent der Seite von unten (z.B. 50.0 = untere Hälfte)
     * @param int $page Seitennummer (1-basiert)
     * @return bool true bei Erfolg
     */
    public static function cropLowerPercent(
        string $inputPath,
        string $outputPath,
        float $percent,
        int $page = 1
    ): bool {
        $dimensions = self::getPageDimensions($inputPath);
        if ($dimensions === null) {
            return false;
        }

        $cropHeight = $dimensions['height'] * ($percent / 100);

        return self::cropToBox($inputPath, $outputPath, 0, 0, $dimensions['width'], $cropHeight, $page);
    }

    /**
     * Ermittelt die Seitenmaße einer PDF in Punkten.
     * 
     * @return array{width: float, height: float}|null null bei Fehler
     */
    private static function getPageDimensions(string $inputPath): ?array {
        $metadata = PDFHelper::getMetadata($inputPath);
        if (empty($metadata['Page size'])) {
            self::logError('Konnte Seitengröße nicht ermitteln', ['path' => $inputPath]);
            return null;
        }

        if (!preg_match('/(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)/', $metadata['Page size'], $matches)) {
            self::logError('Seitengröße konnte nicht geparst werden', ['pageSize' => $metadata['Page size']]);
            return null;
        }

        return [
            'width' => (float) $matches[1],
            'height' => (float) $matches[2],
        ];
    }

    /**
     * Prüft ob Ghostscript für Cropping verfügbar ist.
     */
    public static function isAvailable(): bool {
        $config = Config::getInstance();
        return $config->isExecutableAvailable('gs-crop');
    }
}
