<?php
/*
 * Created on   : Fri Jan 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TesseractDataHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\Data\NumberHelper;
use CommonToolkit\Helper\FileSystem\{File, Folder};
use ERRORToolkit\Traits\ErrorLog;

/**
 * Helper-Klasse für Tesseract OCR Trainingsdaten.
 *
 * Lädt fehlende Trainingsdaten automatisch von GitHub herunter.
 */
final class TesseractDataHelper {
    use ErrorLog;

    private const TESSDATA_BASE_URL = 'https://github.com/tesseract-ocr/tessdata/raw/main/';
    private const DEFAULT_LANGUAGES = ['deu', 'eng'];

    /**
     * Sprachen, die geladen und an Tesseract gegeben werden. Die Angabe kommt
     * auch aus Kundenaufrufen; ohne diese Liste ginge freier Text in Pfade
     * und Download-Adressen. Codes nach tessdata (ISO 639-2 plus Varianten).
     */
    public const SUPPORTED_LANGUAGES = [
        'deu', 'eng', 'fra', 'ita', 'spa', 'nld', 'pol', 'por', 'ces', 'dan', 'swe', 'nor',
        'fin', 'hun', 'ron', 'tur', 'ell', 'rus', 'ukr', 'deu_frak', 'osd',
    ];
    private const REQUIRED_DATA_FILES = ['osd']; // Für Auto-Orientation/Script-Detection
    private const MIN_TRAINEDDATA_SIZE = 1024 * 1024; // 1 MB

    /**
     * Was Tesseract neben den Sprachdaten im Datenverzeichnis erwartet:
     * configs/ und tessconfigs/ (Parameterdateien, die als Namen auf der
     * Kommandozeile stehen - ocrmypdf ruft "pdf txt" bzw. "hocr txt" auf)
     * und pdf.ttf (Schrift der PDF-Ausgabe aelterer Versionen). Zeigt
     * TESSDATA_PREFIX auf ein Verzeichnis ohne sie, meldet Tesseract
     * "read_params_file: Can't open pdf" und schreibt kein PDF: ocrmypdf
     * bricht ab oder liefert still eine Ausgabe ohne Textebene.
     */
    private const CONFIG_DIRS = ['configs', 'tessconfigs'];
    private const CONFIG_FILES = ['pdf.ttf'];

    /** Ohne diese vier Dateien gibt es keine PDF-, Text-, hOCR- oder TSV-Ausgabe. */
    private const REQUIRED_CONFIGS = ['configs/pdf', 'configs/txt', 'configs/hocr', 'configs/tsv'];

    /** Uebliche Orte der System-Sprachdaten (Debian/Ubuntu, Quelle, Homebrew). */
    private const SYSTEM_DATA_PATHS = [
        '/usr/share/tesseract-ocr/*/tessdata',
        '/usr/share/tessdata',
        '/usr/local/share/tessdata',
        '/opt/homebrew/share/tessdata',
    ];

    /**
     * Prüft ob traineddata-Dateien im Verzeichnis vorhanden sind.
     */
    public static function hasTrainedData(string $path): bool {
        if (!Folder::exists($path)) {
            return false;
        }
        $files = glob($path . '/*.traineddata');
        return !empty($files);
    }

