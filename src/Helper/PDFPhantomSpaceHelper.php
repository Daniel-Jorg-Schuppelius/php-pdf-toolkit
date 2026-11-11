<?php
/*
 * Created on   : Wed Nov 11 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFPhantomSpaceHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\File;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * Phantom-Leerzeichen aus der Textebene entfernen (data/mupdf/pdf-phantom-spaces.js).
 *
 * Manche Erzeuger - etwa der PDF24-Druckertreiber für die bedingten
 * Trennstriche aus Word - geben an jeder Trennstelle eines Worts ein
 * Leerzeichen aus, dessen Nachfolger an derselben Stelle beginnt. Es zeichnet
 * nichts, steht aber in der Textebene: pdftotext verwirft es nach Lage,
 * LibreOffice und MuPDF (pdf2docx) übernehmen es, aus "Inhaltsverzeichnis"
 * wird "In halts ver zeich nis". Das Skript entfernt solche Leerzeichen aus den
 * Inhaltsströmen von Seiten und Form-XObjects; die Seiten sehen danach
 * gleich aus (gemessen: 50 Seiten pixelgleich).
 *
 * Gedacht als Vorstufe für Werkzeuge, die die Textebene wörtlich nehmen
 * (PDF nach DOCX/ODT), nicht für die Datei, die der Kunde zurückbekommt.
 */
final class PDFPhantomSpaceHelper {
    use ErrorLog;

    /**
     * Schreibt die bereinigte PDF nach $outputPath - nur, wenn es etwas zu
     * bereinigen gab.
     *
     * @return bool true: $outputPath enthält die bereinigte PDF; false: nichts
     *              gefunden, Skript nicht verfügbar oder gescheitert ($outputPath
     *              entsteht nicht, die Eingabe gilt unverändert)
     */
    public static function removePhantomSpaces(string $inputPath, string $outputPath): bool {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return false;
        }
        if (!self::isAvailable()) {
            return false;
        }

        $pages = PDFPageHelper::runScript('mutool-pdf-phantom-spaces', 'pdf-phantom-spaces.js', [
            '[INPUT]' => $inputPath,
            '[OUTPUT]' => $outputPath,
        ]);
        if ($pages === null || !File::exists($outputPath)) {
            return false;
        }

        self::logInfo('Phantom-Leerzeichen aus der Textebene entfernt', ['input' => $inputPath, 'pages' => $pages]);

        return true;
    }

    public static function isAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('mutool-pdf-phantom-spaces');
    }
}
