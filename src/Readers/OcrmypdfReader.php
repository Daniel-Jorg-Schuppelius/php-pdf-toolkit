<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OcrmypdfReader.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Readers;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use PDFToolkit\Config\Config;
use PDFToolkit\Contracts\PDFReaderInterface;
use PDFToolkit\Enums\PDFReaderType;
use ERRORToolkit\Traits\ErrorLog;

/**
 * PDF-Reader basierend auf OCRmyPDF.
 * 
 * Erstellt ein durchsuchbares PDF aus gescannten Dokumenten.
 * Kombiniert mehrere Tools (Tesseract, unpaper, etc.) für beste Ergebnisse.
 */
final class OcrmypdfReader implements PDFReaderInterface {
    use ErrorLog;

    private ?bool $available = null;
    private Config $config;
    private string $defaultLanguage;
    private int $defaultPsm;

    public function __construct() {
        $this->loadConfig();
    }

    private function loadConfig(): void {
        $this->config = Config::getInstance();
        $this->defaultLanguage = $this->config->getConfig('PDFSettings', 'tesseract_lang') ?? 'deu+eng';
        $this->defaultPsm = (int) ($this->config->getConfig('PDFSettings', 'ocrmypdf_psm') ?? 11);
    }

    public static function getType(): PDFReaderType {
        return PDFReaderType::Ocrmypdf;
    }

    public static function getPriority(): int {
        return PDFReaderType::Ocrmypdf->getPriority();
    }

    public static function supportsScannedPdfs(): bool {
        return PDFReaderType::Ocrmypdf->supportsScannedPdfs();
    }

    public static function supportsTextPdfs(): bool {
        return PDFReaderType::Ocrmypdf->supportsTextPdfs();
    }

    public function isAvailable(): bool {
        if ($this->available !== null) {
            return $this->available;
        }

        // ConfigToolkit prüft bereits Pfad und PATH-Verfügbarkeit
        $this->available = $this->config->getShellExecutable('ocrmypdf') !== null
            && $this->config->getShellExecutable('pdftotext') !== null;
        return $this->available;
    }

    public function canHandle(string $pdfPath): bool {
        return $this->isAvailable();
    }

    public function extractText(string $pdfPath, array $options = []): ?string {
        if (!$this->isAvailable()) {
            return null;
        }

        $language = $options['language'] ?? $this->defaultLanguage;
        $psm = $options['psm'] ?? $this->defaultPsm;

        $tempPdf = sys_get_temp_dir() . '/ocrmypdf_' . uniqid() . '.pdf';
        $tempTxt = sys_get_temp_dir() . '/ocrmypdf_' . uniqid() . '.txt';

        try {
            // 1. OCRmyPDF ausführen - erstellt durchsuchbares PDF
            $command = $this->config->buildCommand('ocrmypdf', [
                '[PSM]' => (string) $psm,
                '[LANG]' => $language,
                '[INPUT]' => $pdfPath,
                '[OUTPUT]' => $tempPdf,
            ]);

            $output = [];
            $returnCode = 0;
            Shell::executeShellCommand($command, $output, $returnCode);

            // Return-Codes: 0 = OK, 6 = bereits Text vorhanden (--skip-text)
            if ($returnCode !== 0 && $returnCode !== 6) {
                $this->logDebug("ocrmypdf failed with code $returnCode for: $pdfPath");
                return null;
            }

            // Wenn Datei nicht erstellt wurde, originales PDF verwenden
            $pdfToExtract = File::exists($tempPdf) ? $tempPdf : $pdfPath;

            // 2. Text aus dem OCR-verarbeiteten PDF extrahieren
            $command = $this->config->buildCommand('pdftotext', [
                '[PDF-FILE]' => $pdfToExtract,
                '[TEXT-FILE]' => $tempTxt,
            ]);

            $output = [];
            $returnCode = 0;
            Shell::executeShellCommand($command, $output, $returnCode);

            if ($returnCode !== 0 || !File::exists($tempTxt)) {
                $this->logDebug("pdftotext failed after ocrmypdf for: $pdfPath");
                return null;
            }

            $text = File::read($tempTxt);

            // Prüfen ob relevanter Text extrahiert wurde
            $trimmed = preg_replace('/\s+/', '', $text);
            if (strlen($trimmed) < 10) {
                $this->logDebug("ocrmypdf extracted too little text from: $pdfPath");
                return null;
            }

            return $this->logDebugAndReturn($text, "ocrmypdf successfully extracted " . strlen($text) . " chars from: $pdfPath");
        } finally {
            // Aufräumen
            File::delete($tempPdf);
            File::delete($tempTxt);
        }
    }
}