<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PdfboxReader.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Readers;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use PDFToolkit\Config\Config;
use PDFToolkit\Contracts\PDFReaderInterface;
use ERRORToolkit\Traits\ErrorLog;

/**
 * PDF-Reader basierend auf Apache PDFBox (Java).
 * 
 * Bessere Ergebnisse bei komplexen Layouts als pdftotext.
 * Benötigt Java und pdfbox-app.jar.
 */
final class PdfboxReader implements PDFReaderInterface {
    use ErrorLog;

    private ?bool $available = null;
    private Config $config;

    public function __construct() {
        $this->config = Config::getInstance();
    }

    public static function getName(): string {
        return 'pdfbox';
    }

    public static function getPriority(): int {
        return 30; // Nach pdftotext, vor OCR
    }

    public static function supportsScannedPdfs(): bool {
        return false;
    }

    public static function supportsTextPdfs(): bool {
        return true;
    }

    public function isAvailable(): bool {
        if ($this->available !== null) {
            return $this->available;
        }

        // ConfigToolkit prüft bereits Pfad und PATH-Verfügbarkeit
        $this->available = $this->config->getShellExecutable('java') !== null
            && $this->config->getJavaExecutable('pdfbox') !== null;
        return $this->available;
    }

    public function canHandle(string $pdfPath): bool {
        return $this->isAvailable();
    }

    public function extractText(string $pdfPath, array $options = []): ?string {
        if (!$this->isAvailable()) {
            return null;
        }

        $tempFile = sys_get_temp_dir() . '/pdfbox_' . uniqid() . '.txt';

        // PDFBox Befehl aus Config bauen
        $command = $this->config->buildJavaCommand('pdfbox', [
            '[INPUT]' => $pdfPath,
            '[OUTPUT]' => $tempFile,
        ]);

        if ($command === null) {
            $this->logDebug("PDFBox command could not be built");
            return null;
        }

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->logDebug("PDFBox failed with code $returnCode for: $pdfPath");
            File::delete($tempFile);
            return null;
        }

        if (!File::exists($tempFile)) {
            $this->logDebug("PDFBox produced no output for: $pdfPath");
            return null;
        }

        $text = File::read($tempFile);
        File::delete($tempFile);

        // Prüfen ob relevanter Text extrahiert wurde
        $trimmed = preg_replace('/\s+/', '', $text);
        if (strlen($trimmed) < 10) {
            $this->logDebug("PDFBox extracted too little text from: $pdfPath");
            return null;
        }

        $this->logDebug("PDFBox successfully extracted " . strlen($text) . " chars from: $pdfPath");
        return $text;
    }
}
