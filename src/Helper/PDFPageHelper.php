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
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * Seiten einer PDF anordnen, drehen, auf Blätter legen und als Bilder
 * ausgeben.
 *
 * Anordnen, Drehen und Seiten pro Blatt laufen über MuPDF-Skripte
 * (data/mupdf/*.js, mutool run): Die Seiten behalten ihren Inhaltsstrom,
 * nichts wird gerendert, Text bleibt Text. Die Skripte laufen mit MuPDF 1.17,
 * 1.21 und 1.25. Bilder und Miniaturen liefert pdftoppm.
 */
final class PDFPageHelper {
    use ErrorLog;

    /** Mehr Miniaturen fragt kein Dialog ab; darüber liefert thumbnails() die ersten */
    public const MAX_THUMBNAILS = 300;

    /** Seiten je Blatt, die page-nup.js kennt */
    public const NUP_PER_SHEET = [2, 4, 6, 9];

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
     * Ordnet die Seiten neu: Reihenfolge, Drehung je Seite, Weglassen und
     * Verdoppeln in einem Schritt. Nicht genannte Seiten fallen weg.
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param list<array{page: int, rotate?: int}> $sequence Zielseiten in ihrer Reihenfolge:
     *        Quellseite (1-basiert) und Drehung in Grad, die zur bestehenden hinzukommt
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
     * @param int $perSheet 2, 4, 6 oder 9
     * @param string $sheet a3, a4, a5, letter oder legal
     * @param float $gapMm Abstand zwischen den Seiten und zum Blattrand in Millimetern
     * @param string $orientation auto, portrait oder landscape
     * @return int|null Zahl der Blätter, null bei Fehler
     */
    public static function nup(string $inputPath, string $outputPath, int $perSheet, string $sheet = 'a4', float $gapMm = 5.0, string $orientation = 'auto'): ?int {
        $sheet = strtolower($sheet);
        $orientation = strtolower($orientation);
        if (!in_array($perSheet, self::NUP_PER_SHEET, true)) {
            self::logError('Seiten je Blatt muss 2, 4, 6 oder 9 sein', ['perSheet' => $perSheet]);
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
        ]);
        if ($sheets === null || !File::exists($outputPath)) {
            return null;
        }

        self::logInfo('PDF-Seiten auf Blätter gelegt', ['input' => $inputPath, 'perSheet' => $perSheet, 'sheets' => $sheets]);

        return $sheets;
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
     * Textform einer Folge für page-arrange.js: "3:90,1,1,2:180".
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
            if ($page < 1) {
                self::logError('Seitennummer muss mindestens 1 sein', ['entry' => $entry]);
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
     * Ruft ein MuPDF-Skript auf; die letzte Ausgabezeile ist eine Zahl
     * (Seiten oder Blätter des Ergebnisses).
     *
     * @param array<string, string> $replacements
     */
    private static function runScript(string $executable, string $script, array $replacements): ?int {
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
