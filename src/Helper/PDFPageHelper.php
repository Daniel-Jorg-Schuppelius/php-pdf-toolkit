<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFPageHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\{Platform, Shell};
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * Seiten einer PDF anordnen, drehen, auf Blätter legen, stempeln und als
 * Bilder ausgeben.
 *
 * Anordnen, Drehen, Seiten pro Blatt und Stempel laufen über MuPDF-Skripte
 * (data/mupdf/*.js, mutool run): Die Seiten behalten ihren Inhaltsstrom,
 * nichts wird gerendert, Text bleibt Text. Die Skripte laufen mit MuPDF 1.17,
 * 1.21 und 1.25. Bilder und Miniaturen liefert pdftoppm.
 */
final class PDFPageHelper {
    use ErrorLog;

    /** Mehr Miniaturen fragt kein Dialog ab; darüber liefert thumbnails() die ersten */
    public const MAX_THUMBNAILS = 300;

    /** Seiten je Blatt, die page-nup.js kennt; 1 legt jede Seite allein auf das Blatt (Seitengröße ändern) */
    public const NUP_PER_SHEET = [1, 2, 4, 6, 8, 9, 12, 16];

    /** Stempel: Seitenzahlen ({n}, {total}) oder Wasserzeichen */
    public const STAMP_NUMBER = 'number';
    public const STAMP_WATERMARK = 'watermark';
    public const STAMP_MODES = [self::STAMP_NUMBER, self::STAMP_WATERMARK];

    /** Positionen eines Stempels; fill kachelt ein Wasserzeichen über die Seite */
    public const STAMP_POSITIONS = [
        'top-left', 'top-center', 'top-right', 'middle-left', 'center', 'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right', 'fill',
    ];

    /** Blätter, die lib.js kennt */
    public const SHEETS = ['a3', 'a4', 'a5', 'letter', 'legal'];

    public const ORIENTATIONS = ['auto', 'portrait', 'landscape'];

    /**
     * Pfad eines MuPDF-Skripts aus data/mupdf.
     */
    public static function scriptPath(string $name): string {
        return dirname(__DIR__, 2) . '/data/mupdf/' . $name;
    }

    /**
     * Gemeinsame Hilfsdatei der Skripte; jedes Skript bekommt sie als erstes Argument.
     */
    public static function libraryPath(): string {
        return self::scriptPath('lib.js');
    }

    /**
     * Ordnet die Seiten neu: Reihenfolge, Drehung je Seite, Weglassen,
     * Verdoppeln und leere Seiten in einem Schritt. Nicht genannte Seiten
     * fallen weg.
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param list<array{page: int, rotate?: int}> $sequence Zielseiten in ihrer Reihenfolge:
     *        Quellseite (1-basiert; 0 = leere Seite wie die davor) und Drehung in Grad, die zur bestehenden hinzukommt
     * @return int|null Seitenzahl des Ergebnisses, null bei Fehler
     */
    public static function arrange(string $inputPath, string $outputPath, array $sequence): ?int {
        $text = self::sequenceToString($sequence);
        if ($text === null) {
            return null;
        }

        $pages = self::runScript('mutool-page-arrange', 'page-arrange.js', [
            '[INPUT]' => $inputPath,
            '[OUTPUT]' => $outputPath,
            '[SEQUENCE]' => $text,
        ]);
        if ($pages === null || !File::exists($outputPath)) {
            return null;
        }

        self::logInfo('PDF-Seiten angeordnet', ['input' => $inputPath, 'pages' => $pages]);

        return $pages;
    }

    /**
     * Dreht Seiten um einen Winkel; die übrigen bleiben, wie sie sind.
     *
     * @param int $angle 90, 180 oder 270
     * @param list<int>|null $pages Seitennummern (1-basiert); null = alle
     * @return int|null Seitenzahl des Ergebnisses, null bei Fehler
     */
    public static function rotate(string $inputPath, string $outputPath, int $angle, ?array $pages = null): ?int {
        if (!in_array($angle, [90, 180, 270], true)) {
            self::logError('Ungültiger Rotationswinkel', ['angle' => $angle]);
            return null;
        }

        $count = PDFHelper::getPageCount($inputPath);
        if ($count < 1) {
            self::logError('Konnte Seitenzahl nicht ermitteln', ['path' => $inputPath]);
            return null;
        }

        $selected = $pages === null ? null : array_flip(array_map('intval', $pages));
        $sequence = [];
        for ($page = 1; $page <= $count; $page++) {
            $sequence[] = $selected === null || isset($selected[$page])
                ? ['page' => $page, 'rotate' => $angle]
                : ['page' => $page];
        }

        return self::arrange($inputPath, $outputPath, $sequence);
    }

    /**
     * Legt mehrere Seiten verkleinert auf ein Blatt (Seiten pro Blatt, N-up).
     * Reihenfolge auf dem Blatt: zeilenweise, links -> rechts, oben -> unten.
     *
     * @param int $perSheet 1, 2, 4, 6, 8, 9, 12 oder 16 (1 = Seitengröße ändern)
     * @param string $sheet a3, a4, a5, letter oder legal
     * @param float $gapMm Abstand zwischen den Seiten und zum Blattrand in Millimetern
     * @param string $orientation auto, portrait oder landscape
     * @param bool $frame Dünner Rahmen um jede belegte Zelle
     * @return int|null Zahl der Blätter, null bei Fehler
     */
    public static function nup(string $inputPath, string $outputPath, int $perSheet, string $sheet = 'a4', float $gapMm = 5.0, string $orientation = 'auto', bool $frame = false): ?int {
        $sheet = strtolower($sheet);
        $orientation = strtolower($orientation);
        if (!in_array($perSheet, self::NUP_PER_SHEET, true)) {
            self::logError('Seiten je Blatt muss 1, 2, 4, 6, 8, 9, 12 oder 16 sein', ['perSheet' => $perSheet]);
            return null;
        }
        if (!in_array($sheet, self::SHEETS, true) || !in_array($orientation, self::ORIENTATIONS, true) || $gapMm < 0) {
            self::logError('Ungültige Blattangaben', ['sheet' => $sheet, 'orientation' => $orientation, 'gapMm' => $gapMm]);
            return null;
        }

        $sheets = self::runScript('mutool-page-nup', 'page-nup.js', [
            '[INPUT]' => $inputPath,
            '[OUTPUT]' => $outputPath,
            '[PER-SHEET]' => (string) $perSheet,
            '[SHEET]' => $sheet,
            '[GAP-MM]' => number_format($gapMm, 2, '.', ''),
            '[ORIENTATION]' => $orientation,
            '[FRAME]' => $frame ? '1' : '0',
        ]);
        if ($sheets === null || !File::exists($outputPath)) {
            return null;
        }

        self::logInfo('PDF-Seiten auf Blätter gelegt', ['input' => $inputPath, 'perSheet' => $perSheet, 'sheets' => $sheets]);

        return $sheets;
    }

    /**
     * Stempelt Text über den Seiteninhalt: Seitenzahlen oder ein Wasserzeichen.
     * Der Text reist in einer Datei, nie auf der Befehlszeile.
     *
     * @param string $mode STAMP_NUMBER (Text mit {n} und {total}) oder STAMP_WATERMARK
     * @param string $text Der Text bzw. das Muster, z. B. "Seite {n} von {total}"
     * @param array{position?: string, size?: float, marginMm?: float, angle?: float, opacity?: float, color?: string, firstPage?: int, start?: int} $options
     *        position aus {@see STAMP_POSITIONS} (Vorgabe bottom-center bzw. center), size in Punkt (10 bzw. 48),
     *        marginMm (10), angle in Grad (0 bzw. 45), opacity 0-1 (1 bzw. 0.3), color rrggbb (000000 bzw. 808080),
     *        firstPage (1), start = Wert von {n} auf der ersten gestempelten Seite (1)
     * @return int|null Zahl der gestempelten Seiten, null bei Fehler
     */
    public static function stamp(string $inputPath, string $outputPath, string $mode, string $text, array $options = []): ?int {
        if (!in_array($mode, self::STAMP_MODES, true)) {
            self::logError('Unbekannter Stempel-Modus', ['mode' => $mode]);
            return null;
        }
        $watermark = $mode === self::STAMP_WATERMARK;
        $position = strtolower((string) ($options['position'] ?? ($watermark ? 'center' : 'bottom-center')));
        $size = (float) ($options['size'] ?? ($watermark ? 48 : 10));
        $marginMm = (float) ($options['marginMm'] ?? 10);
        $angle = (float) ($options['angle'] ?? ($watermark ? 45 : 0));
        $opacity = (float) ($options['opacity'] ?? ($watermark ? 0.3 : 1));
        $color = strtolower((string) ($options['color'] ?? ($watermark ? '808080' : '000000')));
        $firstPage = (int) ($options['firstPage'] ?? 1);
        $start = (int) ($options['start'] ?? 1);

        $text = trim((string) preg_replace('/[\r\n]+/', ' ', $text));
        if ($text === '') {
            self::logError('Der Stempeltext ist leer');
            return null;
        }
        if (!in_array($position, self::STAMP_POSITIONS, true) || ($position === 'fill' && !$watermark)) {
            self::logError('Ungültige Stempel-Position', ['position' => $position, 'mode' => $mode]);
            return null;
        }
        if ($size < 4 || $size > 400 || $marginMm < 0 || $marginMm > 200 || $opacity < 0 || $opacity > 1
            || $firstPage < 1 || preg_match('/^[0-9a-f]{6}$/', $color) !== 1) {
            self::logError('Ungültige Stempel-Angaben', ['size' => $size, 'marginMm' => $marginMm, 'opacity' => $opacity, 'firstPage' => $firstPage, 'color' => $color]);
            return null;
        }

        $dir = Platform::getTempDirectory() . '/pdfstamp_' . bin2hex(random_bytes(8));
        Folder::create($dir, 0700);
        $textFile = $dir . '/text.txt';

        try {
            File::write($textFile, $text . "\n");

            $stamped = self::runScript('mutool-page-stamp', 'page-stamp.js', [
                '[INPUT]' => $inputPath,
                '[OUTPUT]' => $outputPath,
                '[MODE]' => $mode,
                '[TEXT-FILE]' => $textFile,
                '[POSITION]' => $position,
                '[SIZE]' => number_format($size, 2, '.', ''),
                '[MARGIN-MM]' => number_format($marginMm, 2, '.', ''),
                '[ANGLE]' => number_format($angle, 2, '.', ''),
                '[OPACITY]' => number_format($opacity, 3, '.', ''),
                '[COLOR]' => $color,
                '[FIRST-PAGE]' => (string) $firstPage,
                '[START]' => (string) $start,
            ]);
        } finally {
            Folder::delete($dir, true);
        }
        if ($stamped === null || !File::exists($outputPath)) {
            return null;
        }

        self::logInfo('PDF-Seiten gestempelt', ['input' => $inputPath, 'mode' => $mode, 'pages' => $stamped]);

        return $stamped;
    }

    /**
     * Zieht die eingebetteten Bilder eines Seitenbereichs als PNG heraus
     * (pdfimages); der Dateiname trägt die Seitennummer.
     *
     * @param string $outputDir Verzeichnis (wird angelegt)
     * @param int $firstPage Erste Seite (1-basiert)
     * @param int $lastPage Letzte Seite (0 = bis zur letzten)
     * @return list<string> Pfade in Seitenreihenfolge; leer, wenn es keine Bilder gibt oder ein Fehler auftrat
     */
    public static function extractImages(string $inputPath, string $outputDir, int $firstPage = 1, int $lastPage = 0): array {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return [];
        }
        $count = PDFHelper::getPageCount($inputPath);
        $firstPage = max(1, $firstPage);
        $lastPage = $lastPage < 1 || $lastPage > $count ? $count : $lastPage;
        if ($count < 1 || $firstPage > $lastPage) {
            self::logError('Seitenbereich liegt außerhalb der Datei', ['first' => $firstPage, 'last' => $lastPage, 'pages' => $count]);
            return [];
        }

        $config = Config::getInstance();
        if (!$config->isExecutableAvailable('pdfimages-extract')) {
            self::logError('pdfimages ist nicht konfiguriert oder nicht verfügbar');
            return [];
        }

        Folder::create($outputDir);
        $command = $config->buildCommand('pdfimages-extract', [
            '[FIRST]' => (string) $firstPage,
            '[LAST]' => (string) $lastPage,
            '[PDF-FILE]' => $inputPath,
            '[OUTPUT-PREFIX]' => $outputDir . '/bild',
        ]);
        if ($command === null) {
            self::logError('Konnte pdfimages-Befehl nicht erstellen');
            return [];
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode) || $returnCode !== 0) {
            self::logError('pdfimages fehlgeschlagen', ['returnCode' => $returnCode, 'output' => implode("\n", $output)]);
            return [];
        }

        $files = Folder::findByPattern($outputDir, 'bild-*.png');
        sort($files, SORT_NATURAL);
        self::logInfo('Bilder aus PDF gezogen', ['input' => $inputPath, 'images' => count($files)]);

        return $files;
    }

    /**
     * Legt Bilder je auf eine Seite eines Blatts, ohne sie neu zu kodieren
     * (JPEG bleibt JPEG). Jedes Bild wird in den Bereich innerhalb des Rands
     * eingepasst und zentriert, über seine Größe bei 150 dpi nicht vergrößert.
     *
     * @param list<string> $imagePaths Bilder in Seitenreihenfolge
     * @param string $sheet a3, a4, a5, letter oder legal
     * @param float $marginMm Rand rundum in Millimetern
     * @param string $orientation auto (Blatt dreht sich nach dem Bild), portrait oder landscape
     * @return int|null Seitenzahl des Ergebnisses, null bei Fehler
     */
    public static function imagesToPdf(array $imagePaths, string $outputPath, string $sheet = 'a4', float $marginMm = 0.0, string $orientation = 'auto'): ?int {
        $sheet = strtolower($sheet);
        $orientation = strtolower($orientation);
        if ($imagePaths === []) {
            self::logError('Keine Bilder angegeben');
            return null;
        }
        if (!in_array($sheet, self::SHEETS, true) || !in_array($orientation, self::ORIENTATIONS, true) || $marginMm < 0) {
            self::logError('Ungültige Blattangaben', ['sheet' => $sheet, 'orientation' => $orientation, 'marginMm' => $marginMm]);
            return null;
        }
        foreach ($imagePaths as $path) {
            if (!File::exists($path)) {
                self::logError('Bilddatei nicht gefunden', ['path' => $path]);
                return null;
            }
        }

        $pages = self::runScript('mutool-image-page', 'image-page.js', [
            '[OUTPUT]' => $outputPath,
            '[SHEET]' => $sheet,
            '[MARGIN-MM]' => number_format($marginMm, 2, '.', ''),
            '[ORIENTATION]' => $orientation,
            // Mehrere Pfade als eine bereits escapte Folge (CommandBuilder übernimmt sie unverändert)
            '[IMAGES]' => implode(' ', array_map('escapeshellarg', $imagePaths)),
        ]);
        if ($pages === null || !File::exists($outputPath)) {
            return null;
        }

        self::logInfo('Bilder auf PDF-Seiten gelegt', ['images' => count($imagePaths), 'sheet' => $sheet]);

        return $pages;
    }

    /**
     * Miniaturen aller Seiten in einem Aufruf, für Seitenübersichten.
     *
     * @param string $outputDir Verzeichnis (wird angelegt)
     * @param int $dpi Auflösung; 24 dpi ergibt rund 200 x 280 Pixel je A4-Seite
     * @param int $maxPages Obergrenze; darüber kommen nur die ersten Seiten
     * @return list<string> Pfade der PNG-Dateien in Seitenreihenfolge, leer bei Fehler
     */
    public static function thumbnails(string $inputPath, string $outputDir, int $dpi = 24, int $maxPages = self::MAX_THUMBNAILS): array {
        $count = PDFHelper::getPageCount($inputPath);
        if ($count < 1) {
            self::logError('Konnte Seitenzahl nicht ermitteln', ['path' => $inputPath]);
            return [];
        }

        return self::renderPages($inputPath, $outputDir, 'png', max(12, min(72, $dpi)), 1, min($count, max(1, $maxPages)));
    }

    /**
     * Gibt Seiten als Bilder aus, je Seite eine Datei.
     *
     * @param string $outputDir Verzeichnis (wird angelegt)
     * @param string $format png oder jpeg
     * @param int $dpi Auflösung
     * @param int $firstPage Erste Seite (1-basiert)
     * @param int $lastPage Letzte Seite (0 = bis zur letzten)
     * @param int $quality JPEG-Qualität 1-100
     * @return list<string> Pfade in Seitenreihenfolge, leer bei Fehler
     */
    public static function renderPages(string $inputPath, string $outputDir, string $format = 'png', int $dpi = 150, int $firstPage = 1, int $lastPage = 0, int $quality = 85): array {
        $format = strtolower($format) === 'jpg' ? 'jpeg' : strtolower($format);
        if (!in_array($format, ['png', 'jpeg'], true)) {
            self::logError('Bildformat muss png oder jpeg sein', ['format' => $format]);
            return [];
        }
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return [];
        }

        $count = PDFHelper::getPageCount($inputPath);
        $firstPage = max(1, $firstPage);
        $lastPage = $lastPage < 1 || $lastPage > $count ? $count : $lastPage;
        if ($count < 1 || $firstPage > $lastPage) {
            self::logError('Seitenbereich liegt außerhalb der Datei', ['first' => $firstPage, 'last' => $lastPage, 'pages' => $count]);
            return [];
        }

        $executable = 'pdftoppm-range-' . $format;
        $config = Config::getInstance();
        if (!$config->isExecutableAvailable($executable)) {
            self::logError('pdftoppm ist nicht konfiguriert oder nicht verfügbar', ['executable' => $executable]);
            return [];
        }

        Folder::create($outputDir);
        $prefix = $outputDir . '/page';
        $command = $config->buildCommand($executable, [
            '[DPI]' => (string) max(12, min(600, $dpi)),
            '[FIRST]' => (string) $firstPage,
            '[LAST]' => (string) $lastPage,
            '[QUALITY]' => (string) max(1, min(100, $quality)),
            '[PDF-FILE]' => $inputPath,
            '[OUTPUT-PREFIX]' => $prefix,
        ]);
        if ($command === null) {
            self::logError('Konnte pdftoppm-Befehl nicht erstellen');
            return [];
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode) || $returnCode !== 0) {
            self::logError('pdftoppm fehlgeschlagen', ['returnCode' => $returnCode, 'output' => implode("\n", $output)]);
            return [];
        }

        // pdftoppm nummeriert mit führenden Nullen in der Breite der Seitenzahl
        $extension = $format === 'png' ? 'png' : 'jpg';
        $files = Folder::findByPattern($outputDir, 'page-*.' . $extension);
        sort($files, SORT_NATURAL);

        $expected = $lastPage - $firstPage + 1;
        if (count($files) !== $expected) {
            self::logError('pdftoppm hat nicht alle Seiten geschrieben', ['expected' => $expected, 'written' => count($files)]);
            return [];
        }

        return $files;
    }

    /**
     * Prüft ob die Seitenoperationen verfügbar sind (mutool konfiguriert).
     */
    public static function isAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('mutool-page-arrange');
    }

    /**
     * Textform einer Folge für page-arrange.js: "3:90,1,0,2:180" (0 = leere Seite).
     *
     * @param list<array{page: int, rotate?: int}> $sequence
     */
    public static function sequenceToString(array $sequence): ?string {
        if ($sequence === []) {
            self::logError('Die Seitenfolge ist leer');
            return null;
        }

        $items = [];
        foreach ($sequence as $entry) {
            $page = $entry['page'];
            $rotate = $entry['rotate'] ?? 0;
            if ($page < 0) {
                self::logError('Seitennummer darf nicht negativ sein (0 = leere Seite)', ['entry' => $entry]);
                return null;
            }
            if ($rotate % 90 !== 0) {
                self::logError('Drehung muss ein Vielfaches von 90 sein', ['entry' => $entry]);
                return null;
            }
            $rotate = (($rotate % 360) + 360) % 360;
            $items[] = $rotate === 0 ? (string) $page : $page . ':' . $rotate;
        }

        return implode(',', $items);
    }

    /**
     * Ruft ein MuPDF-Skript aus data/mupdf auf; die letzte Ausgabezeile ist
     * eine Zahl (Seiten oder Blätter des Ergebnisses). Für die Helfer dieses
     * Pakets, nicht für Aufrufer von außen.
     *
     * @param array<string, string> $replacements
     */
    public static function runScript(string $executable, string $script, array $replacements): ?int {
        $config = Config::getInstance();
        if (!$config->isExecutableAvailable($executable)) {
            self::logError('mutool ist nicht konfiguriert oder nicht verfügbar', ['executable' => $executable]);
            return null;
        }

        $command = $config->buildCommand($executable, [
            '[SCRIPT]' => self::scriptPath($script),
            '[LIB]' => self::libraryPath(),
        ] + $replacements);
        if ($command === null) {
            self::logError('Konnte mutool-Befehl nicht erstellen', ['executable' => $executable]);
            return null;
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode) || $returnCode !== 0) {
            self::logError('MuPDF-Skript fehlgeschlagen', [
                'script' => $script,
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return null;
        }

        $result = (int) trim((string) end($output));
        if ($result < 1) {
            self::logError('MuPDF-Skript ohne Ergebnis', ['script' => $script, 'output' => implode("\n", $output)]);
            return null;
        }

        return $result;
    }
}
