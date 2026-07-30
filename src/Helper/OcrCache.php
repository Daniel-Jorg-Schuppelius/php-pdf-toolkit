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

use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;

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
 * - `ocr_cache_ttl`  int    Lebensdauer in Sekunden (Standard: 30 Tage, 0 = unbegrenzt)
 */
final class OcrCache {
    use ErrorLog;

    private const DEFAULT_TTL = 2592000; // 30 Tage

    private static ?bool $enabled = null;
    private static ?string $directory = null;
    private static ?int $ttl = null;

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
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $text) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            self::logDebug("OCR-Cache nicht schreibbar: {$file}");
        }
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

        $configured = (string) (Config::getInstance()->getConfig('PDFSettings', 'ocr_cache_dir') ?? '');
        $dir = $configured !== '' ? rtrim($configured, '/') : sys_get_temp_dir() . '/pdftoolkit_ocr';

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            self::logWarning("OCR-Cache-Verzeichnis nicht anlegbar, Cache deaktiviert: {$dir}");
            self::$directory = '';
            return null;
        }

        self::$directory = $dir;
        return $dir;
    }
}
