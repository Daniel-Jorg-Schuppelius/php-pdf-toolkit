<?php
/*
 * Created on   : Thu Jul 30 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OcrCache.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;
use Throwable;

/**
 * Prozessübergreifender Cache für OCR-Ergebnisse.
 *
 * OCR ist der mit Abstand teuerste Schritt der Textgewinnung (Sekunden bis
 * Minuten je Dokument), während der In-Memory-Cache des PDFTextProvider mit
 * dem Prozess endet. Wiederholte Läufe über dieselben Dateien — Testsuiten,
 * Cron-Jobs, erneute Konvertierungen desselben Uploads — zahlen ihn deshalb
 * jedes Mal neu.
 *
 * Der Schlüssel bindet den Dateiinhalt (SHA-1) und alle Parameter ein, die das
 * Ergebnis bestimmen. Eine geänderte Datei erzeugt damit automatisch einen
 * neuen Eintrag; alte Einträge laufen über die TTL aus.
 *
 * Konfiguration (Abschnitt `PDFSettings`):
 * - `ocr_cache`      bool   Cache aktiv (Standard: true)
 * - `ocr_cache_dir`  string Verzeichnis (Standard: <temp>/pdftoolkit_ocr)
 * - `ocr_cache_ttl`  int    Lebensdauer in Sekunden (Standard: 24 Stunden, 0 = unbegrenzt)
 *
 * Der Cache haelt Text aus Kundendokumenten. Deshalb ist die Vorgabe 24 Stunden
 * (so lange leben auch die Jobdateien des Dienstes), und abgelaufene Eintraege
 * werden beim Schreiben weggeraeumt - nicht erst, wenn dieselbe Datei noch
 * einmal gelesen wird; ein einmal konvertiertes Dokument bliebe sonst fuer
 * immer liegen (Entscheidung 14.10.2026). Werkzeuge, die laenger cachen sollen
 * (Katalog-Gate), stellen das mit {@see configure()} fuer sich ein.
 */
final class OcrCache {
    use ErrorLog;

    private const DEFAULT_TTL = 86400; // 24 Stunden

    /** Hoechstens so oft (Sekunden) raeumt ein Prozess beim Schreiben auf. */
    private const PRUNE_INTERVAL = 3600;

    /** Name des Standardverzeichnisses im Temp-Ordner */
    private const DEFAULT_DIRECTORY = 'pdftoolkit_ocr';

    private static ?bool $enabled = null;
    private static ?string $directory = null;
    private static ?int $ttl = null;
    private static ?int $lastPrune = null;
    /** @var string|null Verzeichnis aus configure(), vorrangig vor der Konfiguration */
    private static ?string $configuredDirectory = null;

    /**
     * Setzt Einstellungen fuer diesen Prozess, vorrangig vor der Konfiguration;
     * null laesst den jeweiligen Wert unberuehrt.
     *
     * @param int|null $ttl Lebensdauer in Sekunden, 0 = unbegrenzt
     * @param string|null $directory eigenes Verzeichnis (wird mit 0700 angelegt)
     * @throws \InvalidArgumentException bei negativer Lebensdauer
     */
    public static function configure(?bool $enabled = null, ?int $ttl = null, ?string $directory = null): void {
        if ($ttl !== null && $ttl < 0) {
            throw new \InvalidArgumentException('Die Lebensdauer des OCR-Caches darf nicht negativ sein.');
        }
        if ($enabled !== null) {
            self::$enabled = $enabled;
        }
        if ($ttl !== null) {
            self::$ttl = $ttl;
        }
        if ($directory !== null) {
            self::$directory = null;
            self::$configuredDirectory = rtrim($directory, '/');
        }
        self::$lastPrune = null;
    }

    /**
     * Liefert den gecachten Text oder null (kein Treffer/deaktiviert).
     *
     * @param array<string, scalar|null> $parameters Alles, was das OCR-Ergebnis beeinflusst
     */
    public static function get(string $pdfPath, string $variant, array $parameters = []): ?string {
        if (!self::enabled()) {
            return null;
        }

        $file = self::path($pdfPath, $variant, $parameters);
        if ($file === null || !is_file($file)) {
            return null;
        }

        $ttl = self::ttl();
        if ($ttl > 0 && (time() - (int) @filemtime($file)) > $ttl) {
            @unlink($file);
            return null;
        }

        $text = @file_get_contents($file);
        if ($text === false) {
            return null;
        }

        self::logDebug("OCR-Cache-Treffer für {$variant}: {$pdfPath}");
        return $text;
    }

