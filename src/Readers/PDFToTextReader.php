<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFToTextReader.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Readers;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use PDFToolkit\Config\Config;
use PDFToolkit\Contracts\PDFReaderInterface;
use PDFToolkit\Enums\PDFReaderType;
use PDFToolkit\Helper\{PDFHelper, TextQualityAnalyzer};
use ERRORToolkit\Traits\ErrorLog;

/**
 * PDF-Reader basierend auf pdftotext (poppler-utils).
 * 
 * Schnellste Option für PDFs mit eingebettetem Text.
 * Funktioniert NICHT für gescannte Dokumente.
 */
final class PDFToTextReader implements PDFReaderInterface {
    use ErrorLog;

    private ?bool $available = null;
    private Config $config;

    public function __construct() {
        $this->config = Config::getInstance();
    }

    public static function getType(): PDFReaderType {
        return PDFReaderType::Pdftotext;
    }

    public static function getPriority(): int {
        return PDFReaderType::Pdftotext->getPriority();
    }

    public static function supportsScannedPdfs(): bool {
        return PDFReaderType::Pdftotext->supportsScannedPdfs();
    }

    public static function supportsTextPdfs(): bool {
        return PDFReaderType::Pdftotext->supportsTextPdfs();
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

        // Keine hasEmbeddedText()-Prüfung mehr:
        // - extractText() validiert selbst, ob genug Text extrahiert wurde
        // - extractText() der Registry prüft hasEmbeddedText() bereits auf Orchestrierungs-Ebene
        // - Spart einen redundanten pdftotext-Shell-Call (~11ms)
        return PDFHelper::isValidPdf($pdfPath);
    }

    public function extractText(string $pdfPath, array $options = []): ?string {
        if (!$this->isAvailable()) {
            return null;
        }

        // Option für Layout-Modus (Standard: true für Abwärtskompatibilität)
        // Bank-PDFs benötigen oft layout: false für korrekte Transaktions-Extraktion
        $useLayout = $options['layout'] ?? true;
        $dualStrategy = $options['dualStrategy'] ?? true;

        // Primäre Extraktion
        $text = $this->extractWithMode($pdfPath, $useLayout);
        if ($text === null) {
            return null;
        }

        // Doppelstrategie: Wenn aktiviert und Raw-Modus verfügbar,
        // beide Modi vergleichen und den besseren zurückgeben
        if ($dualStrategy && $this->config->isExecutableAvailable('pdftotext-raw')) {
            $altText = $this->extractWithMode($pdfPath, !$useLayout);
            if ($altText !== null) {
                $language = $options['language'] ?? Config::getInstance()->getConfig('PDFSettings', 'tesseract_lang') ?? 'deu+eng';
                $primaryScore = TextQualityAnalyzer::calculateQualityScore($text, $language);
                $altScore = TextQualityAnalyzer::calculateQualityScore($altText, $language);

                $primaryMode = $useLayout ? 'layout' : 'raw';
                $altMode = $useLayout ? 'raw' : 'layout';
                $this->logDebug("pdftotext dual strategy: $primaryMode=" . round($primaryScore, 2) . ", $altMode=" . round($altScore, 2));

                if ($altScore > $primaryScore) {
                    $this->logDebug("Using $altMode result (better score)");
                    return $altText;
                }
            }
        }

        return $text;
    }

    /**
     * Extrahiert Text in einem bestimmten Modus (Layout oder Raw).
     */
    private function extractWithMode(string $pdfPath, bool $useLayout): ?string {
        $tempFile = sys_get_temp_dir() . '/pdftotext_' . uniqid() . '.txt';

        $configKey = $useLayout ? 'pdftotext' : 'pdftotext-raw';

        // Fallback auf pdftotext falls pdftotext-raw nicht konfiguriert
        if (!$useLayout && !$this->config->isExecutableAvailable('pdftotext-raw')) {
            $configKey = 'pdftotext';
            $this->logDebug('pdftotext-raw not available, using pdftotext with layout');
        }

        $command = $this->config->buildCommand($configKey, [
            '[PDF-FILE]' => $pdfPath,
            '[TEXT-FILE]' => $tempFile,
        ]);

        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $this->logDebug("pdftotext ($configKey) failed with code $returnCode for: $pdfPath");
            File::delete($tempFile);
            return null;
        }

        if (!File::exists($tempFile)) {
            $this->logDebug("pdftotext ($configKey) produced no output for: $pdfPath");
            return null;
        }

        $text = File::read($tempFile);
        File::delete($tempFile);

        // Prüfen ob relevanter Text extrahiert wurde
        $trimmed = preg_replace('/\s+/', '', $text);
        if (strlen($trimmed) < 10) {
            $this->logDebug("pdftotext ($configKey) extracted too little text from: $pdfPath");
            return null;
        }

        $mode = $useLayout ? 'layout' : 'raw';
        return $this->logDebugAndReturn($text, "pdftotext ($mode) successfully extracted " . strlen($text) . " chars from: $pdfPath");
    }
}
