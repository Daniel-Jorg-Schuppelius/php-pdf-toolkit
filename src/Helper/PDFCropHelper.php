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

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

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
        if (!Shell::executeShellCommand($command, $output, $returnCode) || $returnCode !== 0) {
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
     * Normalisiert ein Margin-Array nach CSS-Shorthand-Konvention.
     *
     * - 1 Wert:  [all]                     -> [all, all, all, all]
     * - 2 Werte: [top/bottom, left/right]   -> [top/bottom, left/right, top/bottom, left/right]
     * - 3 Werte: [top, left/right, bottom]  -> [top, left/right, bottom, left/right]
     * - 4 Werte: [top, right, bottom, left] -> [top, right, bottom, left]
     *
     * @param array<float> $margins 1–4 Werte (Einheit beliebig, z.B. cm oder pt)
     * @return array{0: float, 1: float, 2: float, 3: float} [top, right, bottom, left]
     */
    public static function normalizeMargins(array $margins): array {
        $margins = array_values($margins);

        return match (count($margins)) {
            1 => [$margins[0], $margins[0], $margins[0], $margins[0]],
            2 => [$margins[0], $margins[1], $margins[0], $margins[1]],
            3 => [$margins[0], $margins[1], $margins[2], $margins[1]],
            4 => [$margins[0], $margins[1], $margins[2], $margins[3]],
            default => [0.0, 0.0, 0.0, 0.0],
        };
    }

    /**
     * Schneidet den oberen Bereich einer Seite mit prozentualer Angabe aus.
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param float $percent Prozent der Seite von oben (z.B. 50.0 = obere Hälfte)
     * @param int $page Seitennummer (1-basiert)
     * @param array<float> $margins CSS-Shorthand-Margins in Punkten (1–4 Werte: top, right, bottom, left)
     * @return bool true bei Erfolg
     */
    public static function cropUpperPercent(
        string $inputPath,
        string $outputPath,
        float $percent,
        int $page = 1,
        array $margins = []
    ): bool {
        $dimensions = self::getPageDimensions($inputPath);
        if ($dimensions === null) {
            return false;
        }

        [$mTop, $mRight, $mBottom, $mLeft] = !empty($margins)
            ? self::normalizeMargins($margins)
            : [0.0, 0.0, 0.0, 0.0];

        $cropHeight = $dimensions['height'] * ($percent / 100);
        $yOffset = $dimensions['height'] - $cropHeight;

        $x = $mLeft;
        $y = $yOffset + $mBottom;
        $width = $dimensions['width'] - $mLeft - $mRight;
        $height = $cropHeight - $mTop - $mBottom;

        return self::cropToBox($inputPath, $outputPath, $x, $y, $width, $height, $page);
    }

    /**
     * Schneidet den unteren Bereich einer Seite mit prozentualer Angabe aus.
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param float $percent Prozent der Seite von unten (z.B. 50.0 = untere Hälfte)
     * @param int $page Seitennummer (1-basiert)
     * @param array<float> $margins CSS-Shorthand-Margins in Punkten (1–4 Werte: top, right, bottom, left)
     * @return bool true bei Erfolg
     */
    public static function cropLowerPercent(
        string $inputPath,
        string $outputPath,
        float $percent,
        int $page = 1,
        array $margins = []
    ): bool {
        $dimensions = self::getPageDimensions($inputPath);
        if ($dimensions === null) {
            return false;
        }

        [$mTop, $mRight, $mBottom, $mLeft] = !empty($margins)
            ? self::normalizeMargins($margins)
            : [0.0, 0.0, 0.0, 0.0];

        $cropHeight = $dimensions['height'] * ($percent / 100);

        $x = $mLeft;
        $y = $mBottom;
        $width = $dimensions['width'] - $mLeft - $mRight;
        $height = $cropHeight - $mTop - $mBottom;

        return self::cropToBox($inputPath, $outputPath, $x, $y, $width, $height, $page);
    }

    /**
     * Ermittelt die Seitenmaße einer PDF in Punkten.
     *
     * Berücksichtigt die PDF-Rotation: Bei Rotate 90° oder 270° werden
     * Breite und Höhe getauscht, sodass die effektiven Anzeigemaße zurückgegeben werden.
     *
     * @return array{width: float, height: float}|null null bei Fehler
     */
    public static function getPageDimensions(string $inputPath): ?array {
        $metadata = PDFHelper::getMetadata($inputPath);
        if (empty($metadata['Page size'])) {
            self::logError('Konnte Seitengröße nicht ermitteln', ['path' => $inputPath]);
            return null;
        }

        if (!preg_match('/(\d+(?:\.\d+)?)\s*x\s*(\d+(?:\.\d+)?)/', $metadata['Page size'], $matches)) {
            self::logError('Seitengröße konnte nicht geparst werden', ['pageSize' => $metadata['Page size']]);
            return null;
        }

        $width = (float) $matches[1];
        $height = (float) $matches[2];

        // Bei Rotation 90°/270° effektive Breite/Höhe tauschen
        $rotation = (int) ($metadata['Page rot'] ?? 0);
        if ($rotation === 90 || $rotation === 270) {
            [$width, $height] = [$height, $width];
        }

        return [
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Skaliert eine PDF-Seite auf eine Zielgröße (FitPage).
     *
     * Das bestehende PDF wird proportional auf die angegebene Größe skaliert.
     * 1 cm = 28.3465 PDF-Punkte (72/2.54).
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param float $widthPt Zielbreite in PDF-Punkten
     * @param float $heightPt Zielhöhe in PDF-Punkten
     * @return bool true bei Erfolg
     */
    public static function resizeToFit(string $inputPath, string $outputPath, float $widthPt, float $heightPt): bool {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return false;
        }

        $config = Config::getInstance();
        if (!$config->isExecutableAvailable('gs-resize')) {
            self::logError('Ghostscript (gs-resize) ist nicht konfiguriert oder nicht verfügbar');
            return false;
        }

        $command = $config->buildCommand('gs-resize', [
            '[WIDTH]' => number_format($widthPt, 2, '.', ''),
            '[HEIGHT]' => number_format($heightPt, 2, '.', ''),
            '[OUTPUT]' => $outputPath,
            '[INPUT]' => $inputPath,
        ]);

        if ($command === null) {
            self::logError('Konnte gs-resize Befehl nicht erstellen');
            return false;
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command, $output, $returnCode) || $returnCode !== 0) {
            self::logError('Ghostscript-Resize fehlgeschlagen', [
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return false;
        }

        if (!File::exists($outputPath)) {
            self::logError('Resized PDF wurde nicht erstellt', ['path' => $outputPath]);
            return false;
        }

        self::logInfo('PDF erfolgreich skaliert', [
            'input' => $inputPath,
            'output' => $outputPath,
            'targetSize' => sprintf('%.0fx%.0f pt', $widthPt, $heightPt),
        ]);

        return true;
    }

    /**
     * Skaliert eine PDF-Seite proportional auf eine Zielgröße und zentriert den Inhalt.
     *
     * Mehrstufiges Verfahren:
     * 1. Bei Orientierungs-Mismatch (Landscape->Portrait oder umgekehrt): Seite physisch rotieren
     * 2. resizeToFit() mit -dPDFFitPage skaliert korrekt (positioniert unten-links)
     * 3. PageOffset verschiebt den Inhalt zur Mitte (funktioniert zuverlässig mit pdfwrite)
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param float $widthPt Zielbreite in PDF-Punkten
     * @param float $heightPt Zielhöhe in PDF-Punkten
     * @return bool true bei Erfolg
     */
    public static function resizeToFitCentered(string $inputPath, string $outputPath, float $widthPt, float $heightPt): bool {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return false;
        }

        // Quelldimensionen für Offset-Berechnung
        $dims = self::getPageDimensions($inputPath);
        if ($dims === null) {
            return false;
        }

        // Bei Orientierungs-Mismatch: Seite physisch rotieren
        $sourceIsLandscape = $dims['width'] > $dims['height'];
        $targetIsLandscape = $widthPt > $heightPt;
        $rotatedTempPath = null;

        if ($sourceIsLandscape !== $targetIsLandscape) {
            $rotatedTempPath = $outputPath . '.rotated.tmp.pdf';
            if (!self::rotatePage($inputPath, $rotatedTempPath, 90)) {
                self::logWarning('Rotation fehlgeschlagen, fahre ohne Rotation fort');
            } else {
                $inputPath = $rotatedTempPath;
                $dims = self::getPageDimensions($inputPath);
                if ($dims === null) {
                    if (File::exists($rotatedTempPath)) {
                        File::delete($rotatedTempPath);
                    }
                    return false;
                }
            }
        }

        try {
            // Skalierungsfaktor und Zentrierungsoffset berechnen
            $scale = min($widthPt / $dims['width'], $heightPt / $dims['height']);
            $scaledW = $dims['width'] * $scale;
            $scaledH = $dims['height'] * $scale;
            $offsetX = ($widthPt - $scaledW) / 2;
            $offsetY = ($heightPt - $scaledH) / 2;

            // Kein Offset nötig -> einfach resizeToFit
            if ($offsetX < 1.0 && $offsetY < 1.0) {
                return self::resizeToFit($inputPath, $outputPath, $widthPt, $heightPt);
            }

            // Schritt 1: FitPage-Skalierung (positioniert unten-links)
            $tempPath = $outputPath . '.fitpage.tmp.pdf';
            if (!self::resizeToFit($inputPath, $tempPath, $widthPt, $heightPt)) {
                return false;
            }

            try {
                // Schritt 2: Mit PageOffset zentrieren
                // FitPage platziert den Inhalt am oberen Rand. Zum Zentrieren muss
                // er nach UNTEN verschoben werden -> negative Y-Offset-Werte.
                $postscript = sprintf(
                    '<</PageOffset [%s %s]>> setpagedevice',
                    number_format(-$offsetX, 4, '.', ''),
                    number_format(-$offsetY, 4, '.', '')
                );

                $config = Config::getInstance();
                $command = $config->buildCommand('gs-crop', [
                    '[WIDTH]' => number_format($widthPt, 2, '.', ''),
                    '[HEIGHT]' => number_format($heightPt, 2, '.', ''),
                    '[FIRST-PAGE]' => '',
                    '[LAST-PAGE]' => '',
                    '[OUTPUT]' => $outputPath,
                    '[POSTSCRIPT]' => $postscript,
                    '[INPUT]' => $tempPath,
                ]);

                if ($command === null) {
                    self::logError('Konnte Zentrierungs-Befehl nicht erstellen');
                    return false;
                }

                $output = [];
                $returnCode = 0;
                if (!Shell::executeShellCommand($command, $output, $returnCode) || $returnCode !== 0) {
                    self::logError('Ghostscript-Zentrierung fehlgeschlagen', [
                        'returnCode' => $returnCode,
                        'output' => implode("\n", $output),
                    ]);
                    return false;
                }

                if (!File::exists($outputPath)) {
                    self::logError('Zentrierte PDF wurde nicht erstellt', ['path' => $outputPath]);
                    return false;
                }

                self::logInfo('PDF proportional skaliert und zentriert', [
                    'input' => $inputPath,
                    'output' => $outputPath,
                    'scale' => round($scale, 4),
                    'offset' => sprintf('%.1f, %.1f', $offsetX, $offsetY),
                    'targetSize' => sprintf('%.0fx%.0f pt', $widthPt, $heightPt),
                ]);

                return true;
            } finally {
                if (File::exists($tempPath)) {
                    File::delete($tempPath);
                }
            }
        } finally {
            if ($rotatedTempPath !== null && File::exists($rotatedTempPath)) {
                File::delete($rotatedTempPath);
            }
        }
    }

    /**
     * Dreht alle Seiten einer PDF um den angegebenen Winkel (/Rotate).
     *
     * Läuft über das MuPDF-Skript page-arrange.js ({@see PDFPageHelper::rotate()});
     * der frühere Weg über qpdf drehte nur die erste Seite.
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param int $angle Rotationswinkel (90, 180, 270)
     * @return bool true bei Erfolg
     */
    public static function rotatePage(string $inputPath, string $outputPath, int $angle): bool {
        return PDFPageHelper::rotate($inputPath, $outputPath, $angle) !== null;
    }

    /**
     * Zerlegt die Seiten einer PDF in ein Raster aus gleich großen Teilen,
     * z.B. zwei Belege auf einer A4-Seite oder einen Bogen Visitenkarten.
     * Kein Teil wird verworfen.
     *
     * Jeder Teil wird eine eigene Seite, die den Inhalt der Originalseite
     * mitbenutzt und nur einen anderen Seitenrahmen bekommt: nichts wird neu
     * gerendert, Text bleibt Text, die Datei wächst kaum. Reihenfolge: Seite
     * für Seite, je Seite zeilenweise von oben nach unten und von links nach
     * rechts - so, wie die Seite angezeigt wird (/Rotate ist berücksichtigt).
     * Seiten außerhalb von $firstPage..$lastPage bleiben unverändert an ihrer
     * Stelle.
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param int $rows Zeilen je Seite
     * @param int $columns Spalten je Seite
     * @param int $firstPage Erste zu zerlegende Seite (1-basiert)
     * @param int $lastPage Letzte zu zerlegende Seite (0 = bis zur letzten)
     * @param array<float> $margins CSS-Shorthand-Margins in Punkten (1–4 Werte: top, right, bottom, left), in Anzeigeausrichtung
     * @return int|null Seitenzahl des Ergebnisses, null bei Fehler
     */
    public static function cropToGrid(
        string $inputPath,
        string $outputPath,
        int $rows,
        int $columns,
        int $firstPage = 1,
        int $lastPage = 0,
        array $margins = []
    ): ?int {
        $pages = self::runGridSplit($inputPath, $outputPath, $rows, $columns, $firstPage, $lastPage, $margins, false);
        if ($pages === null) {
            return null;
        }

        if (!File::exists($outputPath)) {
            self::logError('Zerlegte PDF wurde nicht erstellt', ['path' => $outputPath]);
            return null;
        }

        return $pages;
    }

    /**
     * Wie {@see cropToGrid()}, aber jede Seite des Ergebnisses wird eine
     * eigene Datei - auch die unverändert übernommenen Seiten außerhalb des
     * Seitenbereichs.
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputDir Verzeichnis für die Teile (wird angelegt)
     * @param int $rows Zeilen je Seite
     * @param int $columns Spalten je Seite
     * @param int $firstPage Erste zu zerlegende Seite (1-basiert)
     * @param int $lastPage Letzte zu zerlegende Seite (0 = bis zur letzten)
     * @param array<float> $margins CSS-Shorthand-Margins in Punkten (1–4 Werte: top, right, bottom, left), in Anzeigeausrichtung
     * @return list<string> Pfade der Teile in Ergebnisreihenfolge, leer bei Fehler
     */
    public static function cropToGridFiles(
        string $inputPath,
        string $outputDir,
        int $rows,
        int $columns,
        int $firstPage = 1,
        int $lastPage = 0,
        array $margins = []
    ): array {
        Folder::create($outputDir);

        $pages = self::runGridSplit($inputPath, $outputDir, $rows, $columns, $firstPage, $lastPage, $margins, true);
        if ($pages === null) {
            return [];
        }

        // Das Skript nummeriert sechsstellig mit führenden Nullen - die
        // Sortierung nach Namen ist die Ergebnisreihenfolge
        $files = Folder::findByPattern($outputDir, '[0-9][0-9][0-9][0-9][0-9][0-9].pdf');
        sort($files, SORT_STRING);

        if (count($files) !== $pages) {
            self::logError('Zerlegung hat nicht alle Teile geschrieben', [
                'expected' => $pages,
                'written' => count($files),
            ]);
            return [];
        }

        return $files;
    }

    /**
     * Ruft das MuPDF-Skript data/mupdf/grid-split.js auf.
     *
     * @param array<float> $margins
     * @return int|null Seitenzahl des Ergebnisses laut Skript, null bei Fehler
     */
    private static function runGridSplit(
        string $inputPath,
        string $output,
        int $rows,
        int $columns,
        int $firstPage,
        int $lastPage,
        array $margins,
        bool $separate
    ): ?int {
        if ($rows < 1 || $columns < 1 || $rows * $columns < 2) {
            self::logError('Raster braucht mindestens zwei Teile', ['rows' => $rows, 'columns' => $columns]);
            return null;
        }

        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return null;
        }

        $config = Config::getInstance();
        if (!$config->isExecutableAvailable('mutool-grid-split')) {
            self::logError('mutool (mutool-grid-split) ist nicht konfiguriert oder nicht verfügbar');
            return null;
        }

        [$mTop, $mRight, $mBottom, $mLeft] = !empty($margins)
            ? self::normalizeMargins($margins)
            : [0.0, 0.0, 0.0, 0.0];

        $command = $config->buildCommand('mutool-grid-split', [
            '[SCRIPT]' => PDFPageHelper::scriptPath('grid-split.js'),
            '[LIB]' => PDFPageHelper::libraryPath(),
            '[INPUT]' => $inputPath,
            '[OUTPUT]' => $output,
            '[ROWS]' => (string) $rows,
            '[COLUMNS]' => (string) $columns,
            '[FIRST-PAGE]' => (string) max(1, $firstPage),
            '[LAST-PAGE]' => (string) max(0, $lastPage),
            '[MARGIN-TOP]' => number_format(max(0.0, (float) $mTop), 2, '.', ''),
            '[MARGIN-RIGHT]' => number_format(max(0.0, (float) $mRight), 2, '.', ''),
            '[MARGIN-BOTTOM]' => number_format(max(0.0, (float) $mBottom), 2, '.', ''),
            '[MARGIN-LEFT]' => number_format(max(0.0, (float) $mLeft), 2, '.', ''),
            '[LAYOUT]' => $separate ? 'separate' : 'single',
        ]);

        if ($command === null) {
            self::logError('Konnte mutool-grid-split Befehl nicht erstellen');
            return null;
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode) || $returnCode !== 0) {
            self::logError('PDF-Zerlegung fehlgeschlagen', [
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return null;
        }

        // Letzte Ausgabezeile des Skripts: Seitenzahl des Ergebnisses
        $pages = (int) trim((string) end($output));
        if ($pages < 1) {
            self::logError('PDF-Zerlegung ohne Ergebnis', ['output' => implode("\n", $output)]);
            return null;
        }

        self::logInfo('PDF in Raster zerlegt', [
            'input' => $inputPath,
            'grid' => "{$rows}x{$columns}",
            'pages' => $pages,
            'separate' => $separate,
        ]);

        return $pages;
    }

    /**
     * Prüft ob das Zerlegen in ein Raster verfügbar ist (mutool).
     */
    public static function isGridAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('mutool-grid-split');
    }

    /**
     * Prüft ob Ghostscript für Cropping verfügbar ist.
     */
    public static function isAvailable(): bool {
        $config = Config::getInstance();
        return $config->isExecutableAvailable('gs-crop');
    }
}
