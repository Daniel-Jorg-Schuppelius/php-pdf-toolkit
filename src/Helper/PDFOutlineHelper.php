<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFOutlineHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Platform;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * Lesezeichen (Outline) einer PDF setzen: eine Ebene, in Seitenreihenfolge.
 * Läuft über data/mupdf/pdf-outline.js; die Titel reisen in einer Datei, nie
 * auf der Befehlszeile. Lesen übernimmt der Aufrufer (z. B. der
 * Lesezeichen-Leser des Aufteilers).
 */
final class PDFOutlineHelper {
    use ErrorLog;

    /** Längster Titel in Zeichen; längere werden gekürzt */
    public const MAX_TITLE_LENGTH = 200;

    /**
     * Ersetzt die Lesezeichen der Datei.
     *
     * @param list<array{page: int, title: string}> $bookmarks Seite (1-basiert) und Titel, in Seitenreihenfolge
     * @return int|null Zahl der gesetzten Lesezeichen, null bei Fehler
     */
    public static function set(string $inputPath, string $outputPath, array $bookmarks): ?int {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return null;
        }
        $pageCount = PDFHelper::getPageCount($inputPath);
        $lines = [];
        foreach ($bookmarks as $bookmark) {
            $page = $bookmark['page'];
            $title = self::cleanTitle($bookmark['title']);
            if ($page < 1 || $page > $pageCount) {
                self::logError('Lesezeichen auf eine Seite, die es nicht gibt', ['page' => $page, 'pages' => $pageCount]);
                return null;
            }
            if ($title === '') {
                self::logError('Lesezeichen ohne Titel', ['page' => $page]);
                return null;
            }
            $lines[] = $page . "\t" . $title;
        }
        if ($lines === []) {
            self::logError('Keine Lesezeichen angegeben');
            return null;
        }

        $dir = Platform::getTempDirectory() . '/pdfoutline_' . bin2hex(random_bytes(8));
        Folder::create($dir, 0700);
        $dataFile = $dir . '/bookmarks.txt';

        try {
            File::write($dataFile, implode("\n", $lines) . "\n");

            $count = PDFPageHelper::runScript('mutool-pdf-outline', 'pdf-outline.js', [
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

        self::logInfo('PDF-Lesezeichen gesetzt', ['input' => $inputPath, 'bookmarks' => $count]);

        return $count;
    }

    public static function isAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('mutool-pdf-outline');
    }

    /**
     * Titel ohne Zeilenumbrüche und Tabulatoren (sie trennen die Einträge in
     * der Datei), auf eine Länge gekürzt, die Betrachter noch anzeigen.
     */
    public static function cleanTitle(string $title): string {
        $clean = trim((string) preg_replace('/[\r\n\t]+/', ' ', $title));
        if (mb_strlen($clean) > self::MAX_TITLE_LENGTH) {
            $clean = rtrim(mb_substr($clean, 0, self::MAX_TITLE_LENGTH - 3)) . '...';
        }

        return $clean;
    }
}
