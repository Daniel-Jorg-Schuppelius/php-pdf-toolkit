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
use PDFToolkit\Helper\{PDFHelper, TesseractDataHelper, TextQualityAnalyzer};
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
    private bool $autoSelectBestLanguage;

    public function __construct() {
        $this->loadConfig();
    }

    private function loadConfig(): void {
        $this->config = Config::getInstance();

        $this->defaultLanguage = $this->config->getConfig('PDFSettings', 'tesseract_lang') ?? 'deu+eng';
        $this->tessDataPath = $this->config->getConfig('PDFSettings', 'tesseract_data_path') ?? '';
        $this->defaultPsm = (int) ($this->config->getConfig('PDFSettings', 'tesseract_psm') ?? 3);
        $this->defaultDpi = (int) ($this->config->getConfig('PDFSettings', 'pdftoppm_dpi') ?? 300);
        $this->autoSelectBestLanguage = (bool) ($this->config->getConfig('PDFSettings', 'tesseract_auto_select_language') ?? true);

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
        $autoSelect = $options['auto_select_language'] ?? $this->autoSelectBestLanguage;

        // Sprache aus PDF-Metadaten als Hinweis verwenden
        $detectedLang = PDFHelper::detectLanguage($pdfPath);
        if ($detectedLang !== null && !str_contains($language, $detectedLang)) {
            $language = $detectedLang . '+' . $language;
            $this->logDebug("Added detected language '$detectedLang' from PDF metadata: $language");
        }

        // Prüfe ob automatische Sprachauswahl aktiviert ist und mehrere Sprachen konfiguriert sind
        if ($autoSelect && str_contains($language, '+')) {
            return $this->extractTextWithBestLanguage($pdfPath, $language, $options);
        }

        return $this->extractTextWithLanguage($pdfPath, $language, $options);
    }

    /**
     * Extrahiert Text mit automatischer Auswahl der besten Sprache.
     * 
     * Testet jede konfigurierte Sprache separat und wählt das Ergebnis
     * mit der höchsten Qualität basierend auf TextQualityAnalyzer.
     */
    private function extractTextWithBestLanguage(string $pdfPath, string $languages, array $options): ?string {
        $languageList = array_map('trim', explode('+', $languages));

        if (count($languageList) < 2) {
            return $this->extractTextWithLanguage($pdfPath, $languages, $options);
        }

        $this->logInfo("Auto-selecting best language from: " . implode(', ', $languageList));

        // Sammle Ergebnisse für jede Sprache
        $results = [];
        foreach ($languageList as $lang) {
            $text = $this->extractTextWithLanguage($pdfPath, $lang, $options);
            if ($text !== null && trim($text) !== '') {
                $results[$lang] = $text;
            }
        }

        // Auch kombinierte Sprache testen (kann manchmal besser sein)
        $combinedText = $this->extractTextWithLanguage($pdfPath, $languages, $options);
        if ($combinedText !== null && trim($combinedText) !== '') {
            $results[$languages] = $combinedText;
        }

        if (empty($results)) {
            $this->logDebug("No text extracted with any language from: $pdfPath");
            return null;
        }

        // Beste Ergebnis auswählen
        $best = TextQualityAnalyzer::selectBestResult($results);

        if (empty($best['text'])) {
            return null;
        }

        $this->logInfo("Selected language '{$best['language']}' with quality score " . round($best['score'], 2));

        return $best['text'];
    }

    /**
     * Extrahiert Text mit einer spezifischen Sprache.
     * 
     * Bei aktivierter Qualitätsprüfung wird bei niedrigem Score:
     * - Erst mit einem anderen PSM-Modus versucht (PSM-Fallback)
     * - Dann mit höherer DPI versucht (Adaptive DPI)
     */
    private function extractTextWithLanguage(string $pdfPath, string $language, array $options = []): ?string {
        $psm = $options['psm'] ?? $this->defaultPsm;
        $dpi = $options['dpi'] ?? $this->defaultDpi;
        $qualityCheck = $options['qualityCheck'] ?? true;
        $qualityThreshold = (float) ($options['qualityThreshold'] ?? Config::getInstance()->getConfig('PDFSettings', 'quality_threshold') ?? 60.0);

        $text = $this->extractTextWithSettings($pdfPath, $language, $psm, $dpi);

        if ($text === null || !$qualityCheck) {
            return $text;
        }

        $score = TextQualityAnalyzer::calculateQualityScore($text, $language);
        $this->logDebug("Tesseract ($language, PSM=$psm, DPI=$dpi): quality score " . round($score, 2));

        if ($score >= $qualityThreshold) {
            return $text;
        }

        // PSM-Fallback: Verschiedene PSM-Modi probieren
        $psmFallbacks = $this->getPsmFallbacks($psm);
        foreach ($psmFallbacks as $altPsm) {
            $altText = $this->extractTextWithSettings($pdfPath, $language, $altPsm, $dpi);
            if ($altText !== null) {
                $altScore = TextQualityAnalyzer::calculateQualityScore($altText, $language);
                $this->logDebug("Tesseract PSM fallback ($language, PSM=$altPsm, DPI=$dpi): score " . round($altScore, 2));

                if ($altScore > $score) {
                    $text = $altText;
                    $score = $altScore;
                    if ($score >= $qualityThreshold) {
                        return $text;
                    }
                }
            }
        }

        // Adaptive DPI: Bei immer noch niedrigem Score mit höherer DPI versuchen
        if ($score < $qualityThreshold && $dpi < 600) {
            $higherDpi = min($dpi + 150, 600);
            $this->logDebug("Adaptive DPI: retrying with DPI=$higherDpi (current score: " . round($score, 2) . ")");

            $altText = $this->extractTextWithSettings($pdfPath, $language, $psm, $higherDpi);
            if ($altText !== null) {
                $altScore = TextQualityAnalyzer::calculateQualityScore($altText, $language);
                $this->logDebug("Tesseract adaptive DPI ($language, PSM=$psm, DPI=$higherDpi): score " . round($altScore, 2));

                if ($altScore > $score) {
                    $text = $altText;
                }
            }
        }

        return $text;
    }

    /**
     * Gibt alternative PSM-Modi zurück, sortiert nach Wahrscheinlichkeit für bessere Ergebnisse.
     * 
     * @param int $currentPsm Aktueller PSM-Modus
     * @return int[] Alternative PSM-Modi
     */
    private function getPsmFallbacks(int $currentPsm): array {
        // PSM 3 = Auto, PSM 6 = Uniform Block, PSM 4 = Single Column, PSM 1 = Auto + OSD
        $all = [3, 6, 4, 1];
        return array_values(array_filter($all, fn(int $psm) => $psm !== $currentPsm));
    }

    /**
     * Extrahiert Text mit spezifischen Tesseract-Einstellungen (Sprache, PSM, DPI).
     */
    private function extractTextWithSettings(string $pdfPath, string $language, int $psm, int $dpi): ?string {
        $tempDir = sys_get_temp_dir() . '/tesseract_' . uniqid();

        if (!mkdir($tempDir, 0755, true)) {
            $this->logError("Failed to create temp directory: $tempDir");
            return null;
        }

        try {
            // 1. PDF zu PNG konvertieren
            $command = $this->config->buildCommand('pdftoppm', [
                '[DPI]' => (string) $dpi,
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
                    '[PSM]' => (string) $psm,
                ]);

                // TESSDATA_PREFIX setzen falls eigene Trainingsdaten vorhanden
                if (!empty($this->tessDataPath) && Folder::exists($this->tessDataPath)) {
                    $command = "TESSDATA_PREFIX=" . escapeshellarg($this->tessDataPath) . " " . $command;
                }

                // stderr unterdrücken (OSD "Weak margin" Warnungen sind harmlos)
                $command .= ' 2>/dev/null';

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
                $this->logDebug("Tesseract ($language, PSM=$psm, DPI=$dpi) extracted no text from: $pdfPath");
                return null;
            }

            $text = implode("\n\n--- Seite ---\n\n", $allText);

            $this->logDebug("Tesseract ($language, PSM=$psm, DPI=$dpi) extracted " . strlen($text) . " chars from " . count($pages) . " pages");

            return $text;
        } finally {
            // Aufräumen - rekursiv löschen
            Folder::delete($tempDir, true);
        }
    }
}
