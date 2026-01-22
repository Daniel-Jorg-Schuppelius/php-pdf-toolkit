<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Config.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Config;

use CommonToolkit\Entities\Executables\JavaExecutable;
use CommonToolkit\Entities\Executables\ShellExecutable;
use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\FileSystem\FileTypes\JsonFile;
use CommonToolkit\Helper\FileSystem\Folder;
use Composer\InstalledVersions;
use ConfigToolkit\ConfigLoader;
use ERRORToolkit\Enums\LogType;
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Konfigurationsklasse für das PDF-Toolkit.
 * 
 * Lädt die Konfiguration aus dem config-Verzeichnis und stellt
 * typisierte Executables für PDF-Operationen bereit.
 */
class Config {
    use ErrorLog;

    private const COMPOSER_FILE = __DIR__ . '/../../composer.json';
    private const VERSION_FILE = __DIR__ . '/../../VERSION';

    private static ?Config $instance = null;
    private ConfigLoader $configLoader;
    private ?bool $debugOverride = null;

    private function __construct(string $configDir, ?LoggerInterface $logger = null) {
        self::setLogger($logger);

        if (!Folder::exists($configDir)) {
            self::logError("Invalid config directory: $configDir");
            throw new InvalidArgumentException("Invalid config directory: $configDir");
        }

        $this->configLoader = ConfigLoader::getInstance($logger);
        $this->configLoader->loadConfigFiles(glob($configDir . '/*.json'), true);
    }

    public static function getInstance(string $configDir = __DIR__ . "/../../config", ?LoggerInterface $logger = null): Config {
        if (self::$instance === null) {
            self::$instance = new self($configDir, $logger);
        }
        return self::$instance;
    }

    /**
     * Setzt die Singleton-Instanz zurück (nützlich für Tests).
     */
    public static function reset(): void {
        self::$instance = null;
    }

    public function reload(?string $configDir = null): void {
        if (!empty($configDir) && Folder::exists($configDir)) {
            $this->configLoader->loadConfigFiles(glob($configDir . '/*.json'), true);
        }
        $this->configLoader->reload();
    }

    // ----------------------------------------------------------
    //          Allgemeine Methoden für Config-Zugriff
    // ----------------------------------------------------------

    /**
     * Holt einen Konfigurationswert.
     * 
     * @param string $section Config-Sektion (z.B. 'PDFSettings')
     * @param string|null $key Optionaler Key innerhalb der Sektion
     * @param mixed $default Default-Wert falls nicht gefunden
     */
    public function getConfig(string $section, ?string $key = null, mixed $default = null): mixed {
        return $this->configLoader->get($section, $key, $default);
    }

    public function getSection(string $section): mixed {
        return $this->configLoader->get($section, null, []);
    }

    // ----------------------------------------------------------
    //          Typisierte Executables
    // ----------------------------------------------------------

    /**
     * Lädt alle Java-Executables aus der Konfiguration.
     * 
     * @return array<string, JavaExecutable>
     */
    public function getJavaExecutables(): array {
        return $this->loadExecutableInstances('javaExecutables', JavaExecutable::class);
    }

    /**
     * Lädt alle Shell-Executables aus der Konfiguration.
     * 
     * @return array<string, ShellExecutable>
     */
    public function getShellExecutables(): array {
        return $this->loadExecutableInstances('shellExecutables', ShellExecutable::class);
    }

    /**
     * Holt ein bestimmtes Shell-Executable.
     */
    public function getShellExecutable(string $name): ?ShellExecutable {
        $executables = $this->getShellExecutables();
        return $executables[$name] ?? null;
    }

    /**
     * Holt ein bestimmtes Java-Executable.
     */
    public function getJavaExecutable(string $name): ?JavaExecutable {
        $executables = $this->getJavaExecutables();
        return $executables[$name] ?? null;
    }