    /**
     * Legt ein Ergebnis ab. Leere Texte werden nicht gespeichert — sie sind
     * meist Symptom eines fehlgeschlagenen Laufs und würden ihn zementieren.
     *
     * @param array<string, scalar|null> $parameters
     */
    public static function put(string $pdfPath, string $variant, string $text, array $parameters = []): void {
        if (!self::enabled() || trim($text) === '') {
            return;
        }

        $file = self::path($pdfPath, $variant, $parameters);
        if ($file === null) {
            return;
        }

        // Atomar schreiben: parallele Prozesse dürfen sich nicht halbe Dateien lesen.
        // 0600 vor dem ersten Byte - der Text ist Dokumentinhalt.
        try {
            File::write($file, $text, 0600, atomic: true);
        } catch (Throwable) {
            self::logDebug("OCR-Cache nicht schreibbar: {$file}");
        }

        if (self::$lastPrune === null || time() - self::$lastPrune >= self::PRUNE_INTERVAL) {
            self::$lastPrune = time();
            self::prune();
        }
    }

    /**
     * Loescht alle Eintraege, die aelter als die Lebensdauer sind.
     *
     * @return int Anzahl geloeschter Eintraege
     */
    public static function prune(): int {
        $ttl = self::ttl();
        $dir = self::directory();
        if ($ttl === 0 || $dir === null || !is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        $limit = time() - $ttl;
        foreach (glob($dir . '/*.txt') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $limit && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Löscht alle Einträge (Wartung/Tests).
     */
    public static function clear(): int {
        $dir = self::directory();
        if ($dir === null || !is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        foreach (glob($dir . '/*.txt') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Setzt die zwischengespeicherte Konfiguration zurück (Tests).
     */
    public static function reset(): void {
        self::$enabled = null;
        self::$directory = null;
        self::$ttl = null;
        self::$lastPrune = null;
        self::$configuredDirectory = null;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private static function path(string $pdfPath, string $variant, array $parameters): ?string {
        $dir = self::directory();
        if ($dir === null) {
            return null;
        }

        $hash = @sha1_file($pdfPath);
        if ($hash === false) {
            return null;
        }

        ksort($parameters);
        $key = sha1($hash . '|' . $variant . '|' . serialize($parameters));

        return $dir . '/' . $key . '.txt';
    }

    private static function enabled(): bool {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        $configured = Config::getInstance()->getConfig('PDFSettings', 'ocr_cache');
        self::$enabled = $configured === null ? true : (bool) $configured;

        return self::$enabled;
    }

    private static function ttl(): int {
        if (self::$ttl !== null) {
            return self::$ttl;
        }

        $configured = Config::getInstance()->getConfig('PDFSettings', 'ocr_cache_ttl');
        self::$ttl = $configured === null ? self::DEFAULT_TTL : max(0, (int) $configured);

        return self::$ttl;
    }

    private static function directory(): ?string {
        if (self::$directory !== null) {
            return self::$directory === '' ? null : self::$directory;
        }

        $configured = self::$configuredDirectory ?? (string) (Config::getInstance()->getConfig('PDFSettings', 'ocr_cache_dir') ?? '');
        $dir = $configured !== '' ? rtrim($configured, '/') : sys_get_temp_dir() . '/' . self::DEFAULT_DIRECTORY;

        try {
            if (!Folder::exists($dir)) {
                // Nur für den eigenen Benutzer: der Cache hält Dokumenttext
                Folder::create($dir, 0700, true);
            }
        } catch (Throwable) {
            // Paralleler Prozess war schneller oder Ort nicht beschreibbar - unten geprüft
        }
        if (!Folder::exists($dir)) {
            self::logWarning("OCR-Cache-Verzeichnis nicht anlegbar, Cache deaktiviert: {$dir}");
            self::$directory = '';
            return null;
        }

        // Der Standardort liegt im gemeinsamen Temp-Ordner und hat einen festen
        // Namen: legt ihn ein anderer Benutzer an, könnte er mitlesen oder
        // Ergebnisse unterschieben, die ungeprüft als OCR-Text zurückkommen
        // (Scan 13.10.2026 S4-03). Ein konfigurierter Ort ist Sache des Betriebs.
        if ($configured === '' && !self::isPrivateDirectory($dir)) {
            self::logWarning("OCR-Cache-Verzeichnis gehört nicht allein diesem Benutzer, Cache deaktiviert: {$dir}");
            self::$directory = '';
            return null;
        }

        self::$directory = $dir;
        return $dir;
    }

    /**
     * Eigenes Verzeichnis ohne Rechte für Gruppe und andere; ein eigenes, zu
     * offenes (ältere Fassungen legten 0775 an) wird auf 0700 gesetzt.
     */
    private static function isPrivateDirectory(string $dir): bool {
        if (function_exists('posix_geteuid') && Folder::owner($dir, false) !== posix_geteuid()) {
            return false;
        }
        if ((Folder::permissions($dir, false) & 0077) === 0) {
            return true;
        }
        if (!@chmod($dir, 0700)) {
            return false;
        }
        clearstatcache(true, $dir);

        return (Folder::permissions($dir, false) & 0077) === 0;
    }
}
