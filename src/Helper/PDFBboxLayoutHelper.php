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
use CommonToolkit\Helper\FileSystem\File;
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

            // Alle Wörter sammeln, nach y sortieren, dann zu Zeilen clustern.
            $items = [];
            foreach ($words as $w) {
                $items[] = ['y' => (float) $w[2], 'x' => (float) $w[1], 't' => StringHelper::htmlEntitiesToText($w[3])];
            }
            usort($items, static fn(array $a, array $b): int => $a['y'] <=> $b['y']);

            $row = [];
            $rowY = null;
            foreach ($items as $it) {
                if ($rowY !== null && $it['y'] - $rowY > self::Y_TOLERANCE) {
                    usort($row, static fn(array $a, array $b): int => $a['x'] <=> $b['x']);
                    $lines[] = implode(' ', array_column($row, 't'));
                    $row = [];
                }
                $row[] = $it;
                $rowY = $it['y']; // gleitend: erlaubt leichten Spalten-Versatz innerhalb einer Zeile
            }
            if ($row !== []) {
                usort($row, static fn(array $a, array $b): int => $a['x'] <=> $b['x']);
                $lines[] = implode(' ', array_column($row, 't'));
            }
        }

        return implode("\n", $lines);
    }
}
