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
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * Helper-Klasse für das Aufteilen von PDF-Dateien.
 *
 * Seiten werden zuerst mit `mutool merge` herausgelöst: Es baut eine neue PDF
 * nur aus den Objekten der gewählten Seiten. Danach pdftk (cat), zuletzt
 * poppler (pdfseparate + pdfunite) - poppler zieht bei Word-PDFs mit
 * seitenübergreifenden Verweisen das ganze Dokument mit (16 Seiten aus einer
 * 13,5-MB-Datei: 44,5 MB statt 434 KB mit mutool).
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

        if (self::extractWithMutool($inputPath, $outputPath, "{$firstPage}-{$lastPage}", $lastPage - $firstPage + 1)) {
            return true;
        }

        if (!self::isAvailable()) {
            // Ohne pdftk übernimmt poppler: pdfseparate vereinzelt, pdfunite fügt
            // wieder zusammen. Gleiche Ausgabe, nur zwei Werkzeuge statt einem.
            if (self::isPopplerAvailable()) {
                return self::extractPagesWithPoppler($inputPath, $outputPath, range($firstPage, $lastPage));
            }
            self::logError('Weder pdftk noch poppler (pdfseparate + pdfunite) verfügbar');
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
        if (!Shell::executeShellCommand($command, $output, $returnCode, timeout: ToolTimeout::forSize($inputPath)) || $returnCode !== 0) {
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
     * Vereinzelt eine Seite mit pdfseparate nach <tempDir>/p-<seite>.pdf.
     */
    private static function separatePage(string $pdfseparate, string $inputPath, string $tempDir, int $page): bool {
        // pdfseparate setzt %d im Muster durch die Seitennummer
        $command = escapeshellarg($pdfseparate) . ' -f ' . $page . ' -l ' . $page . ' '
            . escapeshellarg($inputPath) . ' ' . escapeshellarg($tempDir . '/p-%d.pdf');
        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command, $output, $returnCode, timeout: ToolTimeout::forSize($inputPath)) || $returnCode !== 0) {
            self::logError('PDF-Seite konnte nicht vereinzelt werden (pdfseparate)', [
                'page' => $page,
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return false;
        }
        if (!File::exists($tempDir . '/p-' . $page . '.pdf')) {
            self::logError('pdfseparate hat keine Seitendatei erzeugt', ['page' => $page]);
            return false;
        }

        return true;
    }

    /**
     * Prüft ob pdftk verfügbar ist.
     */
    public static function isAvailable(): bool {
        $config = Config::getInstance();
        return $config->isExecutableAvailable('pdftk-cat');
    }
    /**
     * Extrahiert eine beliebige Seitenmenge in der angegebenen Reihenfolge,
     * etwa alle Querformatseiten einer Datei. Seiten dürfen sich wiederholen.
     *
     * @param string $inputPath Pfad zur Quell-PDF
     * @param string $outputPath Pfad zur Ziel-PDF
     * @param list<int> $pages 1-basierte Seitennummern in Zielreihenfolge
     * @return bool true bei Erfolg
     */
    public static function extractPageSet(string $inputPath, string $outputPath, array $pages): bool {
        $pages = array_values(array_filter(array_map('intval', $pages), static fn (int $page): bool => $page >= 1));
        if ($pages === []) {
            self::logError('Keine Seiten zum Extrahieren angegeben', ['path' => $inputPath]);
            return false;
        }
        if (!File::exists($inputPath)) {
            self::logError('PDF-Datei nicht gefunden', ['path' => $inputPath]);
            return false;
        }
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return false;
        }
        if (self::extractWithMutool($inputPath, $outputPath, implode(',', $pages), count($pages))) {
            return true;
        }
        if (self::isAvailable()) {
            return self::extractPageSetWithPdftk($inputPath, $outputPath, $pages);
        }
        if (self::isPopplerAvailable()) {
            return self::extractPagesWithPoppler($inputPath, $outputPath, $pages);
        }
        self::logError('Weder pdftk noch poppler (pdfseparate + pdfunite) verfügbar');
        return false;
    }

    /**
     * MuPDF-Weg: `mutool merge -o OUT IN 1-3,5` übernimmt nur die Objekte der
     * gewählten Seiten in eine frische Datei. Scheitert mutool (fehlt, alte
     * Version, beschädigte oder verschlüsselte Datei) oder stimmt die
     * Seitenzahl des Ergebnisses nicht, übernehmen pdftk bzw. poppler.
     *
     * @param string $pages Seitenliste für mutool, z. B. "2-5" oder "1,3,3"
     * @param int $expectedPages Seitenzahl, die das Ergebnis haben muss
     */
    private static function extractWithMutool(string $inputPath, string $outputPath, string $pages, int $expectedPages): bool {
        if (!self::isMutoolMergeAvailable()) {
            return false;
        }

        $command = Config::getInstance()->buildCommand('mutool-merge', [
            '[INPUT]' => $inputPath,
            '[OUTPUT]' => $outputPath,
            '[PAGES]' => $pages,
        ]);
        if ($command === null) {
            return false;
        }

        $output = [];
        $returnCode = 0;
        $ok = Shell::executeShellCommand($command, $output, $returnCode, timeout: ToolTimeout::forSize($inputPath))
            && $returnCode === 0
            && File::exists($outputPath)
            && self::getPageCount($outputPath) === $expectedPages;
        if (!$ok) {
            self::logWarning('mutool merge fehlgeschlagen, Rückfall auf pdftk/poppler', [
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
                'pages' => $pages,
            ]);
            if (File::exists($outputPath)) {
                File::delete($outputPath);
            }
        }

        return $ok;
    }

    /**
     * pdftk kennt beliebige Seitenlisten direkt: `cat 1 3 5`.
     *
     * @param list<int> $pages
     */
    private static function extractPageSetWithPdftk(string $inputPath, string $outputPath, array $pages): bool {
        $pdftk = Config::getInstance()->getExecutablePathWithFallback('pdftk');
        $parts = [escapeshellarg($pdftk), escapeshellarg($inputPath), 'cat'];
        foreach ($pages as $page) {
            $parts[] = (string) $page;
        }
        $parts[] = 'output';
        $parts[] = escapeshellarg($outputPath);

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand(implode(' ', $parts), $output, $returnCode, timeout: ToolTimeout::forSize($inputPath)) || $returnCode !== 0) {
            self::logError('PDF-Seitenauswahl (pdftk) fehlgeschlagen', [
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
                'pages' => $pages,
            ]);
            return false;
        }
        if (!File::exists($outputPath)) {
            self::logError('Extrahierte PDF wurde nicht erstellt', ['path' => $outputPath]);
            return false;
        }

        return true;
    }

    /**
     * poppler-Weg: jede gewünschte Seite mit pdfseparate vereinzeln, dann in
     * Zielreihenfolge mit pdfunite zusammenfügen (eine Seite: nur kopieren).
     *
     * @param list<int> $pages
     */
    private static function extractPagesWithPoppler(string $inputPath, string $outputPath, array $pages): bool {
        $config = Config::getInstance();
        $pdfseparate = $config->getExecutablePathWithFallback('pdfseparate');
        $pdfunite = $config->getExecutablePathWithFallback('pdfunite');

        $tempDir = TempPath::directory('pdf-split-poppler');

        try {
            $singles = [];
            foreach ($pages as $page) {
                $single = $tempDir . '/p-' . $page . '.pdf';
                // Eine Seite, die mehrfach gewünscht ist, wird nur einmal vereinzelt
                if (!File::exists($single) && !self::separatePage($pdfseparate, $inputPath, $tempDir, $page)) {
                    return false;
                }
                $singles[] = $single;
            }

            if (count($singles) === 1) {
                return copy($singles[0], $outputPath);
            }

            $parts = [escapeshellarg($pdfunite)];
            foreach ($singles as $single) {
                $parts[] = escapeshellarg($single);
            }
            $parts[] = escapeshellarg($outputPath);

            $output = [];
            $returnCode = 0;
            if (!Shell::executeShellCommand(implode(' ', $parts), $output, $returnCode, timeout: ToolTimeout::forSize(...$singles)) || $returnCode !== 0) {
                self::logError('PDF-Seiten konnten nicht zusammengefügt werden (pdfunite)', [
                    'returnCode' => $returnCode,
                    'output' => implode("\n", $output),
                ]);
                return false;
            }

            return File::exists($outputPath);
        } finally {
            Folder::delete($tempDir, recursive: true);
        }
    }

    /**
     * Prüft ob poppler (pdfseparate + pdfunite) als Ersatz für pdftk verfügbar ist.
     */
    public static function isPopplerAvailable(): bool {
        $config = Config::getInstance();
        return $config->isExecutableAvailable('pdfseparate') && $config->isExecutableAvailable('pdfunite');
    }

    /**
     * Prüft ob mutool (MuPDF) zum Herauslösen von Seiten verfügbar ist.
     */
    public static function isMutoolMergeAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('mutool-merge');
    }

    /**
     * Prüft ob Seiten extrahiert werden können, egal mit welchem Werkzeug.
     */
    public static function isPageExtractionAvailable(): bool {
        return self::isMutoolMergeAvailable() || self::isAvailable() || self::isPopplerAvailable();
    }
}
