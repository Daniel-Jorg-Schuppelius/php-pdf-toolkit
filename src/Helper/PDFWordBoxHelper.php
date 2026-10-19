<?php
/*
 * Created on   : Mon Oct 19 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFWordBoxHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\File;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Entities\WordBox;
use PDFToolkit\Readers\TesseractReader;

/**
 * Wortboxen eines PDF (seit 0.27.0): jedes Wort mit seinem Rechteck relativ
 * zur Seitengröße — aus dem Textlayer (pdftotext -bbox-layout, wie der
 * Schwärzungshelfer) oder per OCR (Tesseract-TSV auf gerenderten Seiten).
 * Grundlage für zonenbasierte Erkennung: „Rechnungsnummer steht bei diesem
 * Lieferanten oben rechts“.
 */
final class PDFWordBoxHelper {
    use ErrorLog;

    /**
     * Wortboxen aus dem Textlayer; leer ohne Textlayer oder Werkzeug.
     *
     * @return list<WordBox>
     */
    public static function fromTextLayer(string $pdfPath): array {
        if (!File::exists($pdfPath) || !PDFHelper::isValidPdf($pdfPath)) {
            return self::logWarningAndReturn([], "Keine gültige PDF-Datei für Wortboxen: {$pdfPath}");
        }
        $boxes = [];
        foreach (PDFRedactHelper::pageLines($pdfPath) as $page => $layout) {
            $width = (float) $layout['width'];
            $height = (float) $layout['height'];
            if ($width <= 0.0 || $height <= 0.0) {
                continue;
            }
            foreach ($layout['lines'] as $line) {
                foreach ($line as $word) {
                    $text = trim((string) $word['text']);
                    if ($text === '') {
                        continue;
                    }
                    $boxes[] = self::box((int) $page, $word['x0'], $word['y0'], $word['x1'] - $word['x0'], $word['y1'] - $word['y0'], $width, $height, $text, null);
                }
            }
        }

        return $boxes;
    }

    /**
     * Wortboxen per OCR (gescannte PDFs): Seiten rendern, Tesseract-TSV lesen.
     * Pixelkoordinaten werden an der Bildgröße normiert, die Tesseract in der
     * Seitenzeile (Ebene 1) nennt.
     *
     * @return list<WordBox>
     */
    public static function fromOcr(string $pdfPath, string $language = 'deu+eng', ?int $dpi = null, ?int $psm = null): array {
        $settings = TesseractReader::ocrSettings();
        $dpi ??= $settings['dpi'];
        $psm ??= $settings['psm'];
        if (!File::exists($pdfPath) || !PDFHelper::isValidPdf($pdfPath)) {
            return self::logWarningAndReturn([], "Keine gültige PDF-Datei für OCR-Wortboxen: {$pdfPath}");
        }
        $ocr = TesseractPageOcr::tsvPerPage($pdfPath, $language, $dpi, $psm);
        if ($ocr['error'] !== null) {
            return self::logWarningAndReturn([], $ocr['error'] . ' – keine OCR-Wortboxen');
        }
        $boxes = [];
        foreach ($ocr['pages'] as $page => $tsv) {
            foreach (self::parseTsvPage($tsv, (int) $page) as $box) {
                $boxes[] = $box;
            }
        }

        return $boxes;
    }

    /**
     * Tesseract-TSV einer Seite: Ebene 1 nennt die Bildgröße, Ebene 5 die
     * Wörter (left, top, width, height, conf, text). Ohne Seitenzeile wird
     * die Bildgröße aus der äußersten Wortlage geschätzt.
     *
     * @return list<WordBox>
     */
    public static function parseTsvPage(string $tsv, int $page): array {
        $imageWidth = 0.0;
        $imageHeight = 0.0;
        $words = [];
        foreach (preg_split('/\r?\n/', $tsv) ?: [] as $index => $line) {
            if ($index === 0 || trim($line) === '') {
                continue; // Kopfzeile / Leerzeilen
            }
            $f = explode("\t", $line);
            if (count($f) < 12) {
                continue;
            }
            $level = (int) $f[0];
            if ($level === 1) {
                $imageWidth = (float) $f[8];
                $imageHeight = (float) $f[9];

                continue;
            }
            if ($level !== 5) {
                continue;
            }
            $text = trim($f[11]);
            if ($text === '') {
                continue;
            }
            $words[] = ['x' => (float) $f[6], 'y' => (float) $f[7], 'w' => (float) $f[8], 'h' => (float) $f[9], 'conf' => (float) $f[10], 't' => $text];
        }
        if ($words === []) {
            return [];
        }
        if ($imageWidth <= 0.0 || $imageHeight <= 0.0) {
            $imageWidth = max(array_map(static fn (array $w): float => $w['x'] + $w['w'], $words));
            $imageHeight = max(array_map(static fn (array $w): float => $w['y'] + $w['h'], $words));
        }
        $boxes = [];
        foreach ($words as $w) {
            $boxes[] = self::box($page, $w['x'], $w['y'], $w['w'], $w['h'], $imageWidth, $imageHeight, $w['t'], $w['conf'] >= 0 ? $w['conf'] : null);
        }

        return $boxes;
    }

    private static function box(int $page, float $x, float $y, float $w, float $h, float $pageWidth, float $pageHeight, string $text, ?float $confidence): WordBox {
        $clamp = static fn (float $value): float => max(0.0, min(1.0, $value));

        return new WordBox(
            $page,
            $clamp($x / $pageWidth),
            $clamp($y / $pageHeight),
            $clamp($w / $pageWidth),
            $clamp($h / $pageHeight),
            $text,
            $confidence,
        );
    }
}