    /**
     * Holt den Pfad eines Shell-Executables.
     * 
     * @param string $name Name des Executables
     * @param string|null $fallback Fallback-Pfad wenn nicht konfiguriert
     * @return string Der konfigurierte Pfad oder Fallback
     */
    public function getExecutablePath(string $name, ?string $fallback = null): string {
        // Versuche den Pfad direkt aus der Konfiguration zu holen
        $raw = $this->configLoader->get('shellExecutables', null, []);
        if (isset($raw[$name]) && is_array($raw[$name]) && isset($raw[$name]['path'])) {
            return $raw[$name]['path'];
        }

        // Fallback: Prüfe ob Tool im PATH verfügbar ist
        if ($fallback === null) {
            exec("which $name 2>/dev/null", $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0])) {
                return $output[0];
            }
            return $name; // Nutze den Namen direkt als letzten Ausweg
        }

        return $fallback;
    }

    /**
     * Holt den Pfad eines Java-Executables (JAR).
     */
    public function getJavaExecutablePath(string $name, ?string $fallback = null): string {
        // Versuche den Pfad direkt aus der Konfiguration zu holen
        $raw = $this->configLoader->get('javaExecutables', null, []);
        if (isset($raw[$name]) && is_array($raw[$name]) && isset($raw[$name]['path'])) {
            return $raw[$name]['path'];
        }
        return $fallback ?? '';
    }

    /**
     * Baut einen Shell-Befehl mit ersetzten Platzhaltern.
     * 
     * Die Argumente werden aus der Config geholt und Platzhalter ersetzt.
     * 
     * @param string $name Name des Executables
     * @param array $replacements Platzhalter-Ersetzungen (z.B. ['[INPUT]' => '/path/to/file.pdf'])
     * @param array $extraArgs Zusätzliche Argumente die angehängt werden
     * @return string|null Der vollständige Befehl oder null wenn nicht konfiguriert
     */
    public function buildCommand(string $name, array $replacements = [], array $extraArgs = []): ?string {
        $raw = $this->configLoader->get('shellExecutables', null, []);
        if (!isset($raw[$name]) || !is_array($raw[$name])) {
            return null;
        }

        $config = $raw[$name];
        $path = $config['path'] ?? $name;
        $arguments = $config['arguments'] ?? [];

        // Platzhalter in Argumenten ersetzen
        $resolvedArgs = [];
        foreach ($arguments as $arg) {
            $resolved = $arg;
            foreach ($replacements as $placeholder => $value) {
                $resolved = str_replace($placeholder, $value, $resolved);
            }
            $resolvedArgs[] = escapeshellarg($resolved);
        }

        // Extra-Argumente anhängen
        foreach ($extraArgs as $arg) {
            $resolvedArgs[] = escapeshellarg($arg);
        }

        return escapeshellcmd($path) . ' ' . implode(' ', $resolvedArgs);
    }

    /**
     * Baut einen Java-Befehl (java -jar ...) mit ersetzten Platzhaltern.
     * 
     * @param string $name Name des Java-Executables
     * @param array $replacements Platzhalter-Ersetzungen
     * @param array $extraArgs Zusätzliche Argumente
     * @return string|null Der vollständige Befehl oder null wenn nicht konfiguriert
     */
    public function buildJavaCommand(string $name, array $replacements = [], array $extraArgs = []): ?string {
        $javaPath = $this->getExecutablePath('java');
        $jarPath = $this->getJavaExecutablePath($name);

        if (empty($jarPath)) {
            return null;
        }

        $raw = $this->configLoader->get('javaExecutables', null, []);
        $arguments = $raw[$name]['arguments'] ?? [];

        // Platzhalter in Argumenten ersetzen
        $resolvedArgs = [];
        foreach ($arguments as $arg) {
            $resolved = $arg;
            foreach ($replacements as $placeholder => $value) {
                $resolved = str_replace($placeholder, $value, $resolved);
            }
            $resolvedArgs[] = escapeshellarg($resolved);
        }

        // Extra-Argumente anhängen
        foreach ($extraArgs as $arg) {
            $resolvedArgs[] = escapeshellarg($arg);
        }

        return escapeshellcmd($javaPath) . ' -jar ' . escapeshellarg($jarPath) . ' ' . implode(' ', $resolvedArgs);
    }

    private function loadExecutableInstances(string $section, string $class): array {
        $raw = $this->configLoader->get($section, null, []);
        $result = [];

        foreach ($raw as $key => $config) {
            if ($config instanceof $class) {
                $result[$key] = $config;
            } elseif (is_array($config)) {
                $result[$key] = new $class($config);
            }
        }

        return $result;
    }

    // ----------------------------------------------------------
    //          Strukturierte Werte (Debugging/Logging)
    // ----------------------------------------------------------

    public function getLogType(): LogType {
        return LogType::fromString($this->configLoader->get("Logging", "log", LogType::NULL->value));
    }

    public function getLogLevel(): string {
        return $this->debugOverride ? LogLevel::DEBUG : $this->configLoader->get("Logging", "level", LogLevel::DEBUG);
    }

    public function getLogPath(): ?string {
        return $this->configLoader->get("Logging", "path");
    }

    public function isDebugEnabled(): bool {
        return $this->debugOverride ?? $this->configLoader->get("Debugging", "debug", false);
    }

    public function setDebug(bool $debug): void {
        $this->debugOverride = $debug;
    }

    public function getVersion(): string {
        if (File::exists(self::COMPOSER_FILE)) {
            try {
                $composer = JsonFile::decode(self::COMPOSER_FILE);

                if (isset($composer['name']) && class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($composer['name'])) {
                    return InstalledVersions::getPrettyVersion($composer['name']) ?? 'unknown';
                }
            } catch (\Throwable) {
                // Fallback zu VERSION-Datei
            }
        }

        if (File::exists(self::VERSION_FILE)) {
            return trim(File::read(self::VERSION_FILE));
        }

        return 'unknown';
    }
}
