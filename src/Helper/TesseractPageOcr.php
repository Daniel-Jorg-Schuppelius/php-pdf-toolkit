<?php
/*
 * Created on   : Mon Oct 19 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TesseractPageOcr.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;
use PDFToolkit\Readers\TesseractReader;
use Throwable;

/**
 * OCR je Seite mit Wortkoordinaten (seit 0.27.0): rendert die Seiten eines
 * PDF (pdftoppm), entzerrt sie wie der Textpfad und lässt Tesseract je Seite
 * eine TSV-Ausgabe schreiben. Gemeinsame Pipeline für die zeilentreue
 * Rekonstruktion ({@see PDFBboxLayoutHelper::ocrRowAlignedText()}) und die
 * Wortboxen ({@see PDFWordBoxHelper::fromOcr()}).
 */
final class TesseractPageOcr {
    use ErrorLog;

    /**
     * @return array{pages: array<int, string>, error: ?string} Seitennummer (1-basiert) → TSV; `error` nennt den Grund, wenn nichts entstand
     */
    public static function tsvPerPage(string $pdfPath, string $language, int $dpi, int $psm): array {
        $settings = TesseractReader::ocrSettings();
        $config = Config::getInstance();
        if ($config->getShellExecutable('tesseract') === null || $config->getShellExecutable('pdftoppm') === null) {
            return ['pages' => [], 'error' => "tesseract/pdftoppm nicht verfügbar für: {$pdfPath}"];
        }
        // Privates Arbeitsverzeichnis: Seitenbilder und OCR-Ausgaben sind Dokumentinhalt
        try {
            $tempDir = TempPath::directory('pdfocrtsv');
        } catch (Throwable $e) {
            return ['pages' => [], 'error' => 'Temp-Verzeichnis nicht erstellbar für OCR: ' . $e->getMessage()];
        }
        try {
            $renderCmd = $config->buildCommand('pdftoppm', [
                '[DPI]' => (string) $dpi,
                '[PDF-FILE]' => $pdfPath,
                '[OUTPUT-PREFIX]' => $tempDir . '/page',
            ]);
            if ($renderCmd === null) {
                return ['pages' => [], 'error' => "Kein pdftoppm-Kommando erzeugbar für: {$pdfPath}"];
            }
            $out = [];
            $rc = 0;
            Shell::executeShellCommand($renderCmd, $out, $rc, timeout: ToolTimeout::forRendering($pdfPath));
            if ($rc === Shell::EXIT_TIMEOUT) {
                // Abgebrochen: die gerenderten Seiten sind unvollständig - kein Teilergebnis
                return ['pages' => [], 'error' => "pdftoppm abgebrochen (Zeitgrenze) für: {$pdfPath}"];
            }
            $pages = glob("$tempDir/page-*.png") ?: [];
            if ($pages === []) {
                return ['pages' => [], 'error' => "Keine Seiten gerendert für: {$pdfPath}"];
            }
            natsort($pages);
            $pages = array_values($pages);
            // Gleiche Vorverarbeitung und Erkennungsparameter wie der Textpfad (TesseractReader).
            if ($settings['preprocess'] && !TesseractReader::deskewPageImages($config, $pages, $settings['denoise'])) {
                return ['pages' => [], 'error' => "Deskew abgebrochen (Zeitgrenze) für: {$pdfPath}"];
            }
            $recognitionArgs = TesseractReader::recognitionArgs($settings['noDict'], $settings['whitelist']);
            $tessData = TesseractDataHelper::getUsableDataPath($language);
            // Ein Tesseract-Prozess je Seite, mehrere gleichzeitig: die Seiten sind
            // voneinander unabhängig, jeder Prozess läuft einthreadig.
            $commands = [];
            foreach ($pages as $png) {
                // TSV per Parameter erzwingen (nicht per "tsv"-Configfile: das liegt im
                // System-tessdata und fehlt, sobald TESSDATA_PREFIX aufs Toolkit zeigt).
                $tessCmd = $config->buildCommand('tesseract', [
                    '[INPUT]' => $png,
                    '[OUTPUT]' => $png . '_ocr',
                    '[LANG]' => $language,
                    '[PSM]' => (string) $psm,
                ], [...$recognitionArgs, '-c', 'tessedit_create_tsv=1']);
                if ($tessCmd === null) {
                    continue;
                }
                if (!empty($tessData) && Folder::exists($tessData)) {
                    $tessCmd = 'TESSDATA_PREFIX=' . escapeshellarg($tessData) . ' ' . $tessCmd;
                }
                $commands[$png] = TesseractReader::OMP_SINGLE_THREAD . ' ' . $tessCmd . ' 2>/dev/null';
            }
            $exitCodes = ParallelShell::run($commands, null, ToolTimeout::PAGE);
            if (in_array(Shell::EXIT_TIMEOUT, $exitCodes, true)) {
                // Eine abgebrochene Seite fehlte sonst still
                return ['pages' => [], 'error' => "Tesseract abgebrochen (Zeitgrenze) für: {$pdfPath}"];
            }
            // Einlesen in Seitenreihenfolge – die Prozesse enden in beliebiger Folge.
            $result = [];
            foreach ($pages as $index => $png) {
                $tsvFile = $png . '_ocr.tsv';
                if (!isset($commands[$png]) || !File::exists($tsvFile)) {
                    continue;
                }
                $result[$index + 1] = File::read($tsvFile);
            }

            return ['pages' => $result, 'error' => null];
        } finally {
            Folder::delete($tempDir, true);
        }
    }
}
