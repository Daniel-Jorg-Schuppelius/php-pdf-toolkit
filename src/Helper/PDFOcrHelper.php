<?php
/*
 * Created on   : Wed Oct 14 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFOcrHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;
use PDFToolkit\Config\Config;
use PDFToolkit\Readers\TesseractReader;

/**
 * Durchsuchbares PDF über OCRmyPDF: eine unsichtbare Textebene über den
 * Seitenbildern - der eine ocrmypdf-Weg des Pakets. Der Reader
 * ({@see \PDFToolkit\Readers\OcrMyPDFReader}) nimmt dazu die Textausgabe
 * (--sidecar), Anwendungen die PDF selbst (v1-Ziel "pdf_ocr").
 *
 * Die Schalter stehen in config/executables.json (`ocrmypdf`, `ocrmypdf-force`
 * und je eine Sidecar-Variante) und sind für alle Aufrufer dieselben:
 * --rotate-pages dreht quer oder kopfüber gescannte Seiten vor der Erkennung,
 * --deskew richtet schiefe Seiten gerade (auch im Ausgabebild), --clean
 * entrauscht nur das Bild für Tesseract (unpaper), die Ausgabe bleibt das
 * Original. Messung und Begründung: README, Abschnitt OCRmyPDF.
 *
 * Standard ist --skip-text (Seiten mit Text bleiben, wie sie sind); mit
 * $forceOcr wird eine vorhandene, unbrauchbare Textebene verworfen und neu
 * erkannt. Die Seitensegmentierung folgt v1: `ocrmypdf_psm` (11, dünner Text),
 * bei Nicht-A4-Seiten 12 ({@see PDFHelper::suggestScanPsm()}).
 */
final class PDFOcrHelper {
    use ErrorLog;

    public const DEFAULT_LANGUAGE = 'deu+eng';

    public const DEFAULT_PSM = 11;

    /** ocrmypdf: alle Seiten hatten schon Text (PriorOcrFoundError) */
    public const EXIT_PRIOR_OCR = 6;

    /**
     * ocrmypdf und Tesseract stehen bereit.
     */
    public static function isAvailable(bool $forceOcr = false): bool {
        return Config::getInstance()->isExecutableAvailable($forceOcr ? 'ocrmypdf-force' : 'ocrmypdf');
    }

    /**
     * Legt per ocrmypdf eine Textebene über die Seiten von $inputPath und
     * schreibt das Ergebnis nach $outputPath.
     *
     * @param bool $forceOcr Vorhandene Textebene verwerfen und neu erkennen (--force-ocr)
     * @param string $language Tesseract-Sprachen, z.B. "deu+eng"
     * @param int|null $psm Seitensegmentierung; null = nach Seitengröße (v1)
     * @param string|null $sidecarPath Zusätzlich den erkannten Text in diese Datei schreiben
     * @param list<string>|null $messages Referenz: Meldungen von ocrmypdf, wenn der Lauf scheiterte
     * @param-out list<string> $messages
     * @return bool true, wenn $outputPath geschrieben wurde. Hatten alle Seiten
     *              schon Text, ist die Ausgabe eine Kopie der Eingabe.
     * @throws InvalidArgumentException wenn die Sprache nicht unterstützt wird
     */
    public static function makeSearchable(string $inputPath, string $outputPath, bool $forceOcr = false, string $language = self::DEFAULT_LANGUAGE, ?int $psm = null, ?string $sidecarPath = null, ?array &$messages = null): bool {
        $messages = [];
        if (!TesseractDataHelper::isSupportedLanguage($language)) {
            self::logErrorAndThrow(InvalidArgumentException::class, 'OCR-Sprache wird nicht unterstützt: ' . $language);
        }
        if (!File::exists($inputPath)) {
            $messages[] = 'Eingabe fehlt: ' . basename($inputPath);
            return false;
        }

        $psm ??= PDFHelper::suggestScanPsm($inputPath, self::configuredPsm());
        $configKey = ($forceOcr ? 'ocrmypdf-force' : 'ocrmypdf') . ($sidecarPath !== null ? '-sidecar' : '');
        $replacements = [
            '[PSM]' => (string) $psm,
            '[LANG]' => $language,
            '[INPUT]' => $inputPath,
            '[OUTPUT]' => $outputPath,
        ];
        if ($sidecarPath !== null) {
            $replacements['[SIDECAR]'] = $sidecarPath;
        }

        $command = Config::getInstance()->buildCommand($configKey, $replacements);
        if ($command === null) {
            $messages[] = "ocrmypdf ist nicht verfügbar ({$configKey})";
            return self::logDebugAndReturn(false, "ocrmypdf ({$configKey}) nicht verfügbar für: {$inputPath}");
        }

        // Sprachdaten: liegen sie nicht im System-tessdata, zeigt TESSDATA_PREFIX
        // auf das (nachgeladene) Verzeichnis samt configs/, die ocrmypdf beim
        // Namen ruft ("pdf txt", "hocr txt"). Ein Thread je Tesseract-Aufruf:
        // ocrmypdf verteilt die Seiten selbst.
        $dataPath = self::dataPath($language);
        if ($dataPath !== null) {
            $command = 'TESSDATA_PREFIX=' . escapeshellarg($dataPath) . ' ' . $command;
        }
        $command = TesseractReader::OMP_SINGLE_THREAD . ' ' . $command;

        $output = [];
        $returnCode = 0;
        // Frist nach Seitenzahl: ein Haenger endet, grosse Scans laufen durch
        Shell::executeShellCommand($command, $output, $returnCode, timeout: ToolTimeout::forOcr($inputPath));

        if ($returnCode === self::EXIT_PRIOR_OCR && !File::exists($outputPath)) {
            // Alle Seiten hatten schon Text: das Original ist bereits durchsuchbar
            File::copy($inputPath, $outputPath);
        }
        if (($returnCode !== 0 && $returnCode !== self::EXIT_PRIOR_OCR) || !File::exists($outputPath)) {
            $messages = $returnCode === Shell::EXIT_TIMEOUT ? ['Zeitgrenze überschritten'] : $output;
            // Kein halbes Ergebnis liegen lassen
            if (File::exists($outputPath)) {
                File::delete($outputPath);
            }

            return self::logDebugAndReturn(false, "ocrmypdf ({$configKey}, PSM {$psm}) fehlgeschlagen (Code {$returnCode}) für: {$inputPath}");
        }

        return self::logDebugAndReturn(true, "ocrmypdf ({$configKey}, PSM {$psm}): " . basename($inputPath) . ' -> durchsuchbares PDF');
    }

    /** PSM-Standard aus PDFSettings (`ocrmypdf_psm`). */
    private static function configuredPsm(): int {
        return (int) (Config::getInstance()->getConfig('PDFSettings', 'ocrmypdf_psm') ?? self::DEFAULT_PSM);
    }

    /**
     * TESSDATA_PREFIX für die Sprachen: ein konfiguriertes Verzeichnis
     * (`tesseract_data_path`) samt configs/, sonst das nachgeladene des Pakets;
     * null = System-tessdata.
     */
    private static function dataPath(string $language): ?string {
        $configured = (string) (Config::getInstance()->getConfig('PDFSettings', 'tesseract_data_path') ?? '');
        if ($configured !== '' && Folder::exists($configured)) {
            TesseractDataHelper::ensureConfigs($configured);

            return $configured;
        }

        $usable = TesseractDataHelper::getUsableDataPath($language);

        return $usable !== null && Folder::exists($usable) ? $usable : null;
    }
}
