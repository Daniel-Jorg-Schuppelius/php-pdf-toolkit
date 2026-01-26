<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TesseractReader.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Readers;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Shell;
use PDFToolkit\Config\Config;
use PDFToolkit\Contracts\PDFReaderInterface;
use PDFToolkit\Enums\PDFReaderType;
use PDFToolkit\Helper\TesseractDataHelper;
use ERRORToolkit\Traits\ErrorLog;

/**
 * PDF-Reader basierend auf Tesseract OCR.
 * 
 * Für gescannte PDFs die keinen eingebetteten Text haben.
 * Konvertiert PDF-Seiten zu Bildern und führt OCR durch.
 */
final class TesseractReader implements PDFReaderInterface {
    use ErrorLog;

    private ?bool $available = null;
    private Config $config;
    private string $defaultLanguage;
    private string $tessDataPath;
    private int $defaultPsm;
    private int $defaultDpi;

    public function __construct() {
        $this->loadConfig();
    }

    private function loadConfig(): void {
        $this->config = Config::getInstance();

        $this->defaultLanguage = $this->config->getConfig('PDFSettings', 'tesseract_lang') ?? 'deu+eng';
        $this->tessDataPath = $this->config->getConfig('PDFSettings', 'tesseract_data_path') ?? '';
        $this->defaultPsm = (int) ($this->config->getConfig('PDFSettings', 'tesseract_psm') ?? 3);
        $this->defaultDpi = (int) ($this->config->getConfig('PDFSettings', 'pdftoppm_dpi') ?? 300);

        // Fallback auf lokales data-Verzeichnis mit automatischem Download
        if (empty($this->tessDataPath)) {
            $usablePath = TesseractDataHelper::getUsableDataPath($this->defaultLanguage);
            if ($usablePath !== null) {
                $this->tessDataPath = $usablePath;
            }
        }
    }

    public static function getType(): PDFReaderType {
        return PDFReaderType::Tesseract;
    }

    public static function getPriority(): int {
        return PDFReaderType::Tesseract->getPriority();
    }

    public static function supportsScannedPdfs(): bool {
        return PDFReaderType::Tesseract->supportsScannedPdfs();
    }

    public static function supportsTextPdfs(): bool {
        return PDFReaderType::Tesseract->supportsTextPdfs();
    }

    public function isAvailable(): bool {
        if ($this->available !== null) {
            return $this->available;
        }

        // ConfigToolkit prüft bereits Pfad und PATH-Verfügbarkeit
        $this->available = $this->config->getShellExecutable('tesseract') !== null
            && $this->config->getShellExecutable('pdftoppm') !== null;
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
        $tempDir = sys_get_temp_dir() . '/tesseract_' . uniqid();

        if (!mkdir($tempDir, 0755, true)) {
            $this->logError("Failed to create temp directory: $tempDir");
            return null;
        }

        try {
            // 1. PDF zu PNG konvertieren
            $command = $this->config->buildCommand('pdftoppm', [
                '[DPI]' => (string) $this->defaultDpi,
                '[PDF-FILE]' => $pdfPath,
                '[OUTPUT-PREFIX]' => $tempDir . '/page',
            ]);

            $output = [];
            $returnCode = 0;
            Shell::executeShellCommand($command, $output, $returnCode);

            if ($returnCode !== 0) {
                $this->logDebug("pdftoppm failed with code $returnCode for: $pdfPath");
                return null;
            }

            // 2. Alle Seiten mit Tesseract verarbeiten
            $pages = glob("$tempDir/page-*.png");
            if (empty($pages)) {
                $this->logDebug("No pages extracted from: $pdfPath");
                return null;
            }

            // Natürliche Sortierung für korrekte Seitenreihenfolge
            natsort($pages);

            $allText = [];
            foreach ($pages as $pagePath) {
                $textFile = $pagePath . '_text';

                // Befehl aus Config bauen
                $command = $this->config->buildCommand('tesseract', [
                    '[INPUT]' => $pagePath,
                    '[OUTPUT]' => $textFile,
                    '[LANG]' => $language,
                    '[PSM]' => (string) $this->defaultPsm,
                ]);

                // TESSDATA_PREFIX setzen falls eigene Trainingsdaten vorhanden
                if (!empty($this->tessDataPath) && Folder::exists($this->tessDataPath)) {
                    $command = "TESSDATA_PREFIX=" . escapeshellarg($this->tessDataPath) . " " . $command;
                }

                $output = [];
                $returnCode = 0;
                Shell::executeShellCommand($command, $output, $returnCode);

                if ($returnCode === 0 && File::exists($textFile . '.txt')) {
                    $pageText = File::read($textFile . '.txt');
                    if (trim($pageText) !== '') {
                        $allText[] = $pageText;
                    }
                }
            }

            if (empty($allText)) {
                $this->logDebug("Tesseract extracted no text from: $pdfPath");
                return null;
            }

            $text = implode("\n\n--- Seite ---\n\n", $allText);

            return $this->logDebugAndReturn($text, "Tesseract extracted " . strlen($text) . " chars from " . count($pages) . " pages");
        } finally {
            // Aufräumen - rekursiv löschen
            Folder::delete($tempDir, true);
        }
    }
}