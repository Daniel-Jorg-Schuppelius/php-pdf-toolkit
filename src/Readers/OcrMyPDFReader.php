<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OcrMyPDFReader.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Readers;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;
use PDFToolkit\Contracts\PDFReaderInterface;
use PDFToolkit\Enums\PDFReaderType;
use PDFToolkit\Helper\{PDFHelper, PDFOcrHelper, TempPath, TesseractDataHelper, TextQualityAnalyzer, ToolTimeout};
use Throwable;

/**
 * PDF-Reader basierend auf OCRmyPDF.
 *
 * Erstellt über {@see PDFOcrHelper} ein durchsuchbares PDF aus gescannten
 * Dokumenten und liest dessen Text (--sidecar). Dieselben ocrmypdf-Schalter
 * wie für Anwendungen, die die durchsuchbare PDF selbst brauchen.
 */
final class OcrMyPDFReader implements PDFReaderInterface {
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
        $this->defaultLanguage = $this->config->getConfig('PDFSettings', 'tesseract_lang') ?? PDFOcrHelper::DEFAULT_LANGUAGE;
        // Sprachdaten (TESSDATA_PREFIX samt configs/) richtet PDFOcrHelper je Aufruf ein
        $this->defaultPsm = (int) ($this->config->getConfig('PDFSettings', 'ocrmypdf_psm') ?? PDFOcrHelper::DEFAULT_PSM);
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
        // forceOcr: vorhandenen (unbrauchbaren) Textlayer per --force-ocr verwerfen und neu
        // rastern, statt ihn mit --skip-text stehen zu lassen (v1-Parität pdfsandwich(true)).
        $forceOcr = (bool) ($options['forceOcr'] ?? false);
        // Start-PSM nach Seitengröße (v1: Nicht-A4 -> PSM 12); explizite Option hat Vorrang.
        $psm = $options['psm'] ?? PDFHelper::suggestScanPsm($pdfPath, $this->defaultPsm);
        $qualityCheck = $options['qualityCheck'] ?? true;
        $qualityThreshold = (float) ($options['qualityThreshold'] ?? Config::getInstance()->getConfig('PDFSettings', 'quality_threshold') ?? 60.0);

        // Sprache aus PDF-Metadaten als Hinweis verwenden
        $detectedLang = PDFHelper::detectLanguage($pdfPath);
        if ($detectedLang !== null && !str_contains($language, $detectedLang)) {
            $language = $detectedLang . '+' . $language;
            $this->logDebug("Added detected language '$detectedLang' from PDF metadata: $language");
        }

        $text = $this->extractWithSettings($pdfPath, $language, $psm, $forceOcr);

        if ($text === null || !$qualityCheck) {
            return $text;
        }

        // PSM-Fallback bei niedrigem Score
        $score = TextQualityAnalyzer::calculateQualityScore($text, $language);
        $this->logDebug("ocrmypdf ($language, PSM=$psm): quality score " . round($score, 2));

        if ($score < $qualityThreshold) {
            // PSM 11 (sparse text) und PSM 3 (auto) als Fallbacks
            $psmFallbacks = array_values(array_filter([3, 6, 11], fn (int $p) => $p !== $psm));

            foreach ($psmFallbacks as $altPsm) {
                $altText = $this->extractWithSettings($pdfPath, $language, $altPsm, $forceOcr);
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
     *
     * @param bool $forceOcr true -> --force-ocr-Variante (vorhandenen Textlayer verwerfen),
     *                        false -> --skip-text-Variante (vorhandenen Textlayer behalten)
     */
    private function extractWithSettings(string $pdfPath, string $language, int $psm, bool $forceOcr = false): ?string {
        if (!TesseractDataHelper::isSupportedLanguage($language)) {
            $this->logDebug("ocrmypdf: Sprache nicht unterstützt ($language) für: $pdfPath");
            return null;
        }

        // Privates Arbeitsverzeichnis: OCR-PDF und Text sind Dokumentinhalt
        try {
            $tempDir = TempPath::directory('ocrmypdf');
        } catch (Throwable $e) {
            $this->logDebug('ocrmypdf: Temp-Verzeichnis nicht anlegbar: ' . $e->getMessage());
            return null;
        }
        $tempPdf = $tempDir . '/ocr.pdf';
        $tempTxt = $tempDir . '/ocr.txt';

        try {
            // Sidecar-Modus bevorzugen (direkter Text ohne erneutes pdftotext).
            $useSidecar = $this->config->isExecutableAvailable($forceOcr ? 'ocrmypdf-force-sidecar' : 'ocrmypdf-sidecar');
            if (!PDFOcrHelper::makeSearchable($pdfPath, $tempPdf, $forceOcr, $language, $psm, $useSidecar ? $tempTxt : null)) {
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
                // Sidecar zu wenig Text (z.B. --skip-text bei Text-PDF) -> pdftotext-Fallback
                $this->logDebug("Sidecar text insufficient, falling back to pdftotext on output PDF");
            }

            // Fallback: Text aus dem OCR-verarbeiteten PDF extrahieren
            $command = $this->config->buildCommand('pdftotext', [
                '[PDF-FILE]' => $tempPdf,
                '[TEXT-FILE]' => $tempTxt,
            ]);
            if ($command === null) {
                $this->logDebug("pdftotext nicht verfügbar für: $pdfPath");
                return null;
            }

            $output = [];
            $returnCode = 0;
            Shell::executeShellCommand($command, $output, $returnCode, timeout: ToolTimeout::forSize($tempPdf));

            if ($returnCode !== 0 || !File::exists($tempTxt)) {
                $this->logDebug("pdftotext failed after ocrmypdf for: $pdfPath");
                return null;
            }

            $text = File::read($tempTxt);
            return $this->validateExtractedText($text, $pdfPath, "pdftotext PSM=$psm");
        } finally {
            // Aufräumen
            Folder::delete($tempDir, true);
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
