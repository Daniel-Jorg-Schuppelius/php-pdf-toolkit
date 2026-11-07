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
use PDFToolkit\Config\Config;

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

    /**
     * Lokale Modelle: jede `<name>.traineddata` im Datenverzeichnis (mitgeliefert
     * oder `tesseract_data_path`) ist ein gueltiger Sprachcode, auch wenn sie
     * nicht in {@see SUPPORTED_LANGUAGES} steht - etwa ein nachtrainiertes
     * `deu_finetuned`. Solche Modelle werden nie heruntergeladen. Der Name ist
     * auf das beschraenkt, was tessdata selbst vergibt (Kleinbuchstaben,
     * Ziffern, Unterstrich), damit er in Pfade und auf die Kommandozeile darf.
     */
    private const MODEL_NAME_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    /**
     * Einstellung PDFSettings/tesseract_models: Sprachcode => Modellname, als
     * JSON-Objekt ({"deu": "deu_finetuned"}) oder Text ("deu=deu_finetuned").
     * Aufrufer nennen weiter den Sprachcode ("deu+eng"); {@see resolveModels()}
     * setzt das Modell ein, sobald es lokal liegt. Fehlt es, bleibt das
     * Standardmodell (einmal je Prozess im Log).
     */
    public const MODELS_SETTING = 'tesseract_models';

    /** @var array<string, true> Modelle, deren Fehlen schon gemeldet wurde */
    private static array $missingModelsReported = [];
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
     * leere Angabe, null, wenn ein Code weder in {@see SUPPORTED_LANGUAGES}
     * steht noch ein lokales Modell ist ({@see isLocalModel()}).
     *
     * @param string|null $directory Zusaetzliches Verzeichnis, in dem lokale
     *                               Modelle liegen duerfen (z. B. das Ziel von
     *                               {@see ensureTrainedData()})
     * @return list<string>|null
     */
    public static function languageCodes(string $language, ?string $directory = null): ?array {
        $codes = [];
        foreach (explode('+', $language) as $code) {
            $code = trim($code);
            if ($code === '') {
                continue;
            }
            if (!in_array($code, self::SUPPORTED_LANGUAGES, true) && !self::isLocalModel($code, $directory)) {
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
    public static function isSupportedLanguage(string $language, ?string $directory = null): bool {
        $codes = self::languageCodes($language, $directory);

        return $codes !== null && $codes !== [];
    }

    /**
     * Ob $code ein lokales Modell ist: gueltiger Name und `<code>.traineddata`
     * in einem Modellverzeichnis ({@see modelDirectories()}, dazu $directory).
     */
    public static function isLocalModel(string $code, ?string $directory = null): bool {
        if (preg_match(self::MODEL_NAME_PATTERN, $code) !== 1) {
            return false;
        }
        foreach (self::modelDirectories($directory) as $dir) {
            if (File::exists($dir . '/' . $code . '.traineddata')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Setzt fuer jeden Sprachcode das konfigurierte Modell ein
     * ({@see MODELS_SETTING}), sofern es lokal liegt: "deu+eng" wird mit
     * {"deu": "deu_finetuned"} zu "deu_finetuned+eng". Ohne Einstellung oder
     * bei ungueltiger Angabe kommt die Angabe unveraendert zurueck. Idempotent:
     * ein Modellname bleibt ein Modellname. Gehoert ueberall dorthin, wo die
     * Sprache an Tesseract geht oder einen Cache-Schluessel bildet - sonst
     * liefert der OCR-Cache nach einem Modellwechsel den alten Text.
     */
    public static function resolveModels(string $language, ?string $directory = null): string {
        $codes = self::languageCodes($language, $directory);
        if ($codes === null || $codes === []) {
            return $language;
        }

        return implode('+', self::applyModels($codes, self::configuredModels(), $directory));
    }

    /**
     * Ersetzt Sprachcodes durch ihre Modelle, wenn das Modell lokal liegt;
     * ein fehlendes Modell wird einmal gemeldet und der Code behalten.
     *
     * @param list<string> $codes
     * @param array<string, string> $models Sprachcode => Modellname
     * @return list<string>
     */
    public static function applyModels(array $codes, array $models, ?string $directory = null): array {
        if ($models === []) {
            return $codes;
        }

        $resolved = [];
        foreach ($codes as $code) {
            $model = $models[$code] ?? $code;
            if ($model !== $code && !self::isLocalModel($model, $directory)) {
                if (!isset(self::$missingModelsReported[$model])) {
                    self::$missingModelsReported[$model] = true;
                    self::logWarning("OCR-Modell '$model' fuer '$code' liegt in keinem Datenverzeichnis, Standardmodell bleibt", ['directories' => self::modelDirectories($directory)]);
                }
                $model = $code;
            }
            $resolved[] = $model;
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Die konfigurierte Zuordnung Sprachcode => Modellname
     * ({@see MODELS_SETTING}); leer ohne Einstellung.
     *
     * @return array<string, string>
     */
    public static function configuredModels(): array {
        return self::parseModels(Config::getInstance()->getConfig('PDFSettings', self::MODELS_SETTING));
    }

    /**
     * Liest eine Modellzuordnung aus JSON-Objekt ({"deu": "deu_finetuned"})
     * oder Text ("deu=deu_finetuned,eng=eng_best"); Paare mit Namen ausserhalb
     * des Musters fallen weg.
     *
     * @return array<string, string>
     */
    public static function parseModels(mixed $value): array {
        if (is_string($value)) {
            $pairs = [];
            foreach (explode(',', $value) as $pair) {
                if (str_contains($pair, '=')) {
                    [$code, $model] = explode('=', $pair, 2);
                    $pairs[trim($code)] = trim($model);
                }
            }
            $value = $pairs;
        }
        if (!is_array($value)) {
            return [];
        }

        $models = [];
        foreach ($value as $code => $model) {
            if (is_string($code) && is_string($model) && preg_match(self::MODEL_NAME_PATTERN, $code) === 1 && preg_match(self::MODEL_NAME_PATTERN, $model) === 1) {
                $models[$code] = $model;
            }
        }

        return $models;
    }

    /**
     * Verzeichnisse, in denen lokale Modelle liegen duerfen: $directory,
     * das konfigurierte (`tesseract_data_path`), das mitgelieferte.
     *
     * @return list<string>
     */
    public static function modelDirectories(?string $directory = null): array {
        $directories = [];
        if ($directory !== null && $directory !== '') {
            $directories[] = rtrim($directory, '/');
        }
        $configured = (string) (Config::getInstance()->getConfig('PDFSettings', 'tesseract_data_path') ?? '');
        if ($configured !== '') {
            $directories[] = rtrim($configured, '/');
        }
        $directories[] = self::getLocalDataPath();

        return array_values(array_unique($directories));
    }

    /**
     * Prüft ob eine bestimmte Sprache verfügbar ist.
     */
    public static function hasLanguage(string $path, string $language): bool {
        $languages = self::languageCodes($language, $path);
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

        // Zu ladende Sprachen ermitteln; nur Codes aus der Positivliste oder lokale Modelle
        $languages = $language !== null ? self::languageCodes($language, $targetPath) : self::DEFAULT_LANGUAGES;
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

            if (!in_array($lang, self::SUPPORTED_LANGUAGES, true)) {
                // Lokales Modell: keine Download-Quelle, es muss im Zielverzeichnis liegen
                self::logError("Lokales OCR-Modell liegt nicht im Datenverzeichnis und hat keine Download-Quelle: $lang", ['path' => $targetPath]);
                $success = false;
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
        $localPath = self::getLocalDataPath();
        if ($language !== null && self::languageCodes($language, $localPath) === null) {
            return null;
        }

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
