<?php
/*
 * Created on   : Thu Mar 06 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFSplitHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Shell;
use PDFToolkit\Config\Config;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Helper-Klasse für das Aufteilen von PDF-Dateien.
 *
 * Nutzt pdftk (cat) um PDF-Seiten zu extrahieren.
 */
final class PDFSplitHelper {
    use ErrorLog;

    /**
     * Extrahiert einen Seitenbereich aus einer PDF.
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param int $firstPage Erste Seite (1-basiert)
     * @param int $lastPage Letzte Seite (1-basiert)
     * @return bool true bei Erfolg
     */
    public static function extractPages(
        string $inputPath,
        string $outputPath,
        int $firstPage,
        int $lastPage
    ): bool {
        if (!File::exists($inputPath)) {
            self::logError('PDF-Datei nicht gefunden', ['path' => $inputPath]);
            return false;
        }

        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return false;
        }

        if (!self::isAvailable()) {
            self::logError('pdftk ist nicht verfügbar');
            return false;
        }

        $pageRange = "{$firstPage}-{$lastPage}";

        $config = Config::getInstance();
        $command = $config->buildCommand('pdftk-cat', [
            '[INPUT]' => $inputPath,
            '[PAGE-RANGE]' => $pageRange,
            '[OUTPUT]' => $outputPath,
        ]);

        if ($command === null) {
            self::logError('Konnte pdftk-cat Befehl nicht erstellen');
            return false;
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode) || $returnCode !== 0) {
            self::logError('PDF-Seitenextraktion fehlgeschlagen', [
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
                'pageRange' => $pageRange,
            ]);
            return false;
        }

        if (!File::exists($outputPath)) {
            self::logError('Extrahierte PDF wurde nicht erstellt', ['path' => $outputPath]);
            return false;
        }

        self::logInfo('PDF-Seiten erfolgreich extrahiert', [
            'input' => $inputPath,
            'output' => $outputPath,
            'pages' => $pageRange,
            'size' => File::size($outputPath),
        ]);

        return true;
    }

    /**
     * Teilt eine PDF in einzelne Seiten auf.
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputDir Verzeichnis für die einzelnen Seiten
     * @param string $filenamePattern Dateiname-Pattern mit %d Platzhalter für Seitennummer
     * @return string[] Pfade der erzeugten Dateien, leer bei Fehler
     */
    public static function splitToPages(
        string $inputPath,
        string $outputDir,
        string $filenamePattern = 'page_%03d.pdf'
    ): array {
        $pageCount = self::getPageCount($inputPath);
        if ($pageCount === null || $pageCount === 0) {
            return [];
        }

        Folder::create($outputDir);
        $outputFiles = [];

        for ($page = 1; $page <= $pageCount; $page++) {
            $filename = sprintf($filenamePattern, $page);
            $outputPath = $outputDir . '/' . $filename;

            if (self::extractPages($inputPath, $outputPath, $page, $page)) {
                $outputFiles[] = $outputPath;
            } else {
                self::logError('Fehler beim Extrahieren von Seite', ['page' => $page]);
            }
        }

        return $outputFiles;
    }

    /**
     * Teilt eine PDF in Blöcke mit einer bestimmten Seitenzahl auf.
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputDir Verzeichnis für die Teile
     * @param int $pagesPerFile Seiten pro Datei
     * @param string $filenamePattern Dateiname-Pattern mit %d Platzhalter für Teilnummer
     * @return string[] Pfade der erzeugten Dateien, leer bei Fehler
     */
    public static function splitByPageCount(
        string $inputPath,
        string $outputDir,
        int $pagesPerFile,
        string $filenamePattern = 'part_%03d.pdf'
    ): array {
        $pageCount = self::getPageCount($inputPath);
        if ($pageCount === null || $pageCount === 0) {
            return [];
        }

        if ($pagesPerFile < 1) {
            $pagesPerFile = 1;
        }

        Folder::create($outputDir);
        $outputFiles = [];
        $partNumber = 1;

        for ($startPage = 1; $startPage <= $pageCount; $startPage += $pagesPerFile) {
            $endPage = min($startPage + $pagesPerFile - 1, $pageCount);
            $filename = sprintf($filenamePattern, $partNumber);
            $outputPath = $outputDir . '/' . $filename;

            if (self::extractPages($inputPath, $outputPath, $startPage, $endPage)) {
                $outputFiles[] = $outputPath;
            } else {
                self::logError('Fehler beim Extrahieren der Seiten', [
                    'startPage' => $startPage,
                    'endPage' => $endPage,
                ]);
            }

            $partNumber++;
        }

        return $outputFiles;
    }

    /**
     * Ermittelt die Seitenanzahl einer PDF.
     */
    public static function getPageCount(string $inputPath): ?int {
        $metadata = PDFHelper::getMetadata($inputPath);
        if (empty($metadata['Pages'])) {
            self::logError('Konnte Seitenanzahl nicht ermitteln', ['path' => $inputPath]);
            return null;
        }

        return (int) $metadata['Pages'];
    }

    /**
     * Prüft ob pdftk verfügbar ist.
     */
    public static function isAvailable(): bool {
        $config = Config::getInstance();
        return $config->isExecutableAvailable('pdftk-cat');
    }
}
