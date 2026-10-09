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

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\{Platform, Shell};
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

/**
 * PDF verkleinern, bereinigen und nach PDF/A wandeln.
 *
 * Drei Stufen: "stark" tastet Bilder über Ghostscript auf 150 dpi ab
 * (gemessen 09.10.2026: bildlastige PDF 5,6 MB -> 0,28 MB), bringt bei
 * CCITT-Scans aber nichts; "verlustfrei" nutzt den Optimierer von OCRmyPDF
 * (JBIG2, pngquant; Scan 1,92 MB -> 1,44 MB); "custom" nimmt Auflösung und
 * JPEG-Qualität als Zahl. Das Ergebnis zählt nur, wenn es kleiner ist.
 * {@see clean()} schreibt die Datei über MuPDF neu (reparieren) oder
 * linearisiert sie für den Abruf im Browser.
 */
final class PDFOptimizeHelper {
    use ErrorLog;

    /** Bilder neu abtasten (Ghostscript /ebook, 150 dpi) */
    public const LEVEL_STRONG = 'strong';

    /** Verlustfrei optimieren (OCRmyPDF --optimize 3) */
    public const LEVEL_LOSSLESS = 'lossless';

    /** Auflösung und JPEG-Qualität als Zahl (Ghostscript) */
    public const LEVEL_CUSTOM = 'custom';

    public const LEVELS = [self::LEVEL_STRONG, self::LEVEL_LOSSLESS, self::LEVEL_CUSTOM];

    /**
     * Verkleinert eine PDF. Schreibt das Ergebnis nur, wenn es kleiner ist.
     *
     * @param string $level LEVEL_STRONG, LEVEL_LOSSLESS oder LEVEL_CUSTOM
     * @param int|null $dpi Nur LEVEL_CUSTOM: Auflösung der Bilder (36-600)
     * @param int|null $quality Nur LEVEL_CUSTOM: JPEG-Qualität 1-100
     * @return int|null Größe des Ergebnisses in Bytes; null, wenn nichts gespart wurde oder ein Fehler auftrat
     */
    public static function compress(string $inputPath, string $outputPath, string $level = self::LEVEL_STRONG, ?int $dpi = null, ?int $quality = null): ?int {
        if (!in_array($level, self::LEVELS, true)) {
            self::logError('Unbekannte Stufe', ['level' => $level]);
            return null;
        }
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return null;
        }

        if ($level === self::LEVEL_CUSTOM) {
            if (!self::runCustom($inputPath, $outputPath, $dpi, $quality)) {
                return null;
            }
        } else {
            $executable = $level === self::LEVEL_STRONG ? 'gs-compress' : 'ocrmypdf-optimize';
            if (!self::run($executable, $inputPath, $outputPath)) {
                return null;
            }
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
     * Schreibt die PDF über MuPDF neu: unbenutzte Objekte weg, Querverweise
     * neu aufgebaut (reparieren); mit $linearize für den seitenweisen Abruf im
     * Browser (Web-optimiert).
     */
    public static function clean(string $inputPath, string $outputPath, bool $linearize = false): bool {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return false;
        }
        if (!self::run($linearize ? 'mutool-clean-web' : 'mutool-clean-repair', $inputPath, $outputPath)) {
            return false;
        }
        if (!PDFHelper::isValidPdf($outputPath)) {
            self::logError('Bereinigte PDF ist nicht lesbar', ['path' => $outputPath]);
            File::delete($outputPath);
            return false;
        }

        self::logInfo($linearize ? 'PDF linearisiert' : 'PDF repariert', ['input' => $inputPath]);

        return true;
    }

    /**
     * Prüft ob eine Stufe verfügbar ist.
     */
    public static function isAvailable(string $level = self::LEVEL_STRONG): bool {
        $config = Config::getInstance();

        return match ($level) {
            self::LEVEL_STRONG => $config->isExecutableAvailable('gs-compress'),
            self::LEVEL_CUSTOM => $config->isExecutableAvailable('gs-compress-custom'),
            default => $config->isExecutableAvailable('ocrmypdf-optimize'),
        };
    }

    public static function isCleanAvailable(bool $linearize = false): bool {
        return Config::getInstance()->isExecutableAvailable($linearize ? 'mutool-clean-web' : 'mutool-clean-repair');
    }

    /**
     * Ghostscript mit Auflösung und JPEG-Qualität. Die Qualität geht als
     * QFactor über eine PostScript-Datei (setdistillerparams) hinein - die
     * spitzen Klammern der Parameter dürfen nicht auf die Befehlszeile.
     */
    private static function runCustom(string $inputPath, string $outputPath, ?int $dpi, ?int $quality): bool {
        if ($dpi === null || $dpi < 36 || $dpi > 600 || $quality === null || $quality < 1 || $quality > 100) {
            self::logError('Auflösung (36-600 dpi) und Qualität (1-100) sind nötig', ['dpi' => $dpi, 'quality' => $quality]);
            return false;
        }
        $config = Config::getInstance();
        if (!$config->isExecutableAvailable('gs-compress-custom')) {
            self::logError('Werkzeug ist nicht konfiguriert oder nicht verfügbar', ['executable' => 'gs-compress-custom']);
            return false;
        }

        // QFactor: 0,15 (beste Qualität) bis 1,65 (kleinste Datei)
        $qFactor = 0.15 + (100 - $quality) / 100 * 1.5;
        $dict = sprintf('<< /QFactor %.2f /Blend 1 /HSamples [2 1 1 2] /VSamples [2 1 1 2] >>', $qFactor);
        $dir = Platform::getTempDirectory() . '/pdfcompress_' . bin2hex(random_bytes(8));
        Folder::create($dir, 0700);
        $paramsFile = $dir . '/params.ps';

        try {
            File::write($paramsFile, "<< /ColorImageDict {$dict} /GrayImageDict {$dict} >> setdistillerparams\n");

            $command = $config->buildCommand('gs-compress-custom', [
                '[DPI]' => (string) $dpi,
                // Strichbilder (Scans) vertragen weniger Abtastung
                '[MONO-DPI]' => (string) min(600, max($dpi, 300)),
                '[PARAMS-FILE]' => $paramsFile,
                '[INPUT]' => $inputPath,
                '[OUTPUT]' => $outputPath,
            ]);
            if ($command === null) {
                self::logError('Konnte Befehl nicht erstellen', ['executable' => 'gs-compress-custom']);
                return false;
            }

            $output = [];
            $returnCode = 0;
            if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode) || $returnCode !== 0 || !File::exists($outputPath)) {
                self::logError('Werkzeug fehlgeschlagen', ['executable' => 'gs-compress-custom', 'returnCode' => $returnCode, 'output' => implode("\n", array_slice($output, -5))]);
                return false;
            }

            return true;
        } finally {
            Folder::delete($dir, true);
        }
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
