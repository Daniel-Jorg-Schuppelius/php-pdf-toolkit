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

        $output = [];
        $returnCode = 0;
        $ok = ConfinedTool::execute('gs-crop', [
            '[WIDTH]' => number_format($width, 2, '.', ''),
            '[HEIGHT]' => number_format($height, 2, '.', ''),
            '[FIRST-PAGE]' => $firstPage,
            '[LAST-PAGE]' => $lastPage,
            '[OUTPUT]' => $outputPath,
            '[POSTSCRIPT]' => $postscript,
            '[INPUT]' => $inputPath,
        ], $output, $returnCode, ToolTimeout::forSize($inputPath));

        if ($ok === null) {
            self::logError('Konnte gs-crop Befehl nicht erstellen');
            return false;
        }

        if (!$ok || $returnCode !== 0) {
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
     * Begrenzung des gezeichneten Inhalts einer Seite in PDF-Punkten
     * (Ursprung unten links), ermittelt mit dem Ghostscript-Geraet bbox.
     *
     * Es zaehlt nur, was auf der Seite liegt: pdfwrite laesst bei einem
     * Zuschnitt ({@see cropToBox()}) den Inhalt ausserhalb im Datenstrom
     * stehen, bbox schneidet ihn ab. Weisser Inhalt zaehlt wie jeder andere -
     * gezeichnet ist gezeichnet.
     *
     * @param string $inputPath Pfad zur PDF
     * @param int $page Seitennummer (1-basiert)
     * @return array{x0: float, y0: float, x1: float, y1: float}|null null bei leerer Seite oder Fehler
     */
    public static function contentBox(string $inputPath, int $page = 1): ?array {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return null;
        }

        if (!Config::getInstance()->isExecutableAvailable('gs-bbox')) {
            self::logError('Ghostscript (gs-bbox) ist nicht konfiguriert oder nicht verfügbar');
            return null;
        }

        // bbox schreibt die Begrenzung auf stderr und keine Ausgabedatei
        $output = [];
        $returnCode = 0;
        $ok = ConfinedTool::execute('gs-bbox', [
            '[FIRST-PAGE]' => sprintf('-dFirstPage=%d', max(1, $page)),
            '[LAST-PAGE]' => sprintf('-dLastPage=%d', max(1, $page)),
            '[INPUT]' => $inputPath,
        ], $output, $returnCode, ToolTimeout::forSize($inputPath), outputs: [], mergeStderr: true);

        if (!$ok || $returnCode !== 0) {
            self::logError('Ghostscript-Begrenzung fehlgeschlagen', [
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return null;
        }

        foreach ($output as $line) {
            if (preg_match('/^%%HiResBoundingBox:\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)/', trim($line), $m) !== 1) {
                continue;
            }
            [$x0, $y0, $x1, $y1] = [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4]];

            // Leere Seite: bbox meldet 0 0 0 0
            return $x1 > $x0 && $y1 > $y0 ? ['x0' => $x0, 'y0' => $y0, 'x1' => $x1, 'y1' => $y1] : null;
        }

        return null;
    }

    /**
     * Schneidet eine Seite auf ihren gezeichneten Inhalt zu ({@see contentBox()}),
     * mit gleichem Rand auf allen Seiten, soweit die Seite ihn hergibt.
     *
     * Fuer Belege, deren Inhalt nicht mittig im Ausschnitt sitzt: Zentriert
     * wird danach der Inhalt, nicht der Ausschnitt (Versandetikett,
     * Meldung FM-SQ6N8-6ZEEB).
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param float $padding Rand um den Inhalt in Punkten
     * @param int $page Seitennummer (1-basiert)
     * @return bool false bei leerer Seite oder Fehler (dann ist nichts geschrieben)
     */
    public static function cropToContent(string $inputPath, string $outputPath, float $padding = 0.0, int $page = 1): bool {
        $box = self::contentBox($inputPath, $page);
        $dims = $box !== null ? self::getPageDimensions($inputPath) : null;
        if ($box === null || $dims === null) {
            return false;
        }

        $padding = max(0.0, $padding);
        $x0 = max(0.0, $box['x0'] - $padding);
        $y0 = max(0.0, $box['y0'] - $padding);
        $x1 = min($dims['width'], $box['x1'] + $padding);
        $y1 = min($dims['height'], $box['y1'] + $padding);

        return self::cropToBox($inputPath, $outputPath, $x0, $y0, $x1 - $x0, $y1 - $y0, $page);
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

        $output = [];
        $returnCode = 0;
        $ok = ConfinedTool::execute('gs-resize', [
            '[WIDTH]' => number_format($widthPt, 2, '.', ''),
            '[HEIGHT]' => number_format($heightPt, 2, '.', ''),
            '[OUTPUT]' => $outputPath,
            '[INPUT]' => $inputPath,
        ], $output, $returnCode, ToolTimeout::forSize($inputPath));

        if ($ok === null) {
            self::logError('Konnte gs-resize Befehl nicht erstellen');
            return false;
        }

        if (!$ok || $returnCode !== 0) {
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
     * Skaliert eine PDF-Seite proportional auf eine Zielgröße und zentriert sie.
     *
     * 1. Bei Orientierungs-Mismatch (Landscape->Portrait oder umgekehrt): Seite physisch rotieren
     * 2. resizeToFit(): -dPDFFitPage skaliert proportional UND setzt die Seite
     *    mittig auf die Zielseite (in beiden Richtungen).
     *
     * Bis 0.32.2 folgte ein dritter Schritt, der das Ergebnis per PageOffset
     * noch einmal um den halben Leerraum verschob - in der Annahme, FitPage
     * setze unten links. Das Ergebnis lag dadurch an einer Kante: die
     * DHL-Paketmarke mit 7,2 mm links und 18 mm rechts auf dem 10x15-cm-
     * Etikett (Meldung FM-SQ6N8-6ZEEB, Ghostscript 10.05/10.06).
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
                $rotatedTempPath = null;
            } else {
                $inputPath = $rotatedTempPath;
            }
        }

        try {
            if (!self::resizeToFit($inputPath, $outputPath, $widthPt, $heightPt)) {
                return false;
            }

            self::logInfo('PDF proportional skaliert und zentriert', [
                'input' => $inputPath,
                'output' => $outputPath,
                'rotated' => $rotatedTempPath !== null,
                'targetSize' => sprintf('%.0fx%.0f pt', $widthPt, $heightPt),
            ]);

            return true;
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
        if ($rows < 1 || $columns < 1) {
            self::logError('Zeilen und Spalten müssen mindestens 1 sein', ['rows' => $rows, 'columns' => $columns]);
            return null;
        }
        // Ein Teil je Seite ist nur mit Rand sinnvoll: beschneidet die Seite um den Rand
        if ($rows * $columns < 2 && array_sum($margins) <= 0) {
            self::logError('Raster braucht mindestens zwei Teile oder einen Rand', ['rows' => $rows, 'columns' => $columns]);
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
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode, timeout: ToolTimeout::forSize($inputPath)) || $returnCode !== 0) {
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
