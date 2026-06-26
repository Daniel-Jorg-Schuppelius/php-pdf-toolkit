<?php
/*
 * Created on   : Mon Jun 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFBboxLayoutHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\Data\StringHelper;
use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * Koordinaten-basierte Zeilen-Reassembly für gescannte/columnar PDFs.
 *
 * Manche (gescannte) Dokumente stellen zusammengehörigen Text in getrennten
 * Spalten dar, deren Wörter pdftotext (auch mit -layout) bzw. PDFBox in
 * verschiedene Textzeilen zerlegt. Über `pdftotext -bbox` werden die Wort-
 * Koordinaten gelesen und Wörter mit (annähernd) gleicher y-Position pro Seite
 * zu EINER Zeile zusammengefügt (nach x sortiert) — so steht ein Datensatz wieder
 * vollständig auf einer Zeile.
 *
 * Wird über {@see PDFTextProvider::rowAlignedText()} bzw.
 * {@see \PDFToolkit\Enums\PDFTextVariant::RowAligned} angeboten.
 */
final class PDFBboxLayoutHelper {
    use ErrorLog;

    /** y-Toleranz (PDF-Punkte), innerhalb derer Wörter als selbe Zeile gelten. */
    private const Y_TOLERANCE = 3.0;

    /** y-Toleranz (Pixel) für OCR-Reassembly bei 300 dpi (ca. halbe Zeilenhöhe). */
    private const OCR_Y_TOLERANCE = 15.0;

    /**
     * Liefert den spaltentreu zeilen-reassemblierten Text eines PDF.
     *
     * @return string Zeilen, je eine pro Bildschirm-Zeile, Wörter nach x sortiert.
     *                Leerer String, wenn das PDF ungültig ist oder pdftotext fehlt.
     */
    public static function rowAlignedText(string $pdfPath): string {
        if (!File::exists($pdfPath) || !PDFHelper::isValidPdf($pdfPath)) {
            return self::logWarningAndReturn('', "Keine gültige PDF-Datei für bbox-Reassembly: {$pdfPath}");
        }

        $config = Config::getInstance();
        if ($config->getShellExecutable('pdftotext-bbox') === null) {
            return self::logWarningAndReturn('', "pdftotext-bbox nicht verfügbar – bbox-Reassembly übersprungen: {$pdfPath}");
        }

        $tempFile = sys_get_temp_dir() . '/pdfbbox_' . uniqid() . '.xml';
        $command = $config->buildCommand('pdftotext-bbox', [
            '[PDF-FILE]' => $pdfPath,
            '[TEXT-FILE]' => $tempFile,
        ]);
        if ($command === null) {
            return self::logWarningAndReturn('', "Kein pdftotext-bbox-Kommando erzeugbar für: {$pdfPath}");
        }

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0 || !File::exists($tempFile)) {
            File::delete($tempFile);
            return self::logWarningAndReturn('', "pdftotext-bbox lieferte keine Ausgabe (Code {$returnCode}) für: {$pdfPath}");
        }

        $xml = File::read($tempFile);
        File::delete($tempFile);

        if (trim($xml) === '') {
            return '';
        }

