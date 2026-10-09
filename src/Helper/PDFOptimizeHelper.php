<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFOptimizeHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * PDF verkleinern und nach PDF/A wandeln.
 *
 * Zwei Stufen, gemessen am 09.10.2026: "stark" tastet Bilder über Ghostscript
 * auf 150 dpi ab (bildlastige PDF 5,6 MB -> 0,28 MB), bringt bei
 * CCITT-Scans aber nichts; "verlustfrei" nutzt den Optimierer von OCRmyPDF
 * (JBIG2, pngquant; Scan 1,92 MB -> 1,44 MB). Das Ergebnis zählt nur, wenn
 * es kleiner ist.
 */
final class PDFOptimizeHelper {
    use ErrorLog;

    /** Bilder neu abtasten (Ghostscript /ebook, 150 dpi) */
    public const LEVEL_STRONG = 'strong';

    /** Verlustfrei optimieren (OCRmyPDF --optimize 3) */
    public const LEVEL_LOSSLESS = 'lossless';

    public const LEVELS = [self::LEVEL_STRONG, self::LEVEL_LOSSLESS];

    /**
     * Verkleinert eine PDF. Schreibt das Ergebnis nur, wenn es kleiner ist.
     *
     * @param string $level LEVEL_STRONG oder LEVEL_LOSSLESS
     * @return int|null Größe des Ergebnisses in Bytes; null, wenn nichts gespart wurde oder ein Fehler auftrat
     */
    public static function compress(string $inputPath, string $outputPath, string $level = self::LEVEL_STRONG): ?int {
        if (!in_array($level, self::LEVELS, true)) {
            self::logError('Unbekannte Stufe', ['level' => $level]);
            return null;
        }
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return null;
        }

        $executable = $level === self::LEVEL_STRONG ? 'gs-compress' : 'ocrmypdf-optimize';
        if (!self::run($executable, $inputPath, $outputPath)) {
            return null;
        }

        $before = File::size($inputPath);
        $after = File::size($outputPath);
        if ($after >= $before) {
            self::logInfo('Verkleinern bringt nichts, Original bleibt', ['level' => $level, 'before' => $before, 'after' => $after]);
            File::delete($outputPath);
            return null;
        }

        self::logInfo('PDF verkleinert', ['level' => $level, 'before' => $before, 'after' => $after]);

        return $after;
    }

    /**
     * Wandelt nach PDF/A-2b (OCRmyPDF, vorhandener Text bleibt, keine OCR).
     */
    public static function toPdfA(string $inputPath, string $outputPath): bool {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return false;
        }
        if (!self::run('ocrmypdf-pdfa', $inputPath, $outputPath)) {
            return false;
        }
        if (!PDFHelper::isValidPdf($outputPath)) {
            self::logError('PDF/A-Ergebnis ist nicht lesbar', ['path' => $outputPath]);
            File::delete($outputPath);
            return false;
        }

        self::logInfo('PDF nach PDF/A-2b gewandelt', ['input' => $inputPath]);

        return true;
    }

    /**
     * Prüft ob eine Stufe verfügbar ist.
     */
    public static function isAvailable(string $level = self::LEVEL_STRONG): bool {
        $config = Config::getInstance();

        return $level === self::LEVEL_STRONG
            ? $config->isExecutableAvailable('gs-compress')
            : $config->isExecutableAvailable('ocrmypdf-optimize');
    }

    /**
     * Prüft ob PDF/A verfügbar ist.
     */
    public static function isPdfAAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('ocrmypdf-pdfa');
    }

    private static function run(string $executable, string $inputPath, string $outputPath): bool {
        $config = Config::getInstance();
        if (!$config->isExecutableAvailable($executable)) {
            self::logError('Werkzeug ist nicht konfiguriert oder nicht verfügbar', ['executable' => $executable]);
            return false;
        }

        $command = $config->buildCommand($executable, [
            '[INPUT]' => $inputPath,
            '[OUTPUT]' => $outputPath,
        ]);
        if ($command === null) {
            self::logError('Konnte Befehl nicht erstellen', ['executable' => $executable]);
            return false;
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode) || $returnCode !== 0 || !File::exists($outputPath)) {
            self::logError('Werkzeug fehlgeschlagen', [
                'executable' => $executable,
                'returnCode' => $returnCode,
                'output' => implode("\n", array_slice($output, -5)),
            ]);
            return false;
        }

        return true;
    }
}
