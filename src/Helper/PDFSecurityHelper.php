<?php
/*
 * Created on   : Fri Oct 09 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFSecurityHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;
use PDFToolkit\Enums\PdfDecryptResult;

/**
 * Passwortschutz einer PDF: prüfen, setzen (AES-256 mit Rechten), entfernen.
 *
 * Läuft über das MuPDF-Skript data/mupdf/pdf-protect.js (mutool run; MuPDF
 * 1.17, 1.21 und 1.25). Passwörter gehen nie über die Befehlszeile - die
 * stünde in der Prozessliste und im Protokoll - sondern über Dateien mit
 * Rechten 0600 in einem eigenen Ordner, der nach dem Aufruf gelöscht wird.
 */
final class PDFSecurityHelper {
    use ErrorLog;

    /** Rechte, die der Besitzer dem Leser einräumt */
    public const PERMIT_PRINT = 1;
    public const PERMIT_COPY = 2;
    public const PERMIT_MODIFY = 4;
    public const PERMIT_ANNOTATE = 8;
    public const PERMIT_ALL = self::PERMIT_PRINT | self::PERMIT_COPY | self::PERMIT_MODIFY | self::PERMIT_ANNOTATE;

    /** AES-256 nimmt höchstens 127 Bytes (PDF 32000-2, 7.6.4.3.3) */
    public const MAX_PASSWORD_BYTES = 127;

    /**
     * Prüft die Verschlüsselung einer PDF.
     *
     * @return array{encrypted: bool, needsPassword: bool}|null null, wenn die Datei nicht lesbar ist
     */
    public static function probe(string $path): ?array {
        if (!File::exists($path)) {
            self::logError('PDF-Datei nicht gefunden', ['path' => $path]);
            return null;
        }

        $output = self::runScript('probe', ['[INPUT]' => $path]);
        if ($output === null) {
            return null;
        }

        $line = trim((string) end($output));
        if (preg_match('/encrypted=(yes|no) needsPassword=(yes|no)/', $line, $m) !== 1) {
            self::logError('Unerwartete Antwort der Prüfung', ['output' => implode("\n", $output)]);
            return null;
        }

        return ['encrypted' => $m[1] === 'yes', 'needsPassword' => $m[2] === 'yes'];
    }

    /**
     * Trägt die Datei eine Verschlüsselung (Benutzer- oder nur Besitzerpasswort)?
     */
    public static function isEncrypted(string $path): bool {
        return (bool) (self::probe($path)['encrypted'] ?? false);
    }

    /**
     * Lässt sich die Datei ohne Passwort nicht öffnen?
     */
    public static function needsUserPassword(string $path): bool {
        return (bool) (self::probe($path)['needsPassword'] ?? false);
    }

    /**
     * Ob ein Passwort als Benutzer- oder Besitzerpasswort taugt: nicht leer,
     * höchstens 127 Bytes, ohne Steuerzeichen und ohne Komma (MuPDF trennt
     * seine Speicheroptionen am Komma).
     */
    public static function isValidPassword(string $password): bool {
        return $password !== ''
            && strlen($password) <= self::MAX_PASSWORD_BYTES
            && !str_contains($password, ',')
            && preg_match('/[\x00-\x1F\x7F]/', $password) !== 1;
    }

    /**
     * Setzt einen Passwortschutz (AES-256).
     *
     * @param string $userPassword Passwort zum Öffnen; leer = Öffnen ohne Passwort, nur Rechte beschränkt
     * @param string|null $ownerPassword Passwort des Besitzers; null = zufällig, damit das Benutzerpasswort
     *                                   die Rechte nicht aufhebt
     * @param int $permissions Rechte des Lesers, Kombination der PERMIT_*-Konstanten
     */
    public static function encrypt(string $inputPath, string $outputPath, string $userPassword, ?string $ownerPassword = null, int $permissions = self::PERMIT_ALL): bool {
        if (!PDFHelper::isValidPdf($inputPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $inputPath]);
            return false;
        }
        if ($userPassword !== '' && !self::isValidPassword($userPassword)) {
            self::logError('Benutzerpasswort ist ungültig (zu lang, Komma oder Steuerzeichen)');
            return false;
        }
        if ($userPassword === '' && $ownerPassword === null) {
            // Nichts zu schützen und niemand, der die Rechte je ändern könnte
            self::logError('Ohne Benutzerpasswort ist ein Besitzerpasswort nötig');
            return false;
        }
        $ownerPassword ??= bin2hex(random_bytes(16));
        if (!self::isValidPassword($ownerPassword)) {
            self::logError('Besitzerpasswort ist ungültig (leer, zu lang, Komma oder Steuerzeichen)');
            return false;
        }

        $secrets = self::secretDir();
        if ($secrets === null) {
            return false;
        }

        try {
            $output = self::runScript('encrypt', [
                '[INPUT]' => $inputPath,
                '[OUTPUT]' => $outputPath,
                '[USER-PASSWORD-FILE]' => self::secretFile($secrets, $userPassword),
                '[OWNER-PASSWORD-FILE]' => self::secretFile($secrets, $ownerPassword),
                '[PERMISSIONS]' => (string) self::permissionValue($permissions),
            ]);
        } finally {
            Folder::delete($secrets, true);
        }

        if ($output === null || !File::exists($outputPath)) {
            return false;
        }

        self::logInfo('PDF mit Passwort geschützt', ['input' => $inputPath, 'permissions' => $permissions]);

