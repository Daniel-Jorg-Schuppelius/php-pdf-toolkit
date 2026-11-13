<?php
/*
 * Created on   : Fri Nov 13 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ConfinedTool.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\{Platform, Shell};
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;
use Throwable;

/**
 * Aufruf von Werkzeugen, die AppArmor auf /tmp und /var/tmp beschraenkt.
 *
 * Ubuntu bringt seit 25.10 ein Profil fuer /usr/bin/gs mit (AppArmor
 * profiles/apparmor.d/gs, enforce): Lesen und Schreiben nur unter /tmp und
 * /var/tmp (abstractions/user-tmp, eigene Dateien) und fuer Dateien mit
 * PostScript-/PDF-Endung unter /home, /mnt und /media. Alles andere wird
 * verweigert - auch das TMPDIR, das ISPConfig je Site setzt
 * (/var/www/clients/<client>/<web>/tmp), und die Ablage des Webservice unter
 * /var/www. Ghostscript bricht dann sofort ab: "Could not open temporary
 * file ... Unable to open the initial device" (Zuschnitt 13.11.2026, gs
 * 10.06.0). ocrmypdf ist selbst nicht eingesperrt, ruft aber gs mit Dateien
 * aus seinem TMPDIR auf; seine Eingabe verlinkt es dort nur (origin.pdf),
 * und AppArmor prueft den aufgeloesten Pfad.
 *
 * Startet ein Kommando eines dieser Programme, bekommt der Aufruf deshalb ein
 * eigenes Verzeichnis unter /tmp bzw. /var/tmp: TMPDIR zeigt dorthin,
 * Eingaben ausserhalb werden hineinkopiert, Ausgaben dort geschrieben und
 * danach an ihr Ziel verschoben. Alle anderen Kommandos laufen unveraendert.
 */
final class ConfinedTool {
    use ErrorLog;

    /** Programme, deren Dateien unter {@see ALLOWED_ROOTS} liegen muessen */
    public const PROGRAMS = ['gs', 'ocrmypdf'];

    /** Wurzeln aus abstractions/user-tmp */
    public const ALLOWED_ROOTS = ['/tmp', '/var/tmp'];

    private const DIRECTORY_PREFIX = 'pdftoolkit_confined_';

    /**
     * @param array<string, string> $replacements Platzhalter fuer den Aufruf
     * @param array<string, string> $moves Ausgabe im Aufrufverzeichnis => Ziel
     */
    private function __construct(
        private readonly ?string $directory,
        private readonly array $replacements,
        private readonly array $moves,
    ) {}

    /**
     * Baut das Kommando aus executables.json und fuehrt es aus.
     *
     * @param array<string, string> $replacements Platzhalter wie bei Config::buildCommand()
     * @param list<string> $output Ausgabe des Kommandos
     * @param list<string> $inputs Platzhalter, deren Pfade das Werkzeug liest
     * @param list<string> $outputs Platzhalter, deren Pfade das Werkzeug schreibt
     * @param array<string, string> $environment Zusaetzliche Umgebungsvariablen
     * @param bool $mergeStderr Fehlerausgabe in die Ausgabe umleiten (2>&1)
     * @return bool|null Ergebnis von Shell::executeShellCommand(); null, wenn
     *                   sich das Kommando nicht bauen liess
     */
    public static function execute(
        string $executable,
        array $replacements,
        array &$output,
        int &$returnCode,
        ?float $timeout = null,
        array $inputs = ['[INPUT]'],
        array $outputs = ['[OUTPUT]'],
        array $environment = [],
        bool $mergeStderr = false,
    ): ?bool {
        $config = Config::getInstance();
        $run = self::isConfined($config->getExecutablePath($executable))
            ? self::open($replacements, $inputs, $outputs)
            : new self(null, $replacements, []);

        try {
            $command = $config->buildCommand($executable, $run->replacements());
            if ($command === null) {
                return null;
            }

            $ok = Shell::executeShellCommand(
                $run->environmentPrefix($environment) . $command . ($mergeStderr ? ' 2>&1' : ''),
                $output,
                $returnCode,
                timeout: $timeout,
            );
            $run->commit();

            return $ok;
        } finally {
            $run->close();
        }
    }

    /**
     * Ob ein Programm (Pfad oder Name) zu {@see PROGRAMS} gehoert.
     */
    public static function isConfined(?string $program): bool {
        return $program !== null && $program !== '' && in_array(basename($program), self::PROGRAMS, true);
    }

