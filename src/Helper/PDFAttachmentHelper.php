<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFAttachmentHelper.php
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
 * Eingebettete Dateien (Anhänge, EmbeddedFiles) einer PDF: auflisten und
 * herauslösen über pdfdetach (poppler), einbetten über data/mupdf/pdf-attach.js.
 * Die Rechnungs-XML einer ZUGFeRD-PDF liest {@see \PDFToolkit\Readers\ZugferdReader};
 * dieser Helfer behandelt Anhänge jeder Art.
 */
final class PDFAttachmentHelper {
    use ErrorLog;

    /** Längster Anhangsname in Bytes, den die Namensliste trägt */
    public const MAX_NAME_LENGTH = 200;

    /**
     * Namen der eingebetteten Dateien in der Reihenfolge der Datei.
     *
     * @return list<string> leer, wenn es keine gibt oder pdfdetach fehlt
     */
    public static function list(string $pdfPath): array {
        $config = Config::getInstance();
        if (!$config->isExecutableAvailable('pdfdetach-list')) {
            self::logError('pdfdetach ist nicht konfiguriert oder nicht verfügbar');
            return [];
        }
        $command = $config->buildCommand('pdfdetach-list', ['[PDF-FILE]' => $pdfPath]);
        if ($command === null) {
            self::logError('Konnte pdfdetach-Befehl nicht erstellen');
            return [];
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode, timeout: ToolTimeout::PROBE) || $returnCode !== 0) {
            self::logError('pdfdetach -list fehlgeschlagen', ['returnCode' => $returnCode, 'output' => implode("\n", $output)]);
            return [];
        }

        // "2 embedded files" dann je Zeile "1: name.ext"
        $names = [];
        foreach ($output as $line) {
            if (preg_match('/^\s*\d+:\s(.*)$/', $line, $m) === 1) {
                $names[] = $m[1];
            }
        }

        return $names;
    }

    /**
     * Löst alle eingebetteten Dateien in einen Ordner. pdfdetach schreibt die
     * Dateien unter ihrem eingebetteten Namen; gefährliche Namen (Pfade,
     * Steuerzeichen) weist poppler ab, deshalb landen sie in einem eigenen,
     * leeren Ordner.
     *
     * @return list<string> Pfade in der Reihenfolge der Datei; leer ohne Anhänge oder bei Fehler
     */
    public static function extractAll(string $pdfPath, string $outputDir): array {
        $config = Config::getInstance();
        if (!$config->isExecutableAvailable('pdfdetach-saveall')) {
            self::logError('pdfdetach ist nicht konfiguriert oder nicht verfügbar');
            return [];
        }
        $names = self::list($pdfPath);
        if ($names === []) {
            return [];
        }

        Folder::create($outputDir, 0700);
        $command = $config->buildCommand('pdfdetach-saveall', ['[OUTPUT-DIR]' => $outputDir, '[PDF-FILE]' => $pdfPath]);
        if ($command === null) {
            self::logError('Konnte pdfdetach-Befehl nicht erstellen');
            return [];
        }

        $output = [];
        $returnCode = 0;
        if (!Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode, timeout: ToolTimeout::forSize($pdfPath)) || $returnCode !== 0) {
            self::logError('pdfdetach -saveall fehlgeschlagen', ['returnCode' => $returnCode, 'output' => implode("\n", $output)]);
            return [];
        }

        $files = [];
        foreach ($names as $name) {
            $path = $outputDir . '/' . $name;
            if (File::exists($path)) {
                $files[] = $path;
            }
        }
        // Namen, die pdfdetach anders schreibt (Pfadanteile): was sonst noch im Ordner liegt
        foreach (Folder::findByPattern($outputDir, '*') as $path) {
            if (!in_array($path, $files, true) && File::isFile($path)) {
                $files[] = $path;
            }
        }

        self::logInfo('Anhänge aus PDF gelöst', ['input' => $pdfPath, 'attachments' => count($files)]);

        return $files;
    }

    /**
     * Bettet Dateien ein; ein Anhang gleichen Namens wird ersetzt, andere bleiben.
     *
     * @param list<array{path: string, name: string, mime?: string, description?: string}> $files
     * @return int|null Zahl der Anhänge in der Datei danach, null bei Fehler
     */
    public static function attach(string $inputPath, string $outputPath, array $files): ?int {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return null;
        }
        $lines = [];
        foreach ($files as $file) {
            $path = $file['path'];
            $name = self::cleanName($file['name']);
            if ($path === '' || !File::exists($path)) {
                self::logError('Anzuhängende Datei fehlt', ['path' => $path]);
                return null;
            }
            if ($name === '') {
                self::logError('Anhang ohne Namen', ['path' => $path]);
                return null;
            }
            $mime = self::cleanField((string) ($file['mime'] ?? ''));
            if ($mime !== '' && preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime) !== 1) {
                $mime = '';
            }
            $lines[] = $path . "\t" . $name . "\t" . $mime . "\t" . self::cleanField((string) ($file['description'] ?? ''));
        }
        if ($lines === []) {
            self::logError('Keine Datei zum Einbetten');
            return null;
        }

        $dir = Platform::getTempDirectory() . '/pdfattach_' . bin2hex(random_bytes(8));
        Folder::create($dir, 0700);
        $dataFile = $dir . '/files.txt';

        try {
            File::write($dataFile, implode("\n", $lines) . "\n");

            $count = PDFPageHelper::runScript('mutool-pdf-attach', 'pdf-attach.js', [
                '[INPUT]' => $inputPath,
                '[OUTPUT]' => $outputPath,
                '[DATA-FILE]' => $dataFile,
            ], ToolTimeout::forSize($inputPath, ...array_map(static fn (array $f): string => (string) $f['path'], $files)));
        } finally {
            Folder::delete($dir, true);
        }
        if ($count === null || !File::exists($outputPath)) {
            return null;
        }

        self::logInfo('Dateien in PDF eingebettet', ['input' => $inputPath, 'attached' => count($lines), 'total' => $count]);

        return $count;
    }

    /** pdfdetach konfiguriert und vorhanden? */
    public static function isAvailable(): bool {
        $config = Config::getInstance();

        return $config->isExecutableAvailable('pdfdetach-list') && $config->isExecutableAvailable('pdfdetach-saveall');
    }

    /** mutool für das Einbetten konfiguriert und vorhanden? */
    public static function isAttachAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('mutool-pdf-attach');
    }

    /**
     * Anhangsname ohne Pfadanteile, Tabulatoren und Zeilenumbrüche; gekürzt.
     */
    public static function cleanName(string $name): string {
        // Anzeigename, kein Dateisystempfad: Umlaute und Leerzeichen bleiben,
        // Pfadanteile und Steuerzeichen fallen weg
        $clean = File::sanitizeDisplayName(trim((string) preg_replace('/[\r\n\t]+/', ' ', $name)), 1000, '');
        $clean = trim(str_replace(['/', '\\'], '_', $clean));
        if (strlen($clean) > self::MAX_NAME_LENGTH) {
            // Endung behalten, den Stamm zeichenweise kuerzen (Umlaute sind mehrere Bytes)
            $extension = pathinfo($clean, PATHINFO_EXTENSION);
            $suffix = $extension === '' ? '' : '.' . $extension;
            $base = pathinfo($clean, PATHINFO_FILENAME);
            while ($base !== '' && strlen($base) + strlen($suffix) > self::MAX_NAME_LENGTH) {
                $base = mb_substr($base, 0, mb_strlen($base) - 1);
            }
            $clean = $base . $suffix;
        }

        return $clean;
    }

    private static function cleanField(string $value): string {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
    }
}
