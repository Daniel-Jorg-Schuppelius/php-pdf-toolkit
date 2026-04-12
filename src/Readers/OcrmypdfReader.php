<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OcrmypdfReader.php
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
use PDFToolkit\Helper\{PDFHelper, TesseractDataHelper, TextQualityAnalyzer};
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
    private string $tessDataPath;
    private int $defaultPsm;

    public function __construct() {
        $this->loadConfig();
    }

    private function loadConfig(): void {
        $this->config = Config::getInstance();
        $this->defaultLanguage = $this->config->getConfig('PDFSettings', 'tesseract_lang') ?? 'deu+eng';
        $this->tessDataPath = $this->config->getConfig('PDFSettings', 'tesseract_data_path') ?? '';
        $this->defaultPsm = (int) ($this->config->getConfig('PDFSettings', 'ocrmypdf_psm') ?? 11);

        // Fallback auf lokales data-Verzeichnis mit automatischem Download
        if (empty($this->tessDataPath)) {
            $usablePath = TesseractDataHelper::getUsableDataPath($this->defaultLanguage);
            if ($usablePath !== null) {
                $this->tessDataPath = $usablePath;
            }
        }
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
        $qualityCheck = $options['qualityCheck'] ?? true;
        $qualityThreshold = (float) ($options['qualityThreshold'] ?? Config::getInstance()->getConfig('PDFSettings', 'quality_threshold') ?? 60.0);

        // Sprache aus PDF-Metadaten als Hinweis verwenden
        $detectedLang = PDFHelper::detectLanguage($pdfPath);
        if ($detectedLang !== null && !str_contains($language, $detectedLang)) {
            $language = $detectedLang . '+' . $language;
            $this->logDebug("Added detected language '$detectedLang' from PDF metadata: $language");
        }

        $text = $this->extractWithSettings($pdfPath, $language, $psm);

        if ($text === null || !$qualityCheck) {
            return $text;
        }

        // PSM-Fallback bei niedrigem Score
        $score = TextQualityAnalyzer::calculateQualityScore($text, $language);
        $this->logDebug("ocrmypdf ($language, PSM=$psm): quality score " . round($score, 2));

        if ($score < $qualityThreshold) {
            // PSM 11 (sparse text) und PSM 3 (auto) als Fallbacks
            $psmFallbacks = array_values(array_filter([3, 6, 11], fn(int $p) => $p !== $psm));

            foreach ($psmFallbacks as $altPsm) {
                $altText = $this->extractWithSettings($pdfPath, $language, $altPsm);
                if ($altText !== null) {
                    $altScore = TextQualityAnalyzer::calculateQualityScore($altText, $language);
                    $this->logDebug("ocrmypdf PSM fallback ($language, PSM=$altPsm): score " . round($altScore, 2));

                    if ($altScore > $score) {
                        $text = $altText;
                        $score = $altScore;
                        if ($score >= $qualityThreshold) {
                            break;
                        }
                    }
                }
            }
        }

        return $text;
    }

    /**
     * Extrahiert Text mit spezifischen OCRmyPDF-Einstellungen.
     * 
     * Nutzt --sidecar für direkte Textausgabe wenn verfügbar,
     * ansonsten Fallback auf pdftotext-Nachverarbeitung.
     */
    private function extractWithSettings(string $pdfPath, string $language, int $psm): ?string {
        $tempPdf = sys_get_temp_dir() . '/ocrmypdf_' . uniqid() . '.pdf';
        $tempTxt = sys_get_temp_dir() . '/ocrmypdf_' . uniqid() . '.txt';

        try {
            // Sidecar-Modus bevorzugen (direkter Text ohne erneutes pdftotext)
            $useSidecar = $this->config->isExecutableAvailable('ocrmypdf-sidecar');
            $configKey = $useSidecar ? 'ocrmypdf-sidecar' : 'ocrmypdf';

            $replacements = [
                '[PSM]' => (string) $psm,
                '[LANG]' => $language,
                '[INPUT]' => $pdfPath,
                '[OUTPUT]' => $tempPdf,
            ];

            if ($useSidecar) {
                $replacements['[SIDECAR]'] = $tempTxt;
            }

            $command = $this->config->buildCommand($configKey, $replacements);

            // TESSDATA_PREFIX setzen falls eigene Trainingsdaten vorhanden
            if (!empty($this->tessDataPath) && Folder::exists($this->tessDataPath)) {
                $command = "TESSDATA_PREFIX=" . escapeshellarg($this->tessDataPath) . " " . $command;
            }

            // stderr unterdrücken (OSD "Weak margin" Warnungen sind harmlos)
            $command .= ' 2>/dev/null';

            $output = [];
            $returnCode = 0;
            Shell::executeShellCommand($command, $output, $returnCode);

            // Return-Codes: 0 = OK, 6 = bereits Text vorhanden (--skip-text)
            if ($returnCode !== 0 && $returnCode !== 6) {
                $this->logDebug("ocrmypdf ($configKey, PSM=$psm) failed with code $returnCode for: $pdfPath");
                return null;
            }

            // Sidecar hat den Text bereits direkt erzeugt
            if ($useSidecar && File::exists($tempTxt)) {
                $text = File::read($tempTxt);
                // ocrmypdf schreibt "[OCR skipped on page(s) ...]" bei --skip-text (Text-PDFs)
                // Diese Marker entfernen, damit nur echter OCR-Text validiert wird
                $cleanedText = preg_replace('/\[OCR skipped on page\(s\) [0-9, -]+\]\s*/', '', $text);
                $validated = $this->validateExtractedText($cleanedText, $pdfPath, "sidecar PSM=$psm");
                if ($validated !== null) {
                    return $validated;
                }
                // Sidecar zu wenig Text (z.B. --skip-text bei Text-PDF) → pdftotext-Fallback
                $this->logDebug("Sidecar text insufficient, falling back to pdftotext on output PDF");
            }

            // Fallback: Text aus dem OCR-verarbeiteten PDF extrahieren
            $pdfToExtract = File::exists($tempPdf) ? $tempPdf : $pdfPath;

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
            return $this->validateExtractedText($text, $pdfPath, "pdftotext PSM=$psm");
        } finally {
            // Aufräumen
            File::delete($tempPdf);
            File::delete($tempTxt);
        }
    }

    /**
     * Validiert den extrahierten Text (Mindestlänge).
     */
    private function validateExtractedText(string $text, string $pdfPath, string $mode): ?string {
        $trimmed = preg_replace('/\s+/', '', $text);
        if (strlen($trimmed) < 10) {
            $this->logDebug("ocrmypdf ($mode) extracted too little text from: $pdfPath");
            return null;
        }

        return $this->logDebugAndReturn($text, "ocrmypdf ($mode) successfully extracted " . strlen($text) . " chars from: $pdfPath");
    }
}