    /**
     * Legt das Aufrufverzeichnis an und setzt Eingaben und Ausgaben um.
     * Ohne beschreibbare Wurzel laeuft der Aufruf wie bisher.
     *
     * @param array<string, string> $replacements
     * @param list<string> $inputs
     * @param list<string> $outputs
     */
    public static function open(array $replacements, array $inputs = ['[INPUT]'], array $outputs = ['[OUTPUT]'], ?string $root = null): self {
        $root ??= self::root();
        if ($root === null) {
            self::logWarning('Kein Verzeichnis unter /tmp oder /var/tmp beschreibbar - Ghostscript kann an AppArmor scheitern');

            return new self(null, $replacements, []);
        }

        $directory = $root . '/' . self::DIRECTORY_PREFIX . bin2hex(random_bytes(8));
        try {
            Folder::create($directory, 0700);
        } catch (Throwable $e) {
            self::logWarning('Aufrufverzeichnis nicht anlegbar, Ghostscript kann an AppArmor scheitern: ' . $e->getMessage());

            return new self(null, $replacements, []);
        }

        $moves = [];
        try {
            foreach ($inputs as $index => $placeholder) {
                $path = $replacements[$placeholder] ?? '';
                if ($path === '' || !File::exists($path) || self::isInside(File::getRealPath($path))) {
                    continue;
                }
                $copy = $directory . '/in' . $index . self::extension($path);
                File::copy($path, $copy);
                $replacements[$placeholder] = $copy;
            }

            foreach ($outputs as $index => $placeholder) {
                $path = $replacements[$placeholder] ?? '';
                if ($path === '' || self::isInside(Folder::getRealPath(dirname($path)))) {
                    continue;
                }
                $local = $directory . '/out' . $index . self::extension($path);
                $replacements[$placeholder] = $local;
                $moves[$local] = $path;
            }
        } catch (Throwable $e) {
            Folder::delete($directory, true);
            throw $e;
        }

        return new self($directory, $replacements, $moves);
    }

    /**
     * Wurzel fuer das Aufrufverzeichnis: das Temp-Verzeichnis, wenn es schon
     * unter /tmp oder /var/tmp liegt, sonst die erste beschreibbare dieser
     * Wurzeln; null, wenn keine beschreibbar ist (open_basedir).
     */
    public static function root(?string $systemTemp = null): ?string {
        $systemTemp = rtrim($systemTemp ?? Platform::getTempDirectory(), '/');
        $resolved = Folder::exists($systemTemp) ? Folder::getRealPath($systemTemp) : $systemTemp;
        if (self::isInside($resolved)) {
            return $resolved;
        }
        foreach (self::ALLOWED_ROOTS as $root) {
            if (Folder::exists($root) && Folder::isWritable($root)) {
                return $root;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> Platzhalter fuer den Aufruf
     */
    public function replacements(): array {
        return $this->replacements;
    }

    /**
     * Umgebungsvariablen vor dem Kommando; TMPDIR zeigt in das Aufrufverzeichnis.
     *
     * @param array<string, string> $environment
     */
    public function environmentPrefix(array $environment = []): string {
        if ($this->directory !== null) {
            $environment['TMPDIR'] = $this->directory;
        }

        $prefix = '';
        foreach ($environment as $name => $value) {
            $prefix .= $name . '=' . escapeshellarg($value) . ' ';
        }

        return $prefix;
    }

    /**
     * Verschiebt die geschriebenen Ausgaben an ihr Ziel. Auch nach einem
     * gescheiterten Lauf: Die Aufrufer pruefen und raeumen ihre Ausgabe selbst.
     */
    public function commit(): void {
        foreach ($this->moves as $local => $target) {
            if (!File::exists($local)) {
                continue;
            }
            if (File::exists($target)) {
                File::delete($target);
            }
            File::move($local, dirname($target), basename($target));
        }
    }

    /**
     * Entfernt das Aufrufverzeichnis samt Kopien und Werkzeugresten.
     */
    public function close(): void {
        if ($this->directory !== null && Folder::exists($this->directory)) {
            Folder::delete($this->directory, true);
        }
    }

    public function directory(): ?string {
        return $this->directory;
    }

    private static function isInside(string $path): bool {
        foreach (self::ALLOWED_ROOTS as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function extension(string $path): string {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension === '' ? '' : '.' . $extension;
    }
}
