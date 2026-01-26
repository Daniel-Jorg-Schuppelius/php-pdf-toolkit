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
    private const MIN_TRAINEDDATA_SIZE = 1024 * 1024; // 1 MB

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
     * Prüft ob eine bestimmte Sprache verfügbar ist.
     */
    public static function hasLanguage(string $path, string $language): bool {
        $languages = explode('+', $language);
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

        // Zu ladende Sprachen ermitteln
        $languages = $language !== null
            ? array_map('trim', explode('+', $language))
            : self::DEFAULT_LANGUAGES;

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
     * Prüft ob der lokale Datenpfad verwendbar ist und lädt ggf. Daten herunter.
     * 
     * @param string|null $language Sprachen im Format "deu+eng"
     * @return string|null Pfad zu den Trainingsdaten oder null wenn nicht verfügbar
     */
    public static function getUsableDataPath(?string $language = null): ?string {
        $localPath = self::getLocalDataPath();

        // Prüfe ob Daten vorhanden oder herunterladbar
        if (self::hasTrainedData($localPath)) {
            // Prüfe ob die benötigten Sprachen vorhanden sind
            if ($language === null || self::hasLanguage($localPath, $language)) {
                return $localPath;
            }

            // Versuche fehlende Sprachen herunterzuladen
            if (self::ensureTrainedData($localPath, $language)) {
                return $localPath;
            }
        } else {
            // Keine Daten vorhanden, versuche herunterzuladen
            if (self::ensureTrainedData($localPath, $language)) {
                return $localPath;
            }
        }

        // Fallback: System-Tesseract verwenden
        self::logDebug("Verwende System-Tesseract-Daten");
        return null;
    }
}
