<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PdftotextReader.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Readers;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use PDFToolkit\Config\Config;
use PDFToolkit\Contracts\PDFReaderInterface;
use PDFToolkit\Helper\PDFHelper;
use ERRORToolkit\Traits\ErrorLog;

/**
 * PDF-Reader basierend auf pdftotext (poppler-utils).
 * 
 * Schnellste Option für PDFs mit eingebettetem Text.
 * Funktioniert NICHT für gescannte Dokumente.
 */
final class PdftotextReader implements PDFReaderInterface {
    use ErrorLog;

    private ?bool $available = null;
    private Config $config;

    public function __construct() {
        $this->config = Config::getInstance();
    }

    public static function getName(): string {
        return 'pdftotext';
    }

    public static function getPriority(): int {
        return 10; // Höchste Priorität - schnellste Option
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
        $this->available = $this->config->getShellExecutable('pdftotext') !== null;
        return $this->available;
    }

    public function canHandle(string $pdfPath): bool {
        if (!$this->isAvailable()) {
            return false;
        }

        // Schneller Check ob PDF Text enthält
        return PDFHelper::hasEmbeddedText($pdfPath, 10);
    }

    public function extractText(string $pdfPath, array $options = []): ?string {
        if (!$this->isAvailable()) {
            return null;
        }

        $tempFile = sys_get_temp_dir() . '/pdftotext_' . uniqid() . '.txt';

        // Befehl aus Config bauen (enthält -layout -enc UTF-8)
        $command = $this->config->buildCommand('pdftotext', [
            '[PDF-FILE]' => $pdfPath,
            '[TEXT-FILE]' => $tempFile,
        ]);

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->logDebug("pdftotext failed with code $returnCode for: $pdfPath");
            File::delete($tempFile);
            return null;
        }

        if (!File::exists($tempFile)) {
            $this->logDebug("pdftotext produced no output for: $pdfPath");
            return null;
        }

        $text = File::read($tempFile);
        File::delete($tempFile);

        // Prüfen ob relevanter Text extrahiert wurde
        $trimmed = preg_replace('/\s+/', '', $text);
        if (strlen($trimmed) < 10) {
            $this->logDebug("pdftotext extracted too little text from: $pdfPath");
            return null;
        }

        $this->logDebug("pdftotext successfully extracted " . strlen($text) . " chars from: $pdfPath");
        return $text;
    }
}
