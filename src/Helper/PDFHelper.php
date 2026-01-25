<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFHelper.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use PDFToolkit\Config\Config;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Helper-Klasse für PDF-Operationen.
 */
final class PDFHelper {
    use ErrorLog;

    /**
     * Prüft ob eine Datei ein gültiges PDF ist.
     */
    public static function isValidPdf(string $filePath): bool {
        if (!File::exists($filePath) || !File::isReadable($filePath)) {
            return false;
        }

        if (!File::isExtension($filePath, ['.pdf'])) {
            return false;
        }

        // PDF-Signatur prüfen (%PDF-)
        $header = File::readPartial($filePath, 8);
        if ($header === false) {
            return false;
        }

        return str_starts_with($header, '%PDF-');
    }

    /**
     * Extrahiert Metadaten aus einer PDF-Datei via pdfinfo.
     * 
     * @return array<string, string> Assoziatives Array mit Metadaten
     */
    public static function getMetadata(string $filePath): array {
        if (!self::isValidPdf($filePath)) {
            return [];
        }

        $config = Config::getInstance();
        if ($config->getShellExecutable('pdfinfo') === null) {
            return [];
        }

        $command = $config->buildCommand('pdfinfo', [
            '[PDF-FILE]' => $filePath,
        ]);

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [];
        }

        $metadata = [];
        foreach ($output as $line) {
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /**
     * Gibt die Seitenanzahl einer PDF zurück.
     */
    public static function getPageCount(string $filePath): int {
        $metadata = self::getMetadata($filePath);
        return isset($metadata['Pages']) ? (int) $metadata['Pages'] : 0;
    }

    /**
     * Prüft ob das PDF Text enthält (nicht nur Bilder).
     * Nutzt pdftotext für einen schnellen Check der ersten Seite.
     */
    public static function hasEmbeddedText(string $filePath, int $minChars = 20): bool {
        if (!self::isValidPdf($filePath)) {
            return false;
        }

        $config = Config::getInstance();
        if ($config->getShellExecutable('pdftotext') === null) {
            return false;
        }

        $tempFile = sys_get_temp_dir() . '/pdf_check_' . uniqid() . '.txt';

        // Nur erste Seite prüfen für Geschwindigkeit (-l 1)
        $command = $config->buildCommand('pdftotext', [
            '[PDF-FILE]' => $filePath,
            '[TEXT-FILE]' => $tempFile,
        ], ['-q', '-l', '1']);

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0 || !File::exists($tempFile)) {
            return false;
        }

        $text = File::read($tempFile);
        File::delete($tempFile);

        // Whitespace entfernen und Länge prüfen
        $text = preg_replace('/\s+/', '', $text);
        return strlen($text) >= $minChars;
    }

    /**
     * Schätzt ob das PDF gescannt ist (nur Bilder, kein Text).
     */
    public static function isLikelyScanned(string $filePath): bool {
        return self::isValidPdf($filePath) && !self::hasEmbeddedText($filePath);
    }

    /**
     * Gibt die PDF-Version zurück (z.B. "1.4", "1.7").
     */
    public static function getPdfVersion(string $filePath): ?string {
        if (!self::isValidPdf($filePath)) {
            return null;
        }

        $header = File::readPartial($filePath, 8);
        if ($header === false) {
            return null;
        }

        if (preg_match('/%PDF-(\d+\.\d+)/', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