        return self::reassemble($xml);
    }

    /**
     * Fügt die bbox-XHTML-Wörter pro Seite zeilenweise (y-Cluster, x-sortiert) zusammen.
     */
    private static function reassemble(string $xml): string {
        $lines = [];
        foreach (preg_split('#</page>#', $xml) ?: [] as $page) {
            if (!preg_match_all('#<word\s+xMin="([\d.]+)"\s+yMin="([-\d.]+)"[^>]*>(.*?)</word>#', $page, $words, PREG_SET_ORDER)) {
                continue;
            }

            $items = [];
            foreach ($words as $w) {
                $items[] = ['y' => (float) $w[2], 'x' => (float) $w[1], 't' => StringHelper::htmlEntitiesToText($w[3])];
            }
            $lines[] = self::reassembleItems($items, self::Y_TOLERANCE);
        }

        return implode("\n", $lines);
    }

    /**
     * Liefert spaltentreu zeilen-reassemblierten OCR-Text eines GESCANNTEN PDF.
     *
     * Im Gegensatz zu {@see rowAlignedText()} (pdftotext -bbox, braucht Textlayer)
     * rendert dies jede Seite (pdftoppm) und liest Wort-Koordinaten via Tesseract
     * TSV. So lassen sich auch reine Bild-PDFs spaltentreu rekonstruieren – z.B.
     * Tabellen, die OCR sonst spaltenweise (alle Daten, dann alle Beträge …)
     * auseinanderreißt.
     *
     * @param string $pdfPath Pfad zum (gescannten) PDF.
     * @param string $language Tesseract-Sprache(n), Standard "deu+eng".
     * @param int $dpi Render-Auflösung (Standard 300).
     * @return string Zeilen, je eine pro Bild-Zeile (Wörter nach x sortiert), Seiten mit "\n" getrennt.
     *                Leerer String, wenn Tooling fehlt oder OCR nichts liefert.
     */
    public static function ocrRowAlignedText(string $pdfPath, string $language = 'deu+eng', int $dpi = 300): string {
        if (!File::exists($pdfPath) || !PDFHelper::isValidPdf($pdfPath)) {
            return self::logWarningAndReturn('', "Keine gültige PDF-Datei für OCR-Reassembly: {$pdfPath}");
        }

        $config = Config::getInstance();
        if ($config->getShellExecutable('tesseract') === null || $config->getShellExecutable('pdftoppm') === null) {
            return self::logWarningAndReturn('', "tesseract/pdftoppm nicht verfügbar – OCR-Reassembly übersprungen: {$pdfPath}");
        }

        $tempDir = sys_get_temp_dir() . '/pdfocrrow_' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            return self::logWarningAndReturn('', "Temp-Verzeichnis nicht erstellbar für OCR-Reassembly: {$tempDir}");
        }

        try {
            $renderCmd = $config->buildCommand('pdftoppm', [
                '[DPI]' => (string) $dpi,
                '[PDF-FILE]' => $pdfPath,
                '[OUTPUT-PREFIX]' => $tempDir . '/page',
            ]);
            if ($renderCmd === null) {
                return self::logWarningAndReturn('', "Kein pdftoppm-Kommando erzeugbar für: {$pdfPath}");
            }
            $out = [];
            $rc = 0;
            Shell::executeShellCommand($renderCmd, $out, $rc);

            $pages = glob("$tempDir/page-*.png") ?: [];
            if ($pages === []) {
                return self::logWarningAndReturn('', "Keine Seiten gerendert für OCR-Reassembly: {$pdfPath}");
            }
            natsort($pages);

            $tessData = TesseractDataHelper::getUsableDataPath($language);
            $pageTexts = [];
            foreach ($pages as $png) {
                $outBase = $png . '_ocr';
                // TSV-Ausgabe per Parameter erzwingen (NICHT per "tsv"-Configfile:
                // das liegt im System-tessdata/configs und fehlt, sobald
                // TESSDATA_PREFIX auf das Toolkit-Datenverzeichnis zeigt).
                $tessCmd = $config->buildCommand('tesseract', [
                    '[INPUT]' => $png,
                    '[OUTPUT]' => $outBase,
                    '[LANG]' => $language,
                    '[PSM]' => '3',
                ], ['-c', 'tessedit_create_tsv=1']);
                if ($tessCmd === null) {
                    continue;
                }
                if (!empty($tessData) && Folder::exists($tessData)) {
                    $tessCmd = 'TESSDATA_PREFIX=' . escapeshellarg($tessData) . ' ' . $tessCmd;
                }
                $tessCmd .= ' 2>/dev/null';
                $out = [];
                $rc = 0;
                Shell::executeShellCommand($tessCmd, $out, $rc);

                if (!File::exists($outBase . '.tsv')) {
                    continue;
                }
                $items = self::parseTsvItems(File::read($outBase . '.tsv'));
                if ($items !== []) {
                    $pageTexts[] = self::reassembleItems($items, self::OCR_Y_TOLERANCE);
                }
            }

            return implode("\n", $pageTexts);
        } finally {
            Folder::delete($tempDir, true);
        }
    }

    /**
     * Parst Tesseract-TSV zu Wort-Items (x=left, y=top, t=text); Kopf/Leeres weg.
     *
     * @return list<array{x: float, y: float, t: string}>
     */
    private static function parseTsvItems(string $tsv): array {
        $items = [];
        foreach (preg_split('/\r?\n/', $tsv) ?: [] as $i => $line) {
            if ($i === 0 || trim($line) === '') {
                continue; // Header / Leerzeilen
            }
            $f = explode("\t", $line);
            if (count($f) < 12) {
                continue;
            }
            $text = trim($f[11]);
            if ($text === '') {
                continue;
            }
            $items[] = ['x' => (float) $f[6], 'y' => (float) $f[7], 't' => $text];
        }
        return $items;
    }

    /**
     * Clustert Wort-Items (x, y, text) nach y zu Zeilen (gleitende Toleranz) und
     * sortiert jede Zeile nach x.
     *
     * @param list<array{x: float, y: float, t: string}> $items
     */
    private static function reassembleItems(array $items, float $yTolerance): string {
        usort($items, static fn (array $a, array $b): int => $a['y'] <=> $b['y']);

        $lines = [];
        $row = [];
        $rowY = null;
        foreach ($items as $it) {
            if ($rowY !== null && $it['y'] - $rowY > $yTolerance) {
                usort($row, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
                $lines[] = implode(' ', array_column($row, 't'));
                $row = [];
            }
            $row[] = $it;
            $rowY = $it['y']; // gleitend: erlaubt leichten Spalten-Versatz innerhalb einer Zeile
        }
        if ($row !== []) {
            usort($row, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
            $lines[] = implode(' ', array_column($row, 't'));
        }

        return implode("\n", $lines);
    }
}
