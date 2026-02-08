<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Config.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Config;

use CommonToolkit\Entities\Executables\JavaExecutable;
use CommonToolkit\Entities\Executables\ShellExecutable;
use CommonToolkit\Helper\FileSystem\Folder;
use ConfigToolkit\CommandBuilder;
use ConfigToolkit\Contracts\Abstracts\ConfigAbstract;
use ERRORToolkit\Enums\LogType;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Konfigurationsklasse für das PDF-Toolkit.
 * 
 * Erweitert die abstrakte Konfiguration und stellt
 * typisierte Executables für PDF-Operationen bereit.
 */
class Config extends ConfigAbstract {
    /**
     * Konstruktor mit optionalem Logger.
     */
    protected function __construct(?string $configDir = null, ?LoggerInterface $logger = null) {
        if ($logger !== null) {
            self::setLogger($logger);
        }

        $configDir = $configDir ?? static::getDefaultConfigDir();

        if (!Folder::exists($configDir)) {
            self::logError("Invalid config directory: $configDir");
            throw new InvalidArgumentException("Invalid config directory: $configDir");
        }

        parent::__construct($configDir);
    }

    protected static function getDefaultConfigDir(): string {
        return __DIR__ . '/../../config';
    }

    protected static function getProjectName(): string {
        return 'php-pdf-toolkit';
    }

    /**
     * Gibt die Singleton-Instanz zurück.
     * 
     * @param string|null $configDir Optionales Konfigurationsverzeichnis
     * @param LoggerInterface|null $logger Optionaler Logger
     * @return static
     */
    public static function getInstance(?string $configDir = null, ?LoggerInterface $logger = null): static {
        if (static::$instance === null) {
            static::$instance = new static($configDir, $logger);
        }
        return static::$instance;
    }

    /**
     * Alias für resetInstance() zur Rückwärtskompatibilität.
     */
    public static function reset(): void {
        static::resetInstance();
    }

    public function reload(?string $configDir = null): void {
        if (!empty($configDir) && Folder::exists($configDir)) {
            $this->configLoader->loadConfigFiles(glob($configDir . '/*.json'), true);
        }
        $this->configLoader->reload();
        $this->commandBuilder = new CommandBuilder($this->configLoader);
    }

    // ----------------------------------------------------------
    //          Allgemeine Methoden für Config-Zugriff
    // ----------------------------------------------------------

    /**
     * Statische Methode um ein Executable zu holen.
     * 
     * @param string $name Name des Executables
     * @return array|null Die Executable-Konfiguration oder null
     */
    public static function getExecutable(string $name): ?array {
        $instance = self::getInstance();
        $raw = $instance->configLoader->get('shellExecutables', null, []);
        return $raw[$name] ?? null;
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
     * Holt den Pfad eines Shell-Executables mit Fallback.
     * 
     * @param string $name Name des Executables
     * @param string|null $fallback Fallback-Pfad wenn nicht konfiguriert
     * @return string Der konfigurierte Pfad oder Fallback
     */
    public function getExecutablePathWithFallback(string $name, ?string $fallback = null): string {
        // Versuche den Pfad über die Parent-Methode
        $path = parent::getExecutablePath($name);
        if ($path !== null) {
            return $path;
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
        $raw = $this->configLoader->get('javaExecutables', null, []);
        if (isset($raw[$name]) && is_array($raw[$name]) && isset($raw[$name]['path'])) {
            return $raw[$name]['path'];
        }
        return $fallback ?? '';
    }

    /**
     * Hilfsmethode um Executable-Instanzen zu laden.
     */
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
    //          Projekt-spezifische Logging-Methoden
    // ----------------------------------------------------------

    /**
     * Gibt den Log-Typ als Enum zurück.
     */
    public function getLogTypeEnum(): LogType {
        return LogType::fromString(parent::getLogType());
    }

    protected static function getComposerFilePath(): string {
        return __DIR__ . '/../../composer.json';
    }

    protected static function getVersionFilePath(): string {
        return __DIR__ . '/../../VERSION';
    }
}
