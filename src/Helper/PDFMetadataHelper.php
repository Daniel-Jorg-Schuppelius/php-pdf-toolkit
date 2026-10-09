<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFMetadataHelper.php
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
 * Metadaten einer PDF setzen oder entfernen (Info-Wörterbuch; der XMP-Strom
 * wird dabei entfernt, sonst zeigten Betrachter weiter die alten Werte).
 * Läuft über data/mupdf/pdf-metadata.js; die Werte reisen in einer Datei,
 * nie auf der Befehlszeile. Lesen übernimmt {@see PDFHelper::getMetadata()}.
 */
final class PDFMetadataHelper {
    use ErrorLog;

    /** Einträge, die gesetzt werden können */
    public const KEYS = ['Title', 'Author', 'Subject', 'Keywords', 'Creator', 'Producer'];

    /**
     * Setzt Einträge; ein leerer Wert entfernt den Eintrag, nicht genannte bleiben.
     *
     * @param array<string, string|null> $values Schlüssel aus {@see KEYS}
     */
    public static function set(string $inputPath, string $outputPath, array $values): bool {
        $lines = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, self::KEYS, true)) {
                self::logError('Unbekannter Metadaten-Schlüssel', ['key' => $key]);
                return false;
            }
            // Zeilenumbrüche trennen die Einträge in der Datei
            $clean = trim((string) preg_replace('/[\r\n]+/', ' ', (string) $value));
            $lines[] = $key . '=' . $clean;
        }
        if ($lines === []) {
            self::logError('Keine Metadaten angegeben');
            return false;
        }

        $dir = Platform::getTempDirectory() . '/pdfmeta_' . bin2hex(random_bytes(8));
        Folder::create($dir, 0700);
        $dataFile = $dir . '/values.txt';

        try {
            File::write($dataFile, implode("\n", $lines) . "\n");

            return self::run($inputPath, $outputPath, 'set', $dataFile, ['keys' => array_keys($values)]);
        } finally {
            Folder::delete($dir, true);
        }
    }

    /**
     * Entfernt alle Einträge des Info-Wörterbuchs und den XMP-Strom.
     */
    public static function clear(string $inputPath, string $outputPath): bool {
        return self::run($inputPath, $outputPath, 'clear', '', []);
    }

    public static function isAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('mutool-pdf-metadata');
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function run(string $inputPath, string $outputPath, string $mode, string $dataFile, array $context): bool {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return false;
        }

        $pages = PDFPageHelper::runScript('mutool-pdf-metadata', 'pdf-metadata.js', [
            '[INPUT]' => $inputPath,
            '[OUTPUT]' => $outputPath,
            '[MODE]' => $mode,
            '[DATA-FILE]' => $dataFile,
        ]);
        if ($pages === null || !File::exists($outputPath)) {
            return false;
        }

        self::logInfo('PDF-Metadaten ' . ($mode === 'set' ? 'gesetzt' : 'entfernt'), ['input' => $inputPath] + $context);

        return true;
    }
}