    /**
     * Zerlegt eine Sprachangabe ("deu+eng") in ihre Codes; leer für eine
     * leere Angabe, null, wenn ein Code nicht in {@see SUPPORTED_LANGUAGES}
     * steht.
     *
     * @return list<string>|null
     */
    public static function languageCodes(string $language): ?array {
        $codes = [];
        foreach (explode('+', $language) as $code) {
            $code = trim($code);
            if ($code === '') {
                continue;
            }
            if (!in_array($code, self::SUPPORTED_LANGUAGES, true)) {
                self::logError('Nicht unterstützte OCR-Sprache abgelehnt', ['language' => $language]);
                return null;
            }
            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }

    /**
     * Ob eine Sprachangabe ("deu+eng") mindestens einen und nur unterstützte
     * Codes enthält.
     */
    public static function isSupportedLanguage(string $language): bool {
        $codes = self::languageCodes($language);

        return $codes !== null && $codes !== [];
    }

    /**
     * Prüft ob eine bestimmte Sprache verfügbar ist.
     */
    public static function hasLanguage(string $path, string $language): bool {
        $languages = self::languageCodes($language);
        if ($languages === null) {
            return false;
        }
        foreach ($languages as $lang) {
            $lang = trim($lang);
            if (!empty($lang) && !File::exists($path . '/' . $lang . '.traineddata')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Lädt fehlende Trainingsdaten herunter.
     *
     * @param string $targetPath Zielverzeichnis für die Trainingsdaten
     * @param string|null $language Sprachen im Format "deu+eng" (null = Standardsprachen)
     * @return bool True wenn alle Daten verfügbar sind
     */
    public static function ensureTrainedData(string $targetPath, ?string $language = null): bool {
        // Verzeichnis erstellen falls nicht vorhanden
        if (!Folder::exists($targetPath)) {
            try {
                Folder::create($targetPath, 0755, true);
            } catch (\Throwable $e) {
                self::logError("Konnte Verzeichnis nicht erstellen: $targetPath - " . $e->getMessage());
                return false;
            }
        }

        // Zu ladende Sprachen ermitteln; nur Codes aus der Positivliste
        $languages = $language !== null ? self::languageCodes($language) : self::DEFAULT_LANGUAGES;
        if ($languages === null) {
            return false;
        }

        // osd.traineddata ist Pflicht für Auto-Orientation/Script-Detection
        $languages = array_unique(array_merge(self::REQUIRED_DATA_FILES, $languages));

        $success = true;
        foreach ($languages as $lang) {
            if (empty($lang)) {
                continue;
            }

            $targetFile = $targetPath . '/' . $lang . '.traineddata';

            if (File::exists($targetFile)) {
                self::logDebug("Trainingsdaten bereits vorhanden: $lang");
                continue;
            }

            if (!self::downloadTrainedData($lang, $targetFile)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Lädt eine einzelne traineddata-Datei herunter.
     */
    private static function downloadTrainedData(string $language, string $targetFile): bool {
        $url = self::TESSDATA_BASE_URL . $language . '.traineddata';

        self::logInfo("Lade Tesseract-Trainingsdaten herunter: $language von $url");

        $tempFile = $targetFile . '.tmp';

        try {
            // Chunk-basierter Download (speichereffizient für große Dateien)
            $bytesDownloaded = File::download($url, $tempFile);

            if ($bytesDownloaded === false) {
                self::logError("Download fehlgeschlagen: $language");
                return false;
            }

            // Prüfe ob die Datei gültig ist (mindestens 1MB für traineddata)
            if ($bytesDownloaded < self::MIN_TRAINEDDATA_SIZE) {
                self::logError("Heruntergeladene Datei zu klein, möglicherweise ungültig: $language ($bytesDownloaded Bytes)");
                File::delete($tempFile);
                return false;
            }

            // Umbenennen zur Zieldatei (atomar)
            File::rename($tempFile, $targetFile);

            self::logInfo("Trainingsdaten erfolgreich heruntergeladen: $language (" . NumberHelper::formatBytes($bytesDownloaded) . ")");
            return true;
        } catch (\Throwable $e) {
            if (File::exists($tempFile)) {
                File::delete($tempFile);
            }
            self::logError("Fehler beim Download von $language: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gibt den Standard-Pfad für lokale Trainingsdaten zurück.
     */
    public static function getLocalDataPath(): string {
        return dirname(__DIR__, 2) . '/data/tesseract';
    }

    /**
     * Ob ein Datenverzeichnis die Parameterdateien traegt, die Tesseract fuer
     * die PDF-, Text-, hOCR- und TSV-Ausgabe braucht (configs/pdf usw.).
     */
    public static function hasConfigs(string $path): bool {
        foreach (self::REQUIRED_CONFIGS as $relative) {
            if (!File::exists($path . '/' . $relative)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Stellt configs/, tessconfigs/ und pdf.ttf in einem Datenverzeichnis
     * sicher, das als TESSDATA_PREFIX dienen soll. Quelle ist das mitgelieferte
     * Verzeichnis des Toolkits, sonst die System-Sprachdaten; vorhandene
     * Dateien bleiben unangetastet.
     *
     * @return bool true, wenn die Pflichtdateien danach vorhanden sind
     */
    public static function ensureConfigs(string $path): bool {
        if (self::hasConfigs($path)) {
            return true;
        }
        if (!Folder::exists($path)) {
            return false;
        }

        foreach (self::configSources($path) as $source) {
            try {
                self::copyConfigs($source, $path);
            } catch (\Throwable $e) {
                self::logWarning('Tesseract-Konfigurationsdateien konnten nicht kopiert werden', ['from' => $source, 'to' => $path, 'error' => $e->getMessage()]);
            }
            if (self::hasConfigs($path)) {
                self::logDebug("Tesseract-Konfigurationsdateien ergaenzt: $path (aus $source)");

                return true;
            }
        }

        self::logError('Tesseract-Konfigurationsdateien (configs/pdf, configs/txt, configs/hocr, configs/tsv) fehlen, keine Quelle gefunden', ['path' => $path]);

        return false;
    }

    /**
     * Das Datenverzeichnis des installierten Tesseract: TESSDATA_PREFIX der
     * Umgebung, sonst der erste uebliche Installationsort mit configs/pdf
     * (neueste Version zuerst).
     */
    public static function systemDataPath(): ?string {
        $env = getenv('TESSDATA_PREFIX');
        if (is_string($env) && $env !== '' && File::exists(rtrim($env, '/') . '/configs/pdf')) {
            return rtrim($env, '/');
        }

        foreach (self::SYSTEM_DATA_PATHS as $pattern) {
            $matches = glob($pattern, GLOB_ONLYDIR) ?: [];
            rsort($matches, SORT_NATURAL);
            foreach ($matches as $dir) {
                if (File::exists($dir . '/configs/pdf')) {
                    return $dir;
                }
            }
        }

        return null;
    }

    /**
     * Quellen fuer die Konfigurationsdateien, in Reihenfolge: das mitgelieferte
     * Verzeichnis (sofern es nicht selbst das Ziel ist), dann das System.
     *
     * @return list<string>
     */
    private static function configSources(string $target): array {
        $sources = [];
        $bundled = self::getLocalDataPath();
        if (realpath($bundled) !== realpath($target) && self::hasConfigs($bundled)) {
            $sources[] = $bundled;
        }
        $system = self::systemDataPath();
        if ($system !== null && realpath($system) !== realpath($target)) {
            $sources[] = $system;
        }

        return $sources;
    }

    private static function copyConfigs(string $source, string $target): void {
        foreach (self::CONFIG_DIRS as $dir) {
            $from = $source . '/' . $dir;
            if (!Folder::exists($from)) {
                continue;
            }
            $to = $target . '/' . $dir;
            if (!Folder::exists($to)) {
                Folder::create($to, 0755, true);
            }
            foreach (glob($from . '/*') ?: [] as $file) {
                $dest = $to . '/' . basename($file);
                if (is_file($file) && !File::exists($dest)) {
                    File::copy($file, $dest);
                }
            }
        }
        foreach (self::CONFIG_FILES as $name) {
            $from = $source . '/' . $name;
            $dest = $target . '/' . $name;
            if (File::exists($from) && !File::exists($dest)) {
                File::copy($from, $dest);
            }
        }
    }

    /** Liefert den Pfad nach Sicherung der Konfigurationsdateien; ein Fehlschlag steht im Log. */
    private static function withConfigs(string $path): string {
        self::ensureConfigs($path);

        return $path;
    }

    /**
     * Prüft ob der lokale Datenpfad verwendbar ist und lädt ggf. Daten herunter.
     *
     * @param string|null $language Sprachen im Format "deu+eng"
     * @return string|null Pfad zu den Trainingsdaten oder null wenn nicht verfügbar
     */
    public static function getUsableDataPath(?string $language = null): ?string {
        if ($language !== null && self::languageCodes($language) === null) {
            return null;
        }

        $localPath = self::getLocalDataPath();

        // Prüfe ob Daten vorhanden oder herunterladbar. Das Verzeichnis wird zum
        // TESSDATA_PREFIX und muss deshalb auch die Konfigurationsdateien tragen.
        if (self::hasTrainedData($localPath)) {
            // Prüfe ob die benötigten Sprachen vorhanden sind
            if ($language === null || self::hasLanguage($localPath, $language)) {
                return self::withConfigs($localPath);
            }

            // Versuche fehlende Sprachen herunterzuladen
            if (self::ensureTrainedData($localPath, $language)) {
                return self::withConfigs($localPath);
            }
        } else {
            // Keine Daten vorhanden, versuche herunterzuladen
            if (self::ensureTrainedData($localPath, $language)) {
                return self::withConfigs($localPath);
            }
        }

        // Fallback: System-Tesseract verwenden
        self::logDebug("Verwende System-Tesseract-Daten");
        return null;
    }
}