        return true;
    }

    /**
     * Entfernt den Passwortschutz. Ohne Benutzerpasswort (nur Rechteschutz)
     * genügt ein leeres Passwort.
     */
    public static function decrypt(string $inputPath, string $outputPath, string $password = ''): PdfDecryptResult {
        if (!File::exists($inputPath)) {
            self::logError('PDF-Datei nicht gefunden', ['path' => $inputPath]);
            return PdfDecryptResult::Failed;
        }
        if ($password !== '' && !self::isValidPassword($password)) {
            self::logError('Passwort ist ungültig (zu lang, Komma oder Steuerzeichen)');
            return PdfDecryptResult::WrongPassword;
        }

        $secrets = self::secretDir();
        if ($secrets === null) {
            return PdfDecryptResult::Failed;
        }

        try {
            $output = self::runScript('decrypt', [
                '[INPUT]' => $inputPath,
                '[OUTPUT]' => $outputPath,
                '[USER-PASSWORD-FILE]' => self::secretFile($secrets, $password),
            ], [2]);
        } finally {
            Folder::delete($secrets, true);
        }

        if ($output === null) {
            return PdfDecryptResult::Failed;
        }
        if (in_array('FALSCHES_PASSWORT', array_map('trim', $output), true)) {
            self::logInfo('Passwort passt nicht', ['input' => $inputPath]);
            return PdfDecryptResult::WrongPassword;
        }
        if (!File::exists($outputPath)) {
            self::logError('Entschlüsselte PDF wurde nicht erstellt', ['path' => $outputPath]);
            return PdfDecryptResult::Failed;
        }

        self::logInfo('Passwortschutz entfernt', ['input' => $inputPath]);

        return PdfDecryptResult::Ok;
    }

    /**
     * Wert des /P-Eintrags (PDF 32000-1, 7.6.3.2) für eine Kombination der
     * PERMIT_*-Konstanten: Bits 1-2 bleiben 0, nicht eingeräumte Rechte
     * werden aus "alles erlaubt" (-4) genommen.
     */
    public static function permissionValue(int $permissions): int {
        $value = -4;
        if (($permissions & self::PERMIT_PRINT) === 0) {
            $value &= ~(4 | 2048);          // Drucken, Drucken in hoher Auflösung
        }
        if (($permissions & self::PERMIT_COPY) === 0) {
            $value &= ~(16 | 512);          // Inhalt kopieren, Zugang für Barrierefreiheit
        }
        if (($permissions & self::PERMIT_MODIFY) === 0) {
            $value &= ~(8 | 256 | 1024);    // Ändern, Formulare ausfüllen, Zusammenstellen
        }
        if (($permissions & self::PERMIT_ANNOTATE) === 0) {
            $value &= ~32;                  // Kommentieren
        }

        return $value;
    }

    /**
     * Prüft ob der Passwortschutz verfügbar ist (mutool konfiguriert).
     */
    public static function isAvailable(): bool {
        return Config::getInstance()->isExecutableAvailable('mutool-pdf-protect');
    }

    /**
     * Eigener Ordner (0700) für die Passwortdateien eines Aufrufs.
     */
    private static function secretDir(): ?string {
        $dir = sys_get_temp_dir() . '/pdfprotect_' . bin2hex(random_bytes(8));
        try {
            Folder::create($dir, 0700);
        } catch (\Throwable $e) {
            self::logError('Konnte Ordner für Passwortdateien nicht anlegen: ' . $e->getMessage());
            return null;
        }

        return $dir;
    }

    /**
     * Passwort in eine Datei mit Rechten 0600; das Skript entfernt genau den
     * abschließenden Zeilenumbruch wieder.
     */
    private static function secretFile(string $dir, string $password): string {
        return File::createTemp($password . "\n", 'pw', null, 0600, $dir);
    }

    /**
     * Ruft pdf-protect.js auf und liefert die Ausgabezeilen; null bei Fehler.
     *
     * @param array<string, string> $replacements
     * @param list<int> $acceptedCodes Rückgabewerte neben 0, die eine Antwort tragen (2 = falsches Passwort)
     * @return list<string>|null
     */
    private static function runScript(string $mode, array $replacements, array $acceptedCodes = []): ?array {
        $config = Config::getInstance();
        if (!$config->isExecutableAvailable('mutool-pdf-protect')) {
            self::logError('mutool (mutool-pdf-protect) ist nicht konfiguriert oder nicht verfügbar');
            return null;
        }

        $command = $config->buildCommand('mutool-pdf-protect', [
            '[SCRIPT]' => PDFPageHelper::scriptPath('pdf-protect.js'),
            '[LIB]' => PDFPageHelper::libraryPath(),
            '[MODE]' => $mode,
        ] + $replacements);
        if ($command === null) {
            self::logError('Konnte mutool-pdf-protect Befehl nicht erstellen');
            return null;
        }

        // executeShellCommand meldet jeden Rückgabewert ungleich 0 als
        // Fehlschlag; ein erwarteter Wert (2 = falsches Passwort) zählt hier
        // als Antwort
        $output = [];
        $returnCode = 0;
        Shell::executeShellCommand($command . ' 2>&1', $output, $returnCode);
        if ($returnCode !== 0 && !in_array($returnCode, $acceptedCodes, true)) {
            self::logError('pdf-protect.js fehlgeschlagen', [
                'mode' => $mode,
                'returnCode' => $returnCode,
                'output' => implode("\n", $output),
            ]);
            return null;
        }

        return $output;
    }
}
