<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFRedactHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\Data\StringHelper;
use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\{Platform, Shell};
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * Bereiche einer PDF schwärzen: Text und Bildpixel unter dem Rechteck werden
 * aus der Datei entfernt, nicht nur überdeckt (MuPDF Redact-Anmerkung und
 * applyRedactions, data/mupdf/pdf-redact.js). Die Fundstellen liefert
 * {@see findText()} aus pdftotext -bbox-layout: je Wort ein Rechteck im
 * Seitenraum der Anzeige (Ursprung oben links, y nach unten, Drehung
 * angewandt), auf Wunsch die ganze Zeile über die Seitenbreite.
 *
 * Bildpixel entfernt MuPDF erst ab 1.19 (Ubuntu 22.04); davor überdeckt die
 * schwarze Fläche das Bild nur, die Daten blieben in der Datei. Deshalb
 * schwärzt {@see redact()} Seiten mit Bildern nur bei einer tauglichen
 * Version und lehnt sonst ab ({@see supportsImages()}).
 */
final class PDFRedactHelper {
    use ErrorLog;

    /** Erste MuPDF-Version, die Bildpixel unter einer Schwärzung entfernt (gemessen: 1.17 nicht, 1.19 ja) */
    public const MIN_IMAGE_VERSION = '1.19';

    /** Rand um ein Wort in Punkten, damit Unter- und Oberlängen sicher weg sind */
    public const WORD_PADDING = 1.0;

    /** Rand über und unter einer ganzen Zeile in Punkten */
    public const LINE_PADDING = 1.5;

    /** Kleinste senkrechte Toleranz in Punkten, innerhalb derer Wörter eine Zeile bilden */
    public const LINE_TOLERANCE = 3.0;

    private static ?string $mutoolVersion = null;

    /**
     * Fundstellen der Suchbegriffe je Seite. Verglichen wird ohne Rücksicht
     * auf Groß-/Kleinschreibung und mit einfachen Leerzeichen; ein Begriff
     * darf mehrere Wörter umfassen, aber keine Zeile überschreiten.
     *
     * @param list<string> $terms Suchbegriffe, leere werden übergangen
     * @param bool $wholeLine true = die ganze Zeile über die Seitenbreite statt nur der Wörter
     * @param list<int>|null $pages Seiten (1-basiert); null = alle
     * @return array<int, list<array{x0: float, y0: float, x1: float, y1: float}>> Seite -> Rechtecke
     */
    public static function findText(string $pdfPath, array $terms, bool $wholeLine = false, ?array $pages = null): array {
        $needles = [];
        foreach ($terms as $term) {
            $needle = self::normalize((string) $term);
            if ($needle !== '') {
                $needles[] = $needle;
            }
        }
        if ($needles === []) {
            self::logError('Kein Suchbegriff angegeben');
            return [];
        }

        $found = [];
        foreach (self::pageLines($pdfPath, $pages) as $pageNumber => $page) {
            foreach ($page['lines'] as $line) {
                $rects = self::matchLine($line, $needles, $wholeLine, $page['width']);
                if ($rects !== []) {
                    $found[$pageNumber] = array_merge($found[$pageNumber] ?? [], $rects);
                }
            }
        }

        return $found;
    }

    /**
     * Schwärzt die Rechtecke (Seitenraum der Anzeige, Punkte) und schreibt die
     * Datei neu.
     *
     * @param array<int, list<array{x0: float, y0: float, x1: float, y1: float}>> $rects Seite (1-basiert) -> Rechtecke
     * @return int|null Zahl der geschwärzten Rechtecke, null bei Fehler
     */
    public static function redact(string $inputPath, string $outputPath, array $rects): ?int {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return null;
        }
        $pageCount = PDFHelper::getPageCount($inputPath);
        $lines = [];
        foreach ($rects as $page => $list) {
            $page = (int) $page;
            if ($page < 1 || $page > $pageCount) {
                self::logError('Schwärzung auf einer Seite, die es nicht gibt', ['page' => $page, 'pages' => $pageCount]);
                return null;
            }
            foreach ($list as $rect) {
                $x0 = min((float) $rect['x0'], (float) $rect['x1']);
                $x1 = max((float) $rect['x0'], (float) $rect['x1']);
                $y0 = min((float) $rect['y0'], (float) $rect['y1']);
                $y1 = max((float) $rect['y0'], (float) $rect['y1']);
                if ($x1 <= $x0 || $y1 <= $y0) {
                    self::logError('Leeres Rechteck', ['page' => $page, 'rect' => $rect]);
                    return null;
                }
                $lines[] = implode("\t", [$page, self::number($x0), self::number($y0), self::number($x1), self::number($y1)]);
            }
        }
        if ($lines === []) {
            self::logError('Kein Rechteck zum Schwärzen');
            return null;
        }

