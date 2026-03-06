<?php
/*
 * Created on   : Thu Mar 06 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFMergeHelper.php
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
 * Helper-Klasse für das Zusammenführen von PDF-Dateien.
 *
 * Nutzt pdfunite (poppler-utils) um mehrere PDFs zu einer Datei zusammenzuführen.
 */
final class PDFMergeHelper {
    use ErrorLog;

    /**
     * Führt mehrere PDF-Dateien zu einer zusammen.
     *
     * @param string[] $inputPaths Pfade zu den Quell-PDFs (mindestens 2)
     * @param string $outputPath Pfad zur Ziel-PDF
     * @return bool true bei Erfolg
     */
    public static function merge(array $inputPaths, string $outputPath): bool {
        if (count($inputPaths) < 2) {
            self::logError('Mindestens 2 PDF-Dateien zum Zusammenführen erforderlich');
            return false;
        }

        // Alle Eingabedateien validieren
        foreach ($inputPaths as $path) {
            if (!File::exists($path)) {
                self::logError('PDF-Datei nicht gefunden', ['path' => $path]);
                return false;
            }

            if (!PDFHelper::isValidPdf($path)) {
                self::logError('Ungültige PDF-Datei', ['path' => $path]);
                return false;
            }
        }

        if (!self::isAvailable()) {
            self::logError('pdfunite ist nicht verfügbar');
            return false;
        }

        // pdfunite-Pfad aus Config holen
        $config = Config::getInstance();
        $executablePath = $config->getExecutablePathWithFallback('pdfunite');

        // Befehl zusammenbauen: pdfunite input1.pdf input2.pdf ... output.pdf
        $parts = [escapeshellarg($executablePath)];
        foreach ($inputPaths as $path) {
            $parts[] = escapeshellarg($path);
        }
        $parts[] = escapeshellarg($outputPath);

        $command = implode(' ', $parts);

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode) || $returnCode !== 0) {
            self::logError('PDF-Zusammenführung fehlgeschlagen', [
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return false;
        }

        if (!File::exists($outputPath)) {
            self::logError('Zusammengeführte PDF wurde nicht erstellt', ['path' => $outputPath]);
            return false;
        }

        self::logInfo('PDFs erfolgreich zusammengeführt', [
            'inputCount' => count($inputPaths),
            'output' => $outputPath,
            'size' => File::size($outputPath),
        ]);

        return true;
    }

    /**
     * Prüft ob pdfunite verfügbar ist.
     */
    public static function isAvailable(): bool {
        $config = Config::getInstance();
        return $config->isExecutableAvailable('pdfunite');
    }
}