        if (!self::supportsImages() && self::hasImages($inputPath, array_map('intval', array_keys($rects)))) {
            self::logError('Diese MuPDF-Version entfernt keine Bildpixel unter einer Schwärzung; ab ' . self::MIN_IMAGE_VERSION . ' nötig', ['version' => self::mutoolVersion()]);
            return null;
        }

        $dir = Platform::getTempDirectory() . '/pdfredact_' . bin2hex(random_bytes(8));
        Folder::create($dir, 0700);
        $dataFile = $dir . '/rects.txt';

        try {
            File::write($dataFile, implode("\n", $lines) . "\n");

            $count = PDFPageHelper::runScript('mutool-pdf-redact', 'pdf-redact.js', [
                '[INPUT]' => $inputPath,
                '[OUTPUT]' => $outputPath,
                '[DATA-FILE]' => $dataFile,
            ]);
        } finally {
            Folder::delete($dir, true);
        }
        if ($count === null || !File::exists($outputPath)) {
            return null;
        }

        self::logInfo('PDF geschwärzt', ['input' => $inputPath, 'rects' => $count, 'pages' => count($rects)]);

        return $count;
    }

    public static function isAvailable(): bool {
        $config = Config::getInstance();

        return $config->isExecutableAvailable('mutool-pdf-redact') && $config->isExecutableAvailable('pdftotext-bbox-layout');
    }

    /** Entfernt die installierte MuPDF-Version Bildpixel unter einer Schwärzung? */
    public static function supportsImages(): bool {
        $version = self::mutoolVersion();

        return $version !== null && version_compare($version, self::MIN_IMAGE_VERSION, '>=');
    }

    /** Version von mutool ("1.25.1"), null wenn nicht lesbar */
    public static function mutoolVersion(): ?string {
        if (self::$mutoolVersion !== null) {
            return self::$mutoolVersion === '' ? null : self::$mutoolVersion;
        }
        $config = Config::getInstance();
        $command = $config->isExecutableAvailable('mutool-version') ? $config->buildCommand('mutool-version', []) : null;
        $output = [];
        $returnCode = 0;
        $version = '';
        if ($command !== null && Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode, timeout: ToolTimeout::PROBE)) {
            if (preg_match('/version\s+(\d+\.\d+(?:\.\d+)?)/', implode("\n", $output), $m) === 1) {
                $version = $m[1];
            }
        }
        self::$mutoolVersion = $version;

        return $version === '' ? null : $version;
    }

    /** Für Tests: gemerkte Version vergessen */
    public static function resetVersionCache(): void {
        self::$mutoolVersion = null;
    }

    /**
     * Tragen die Seiten eingebettete Bilder? (pdfimages -list)
     *
     * @param list<int> $pages
     */
    public static function hasImages(string $pdfPath, array $pages): bool {
        $config = Config::getInstance();
        if ($pages === [] || !$config->isExecutableAvailable('pdfimages-list-range')) {
            return $pages !== [];
        }
        $command = $config->buildCommand('pdfimages-list-range', [
            '[FIRST]' => (string) min($pages),
            '[LAST]' => (string) max($pages),
            '[PDF-FILE]' => $pdfPath,
        ]);
        if ($command === null) {
            return true;
        }
        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode, timeout: ToolTimeout::PROBE) || $returnCode !== 0) {
            return true;
        }
        $wanted = array_flip($pages);
        foreach ($output as $line) {
            // "   1     0 image     400   300  rgb ..." - Spalte 1 ist die Seite
            if (preg_match('/^\s*(\d+)\s+\d+\s+(image|smask|stencil)\b/', $line, $m) === 1 && isset($wanted[(int) $m[1]])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zeilen je Seite aus pdftotext -bbox-layout: Wörter mit Rechtecken im
     * Seitenraum der Anzeige.
     *
     * @param list<int>|null $pages
     * @return array<int, array{width: float, height: float, lines: list<list<array{text: string, x0: float, y0: float, x1: float, y1: float}>>}>
     */
    public static function pageLines(string $pdfPath, ?array $pages = null): array {
        if (!PDFHelper::isValidPdf($pdfPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $pdfPath]);
            return [];
        }
        $config = Config::getInstance();
        if (!$config->isExecutableAvailable('pdftotext-bbox-layout')) {
            self::logError('pdftotext ist nicht konfiguriert oder nicht verfügbar (pdftotext-bbox-layout)');
            return [];
        }
        $pageCount = PDFHelper::getPageCount($pdfPath);
        $first = $pages === null || $pages === [] ? 1 : max(1, min($pages));
        $last = $pages === null || $pages === [] ? $pageCount : min($pageCount, max($pages));
        if ($first > $last) {
            return [];
        }
        $wanted = $pages === null ? null : array_flip($pages);

        $textFile = TempPath::file('pdfredact_bbox', 'html');
        try {
            $command = $config->buildCommand('pdftotext-bbox-layout', [
                '[FIRST]' => (string) $first,
                '[LAST]' => (string) $last,
                '[PDF-FILE]' => $pdfPath,
                '[TEXT-FILE]' => $textFile,
            ]);
            if ($command === null) {
                self::logError('Konnte pdftotext-Befehl nicht erstellen');
                return [];
            }
            $output = [];
            $returnCode = 0;
            if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode, timeout: ToolTimeout::forSize($pdfPath)) || $returnCode !== 0 || !File::exists($textFile)) {
                self::logError('pdftotext -bbox-layout fehlgeschlagen', ['returnCode' => $returnCode, 'output' => implode("\n", $output)]);
                return [];
            }
            $html = File::read($textFile);
        } finally {
            if (File::exists($textFile)) {
                File::delete($textFile);
            }
        }

        return self::parseLayout($html, $first, $wanted);
    }

    /**
     * Zerlegt die XHTML-Ausgabe von pdftotext -bbox-layout ohne DOM (die
     * Dateien werden bei langen Auszügen groß).
     *
     * @param array<int, int>|null $wanted
     * @return array<int, array{width: float, height: float, lines: list<list<array{text: string, x0: float, y0: float, x1: float, y1: float}>>}>
     */
    public static function parseLayout(string $html, int $firstPage, ?array $wanted = null): array {
        $result = [];
        $pageNumber = $firstPage - 1;
        if (preg_match_all('#<page\s+width="([\d.]+)"\s+height="([\d.]+)">(.*?)</page>#s', $html, $pageMatches, PREG_SET_ORDER) < 1) {
            return [];
        }
        foreach ($pageMatches as $pageMatch) {
            $pageNumber++;
            if ($wanted !== null && !isset($wanted[$pageNumber])) {
                continue;
            }
            // Alle Woerter der Seite; die Bloecke von pdftotext trennen Spalten,
            // eine Buchungszeile liegt aber ueber alle Spalten - Zeilen entstehen
            // deshalb aus der senkrechten Lage ({@see groupLines()})
            $words = [];
            if (preg_match_all('#<word\s+xMin="([\d.]+)"\s+yMin="([\d.]+)"\s+xMax="([\d.]+)"\s+yMax="([\d.]+)">(.*?)</word>#s', $pageMatch[3], $wordMatches, PREG_SET_ORDER) > 0) {
                foreach ($wordMatches as $w) {
                    $words[] = [
                        'text' => html_entity_decode($w[5], ENT_QUOTES | ENT_XML1, 'UTF-8'),
                        'x0' => (float) $w[1],
                        'y0' => (float) $w[2],
                        'x1' => (float) $w[3],
                        'y1' => (float) $w[4],
                    ];
                }
            }
            $result[$pageNumber] = ['width' => (float) $pageMatch[1], 'height' => (float) $pageMatch[2], 'lines' => self::groupLines($words)];
        }

        return $result;
    }

    /**
     * Woerter zu Zeilen: Woerter, deren senkrechte Mitte innerhalb der halben
     * Zeilenhoehe (mindestens {@see LINE_TOLERANCE}) der laufenden Zeile liegt,
     * gehoeren dazu; innerhalb der Zeile nach x sortiert.
     *
     * @param list<array{text: string, x0: float, y0: float, x1: float, y1: float}> $words
     * @return list<list<array{text: string, x0: float, y0: float, x1: float, y1: float}>>
     */
    public static function groupLines(array $words): array {
        usort($words, static fn (array $a, array $b): int => ($a['y0'] + $a['y1']) <=> ($b['y0'] + $b['y1']) ?: $a['x0'] <=> $b['x0']);
        $lines = [];
        $current = [];
        $centerSum = 0.0;
        $heightSum = 0.0;
        foreach ($words as $word) {
            $center = ($word['y0'] + $word['y1']) / 2;
            $height = max(0.1, $word['y1'] - $word['y0']);
            if ($current !== []) {
                $n = count($current);
                $tolerance = max(self::LINE_TOLERANCE, ($heightSum / $n) / 2);
                if (abs($center - $centerSum / $n) > $tolerance) {
                    usort($current, static fn (array $a, array $b): int => $a['x0'] <=> $b['x0']);
                    $lines[] = $current;
                    $current = [];
                    $centerSum = 0.0;
                    $heightSum = 0.0;
                }
            }
            $current[] = $word;
            $centerSum += $center;
            $heightSum += $height;
        }
        if ($current !== []) {
            usort($current, static fn (array $a, array $b): int => $a['x0'] <=> $b['x0']);
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Rechtecke der Treffer in einer Zeile.
     *
     * @param list<array{text: string, x0: float, y0: float, x1: float, y1: float}> $words
     * @param list<string> $needles normalisiert
     * @return list<array{x0: float, y0: float, x1: float, y1: float}>
     */
    private static function matchLine(array $words, array $needles, bool $wholeLine, float $pageWidth): array {
        // Zeilentext mit Zuordnung Zeichen -> Wort
        $text = '';
        $owner = [];
        foreach ($words as $index => $word) {
            $normalized = self::normalize($word['text']);
            if ($normalized === '') {
                continue;
            }
            if ($text !== '') {
                $text .= ' ';
                $owner[] = -1;
            }
            $length = mb_strlen($normalized);
            $text .= $normalized;
            for ($i = 0; $i < $length; $i++) {
                $owner[] = $index;
            }
        }
        if ($text === '') {
            return [];
        }

        $hit = [];
        foreach ($needles as $needle) {
            $offset = 0;
            $needleLength = mb_strlen($needle);
            while (($position = mb_strpos($text, $needle, $offset)) !== false) {
                for ($i = $position; $i < $position + $needleLength; $i++) {
                    if (($owner[$i] ?? -1) >= 0) {
                        $hit[$owner[$i]] = true;
                    }
                }
                $offset = $position + max(1, $needleLength);
            }
        }
        if ($hit === []) {
            return [];
        }

        $y0 = min(array_map(static fn (array $w): float => $w['y0'], $words));
        $y1 = max(array_map(static fn (array $w): float => $w['y1'], $words));
        if ($wholeLine) {
            return [['x0' => 0.0, 'y0' => max(0.0, $y0 - self::LINE_PADDING), 'x1' => $pageWidth, 'y1' => $y1 + self::LINE_PADDING]];
        }

        // Zusammenhängende Trefferwörter zu einem Rechteck je Lauf
        $rects = [];
        $current = null;
        foreach ($words as $index => $word) {
            if (!isset($hit[$index])) {
                if ($current !== null) {
                    $rects[] = $current;
                    $current = null;
                }
                continue;
            }
            $box = ['x0' => $word['x0'] - self::WORD_PADDING, 'y0' => $word['y0'] - self::WORD_PADDING, 'x1' => $word['x1'] + self::WORD_PADDING, 'y1' => $word['y1'] + self::WORD_PADDING];
            if ($current === null) {
                $current = $box;
            } else {
                $current = ['x0' => min($current['x0'], $box['x0']), 'y0' => min($current['y0'], $box['y0']), 'x1' => max($current['x1'], $box['x1']), 'y1' => max($current['y1'], $box['y1'])];
            }
        }
        if ($current !== null) {
            $rects[] = $current;
        }

        return $rects;
    }

    /** Kleinschreibung, einfache Leerzeichen, ohne Rand */
    public static function normalize(string $text): string {
        $text = StringHelper::normalizeWhitespace($text);

        return trim(mb_strtolower($text));
    }

    private static function number(float $value): string {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
