<?php
/*
 * Created on   : Fri Oct 10 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFAComplianceHelper.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\{Platform, Shell};
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;
use PDFToolkit\Entities\{PdfAComplianceIssue, PdfAComplianceResult};

/**
 * PDF/A-Konformität prüfen (ADR-0034): sagt, ob eine Datei die Regeln der
 * ISO 19005 für eine Stufe einhält, und welche Prüfpunkte sie verletzt.
 *
 * Zwei Prüfer, fest geordnet:
 * - veraPDF (`verapdf`), die Referenzimplementierung der PDF Association.
 *   Braucht Java; kommt über das Install-Skript (Sektion installers).
 * - PdfCraft (`pdfcraft-run`), Rückfall ohne Java. Kennt nur 2b und 3b und
 *   prüft nachweislich weniger Regeln (10.10.2026: eine Datei mit
 *   .notdef-Glyphen galt dort als konform, bei veraPDF nicht). Das Ergebnis
 *   nennt deshalb immer den Prüfer.
 *
 * Geprüft wird gegen die Stufe, die die Datei in ihrer XMP-Kennung erklärt
 * ({@see declaredLevel()}); erklärt sie keine, gegen {@see DEFAULT_LEVEL}, das
 * Ziel unseres PDF/A-Konverters. Wer eine andere Stufe will, gibt sie an.
 *
 * Beide Werkzeuge sind optional. Ohne Prüfer liefert {@see verify()} null -
 * lieber kein Urteil als ein erfundenes.
 */
final class PDFAComplianceHelper {
    use ErrorLog;

    public const ENGINE_VERAPDF = 'verapdf';
    public const ENGINE_PDFCRAFT = 'pdfcraft';

    /** Stufe, gegen die eine Datei ohne eigene Kennung geprüft wird (unser Konverterziel). */
    public const DEFAULT_LEVEL = '2b';

    /** Stufen, die veraPDF als Flavour kennt (ISO 19005-1 bis -4). */
    public const LEVELS = ['1a', '1b', '2a', '2b', '2u', '3a', '3b', '3u', '4', '4e', '4f'];

    private const PDFCRAFT_LEVELS = ['2b', '3b'];
    private const EXECUTABLE_VERAPDF = 'verapdf';
    private const EXECUTABLE_PDFCRAFT = 'pdfcraft-run';
    private const MAX_FAILURES_DISPLAYED = 50;
    private const TIMEOUT_VERAPDF = 300.0;
    private const TIMEOUT_PDFCRAFT = 120.0;
    /** Blockgröße beim Suchen der XMP-Kennung; große Dateien werden nicht am Stück geladen. */
    private const SCAN_CHUNK = 4 * 1024 * 1024;
    private const SCAN_OVERLAP = 512;

    /** @var array<string, string> */
    private static array $versionCache = [];

    /**
     * Der Prüfer, der zum Zuge käme: veraPDF vor PdfCraft; null, wenn keiner
     * installiert ist. Anders als {@see Config::isExecutableAvailable()} wird
     * geprüft, ob das Programm wirklich da ist, nicht nur, ob es konfiguriert
     * ist - beide werden erst vom Install-Skript nachgeladen.
     */
    public static function engine(): ?string {
        if (self::toolAvailable(self::EXECUTABLE_VERAPDF)) {
            return self::ENGINE_VERAPDF;
        }
        if (self::toolAvailable(self::EXECUTABLE_PDFCRAFT)) {
            return self::ENGINE_PDFCRAFT;
        }

        return null;
    }

    public static function isAvailable(): bool {
        return self::engine() !== null;
    }

    /**
     * Kann der (gewählte oder anstehende) Prüfer diese Stufe prüfen?
     */
    public static function supportsLevel(string $level, ?string $engine = null): bool {
        $level = strtolower($level);
        $engine ??= self::engine();

        return match ($engine) {
            self::ENGINE_VERAPDF => in_array($level, self::LEVELS, true),
            self::ENGINE_PDFCRAFT => in_array($level, self::PDFCRAFT_LEVELS, true),
            default => false,
        };
    }

    /**
     * Stufe aus der XMP-Kennung der Datei (pdfaid:part, pdfaid:conformance),
     * z. B. "2b", "3u", "4", "4f"; null, wenn die Datei sich nicht als PDF/A
     * erklärt. Bei mehreren Kennungen (inkrementelle Speicherungen) zählt die
     * letzte. Eine konforme Datei muss den XMP-Strom unkomprimiert ablegen,
     * deshalb genügt die Rohsuche; eine komprimierte Kennung wäre ohnehin keine.
     */
    public static function declaredLevel(string $pdfPath): ?string {
        $handle = @fopen($pdfPath, 'rb');
        if ($handle === false) {
            return null;
        }

        $part = null;
        $conformance = null;
        $tail = '';
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, self::SCAN_CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $window = $tail . $chunk;
                if (preg_match_all('/pdfaid:part\s*(?:=\s*["\']|>)\s*(\d)/', $window, $matches) > 0) {
                    $part = (int) end($matches[1]);
                    $conformance = null;
                }
                if (preg_match_all('/pdfaid:conformance\s*(?:=\s*["\']|>)\s*([A-Za-z])/', $window, $matches) > 0) {
                    $conformance = strtolower((string) end($matches[1]));
                }
                $tail = substr($window, -self::SCAN_OVERLAP);
            }
        } finally {
            fclose($handle);
        }

        if ($part === null) {
            return null;
        }
        $level = $part . ($conformance ?? '');

        return in_array($level, self::LEVELS, true) ? $level : null;
    }

    /**
     * Prüft die Datei gegen $level (Standard: erklärte Stufe, sonst 2b).
     * null bei ungültiger Datei, unbekannter Stufe, fehlendem oder
     * gescheitertem Prüfer - das Protokoll nennt den Grund.
     */
    public static function verify(string $pdfPath, ?string $level = null): ?PdfAComplianceResult {
        if (!PDFHelper::isValidPdf($pdfPath)) {
            self::logError('Ungültige PDF-Datei', ['path' => $pdfPath]);

            return null;
        }

        $declared = self::declaredLevel($pdfPath);
        $level = strtolower($level ?? $declared ?? self::DEFAULT_LEVEL);
        if (!in_array($level, self::LEVELS, true)) {
            self::logError('Unbekannte PDF/A-Stufe', ['level' => $level]);

            return null;
        }

        $engine = self::engine();
        if ($engine === self::ENGINE_VERAPDF) {
            return self::verifyWithVeraPdf($pdfPath, $level, $declared);
        }
        if ($engine === self::ENGINE_PDFCRAFT) {
            return self::verifyWithPdfCraft($pdfPath, $level, $declared);
        }

        self::logError('Kein PDF/A-Prüfer verfügbar (weder verapdf noch pdfcraft-cli)');

        return null;
    }

    /**
     * veraPDF-Bericht (`--format json`) in das gemeinsame Ergebnis überführen.
     * Öffentlich, damit Berichte ohne Werkzeug getestet werden können.
     *
     * @param array<string, mixed> $report
     */
    public static function fromVeraPdfReport(array $report, ?string $declaredLevel = null): ?PdfAComplianceResult {
        $job = $report['report']['jobs'][0] ?? null;
        if (!is_array($job)) {
            return null;
        }
        $validation = $job['validationResult'] ?? null;
        if (is_array($validation) && array_is_list($validation)) {
            $validation = $validation[0] ?? null;
        }
        // Kein validationResult: veraPDF konnte die Datei nicht prüfen (verschlüsselt, kein PDF).
        if (!is_array($validation) || !array_key_exists('compliant', $validation)) {
            return null;
        }

        $details = is_array($validation['details'] ?? null) ? $validation['details'] : [];
        $issues = [];
        foreach ((array) ($details['ruleSummaries'] ?? []) as $rule) {
            if (!is_array($rule) || ($rule['status'] ?? 'failed') !== 'failed') {
                continue;
            }
            $page = null;
            foreach ((array) ($rule['checks'] ?? []) as $check) {
                $context = is_array($check) ? (string) ($check['context'] ?? '') : '';
                if (preg_match('/pages\[(\d+)\]/', $context, $m) === 1) {
                    $page = (int) $m[1] + 1;
                    break;
                }
            }
            $issues[] = new PdfAComplianceIssue(
                clause: (string) ($rule['clause'] ?? ''),
                message: trim((string) ($rule['description'] ?? '')),
                occurrences: max(1, (int) ($rule['failedChecks'] ?? count((array) ($rule['checks'] ?? [])))),
                page: $page,
                specification: self::stringOrNull($rule['specification'] ?? null),
                test: isset($rule['testNumber']) ? (int) $rule['testNumber'] : null,
            );
        }

        return new PdfAComplianceResult(
            engine: self::ENGINE_VERAPDF,
            engineVersion: self::veraPdfVersionFromReport($report),
            level: self::levelFromName((string) ($validation['profileName'] ?? '')) ?? '',
            declaredLevel: $declaredLevel,
            compliant: (bool) $validation['compliant'],
            issues: $issues,
            passedRules: isset($details['passedRules']) ? (int) $details['passedRules'] : null,
            failedRules: isset($details['failedRules']) ? (int) $details['failedRules'] : count($issues),
            passedChecks: isset($details['passedChecks']) ? (int) $details['passedChecks'] : null,
            failedChecks: isset($details['failedChecks']) ? (int) $details['failedChecks'] : null,
            statement: self::stringOrNull($validation['statement'] ?? null),
        );
    }

    /**
     * Antwort von PdfCraft `pdfa_verify` in das gemeinsame Ergebnis überführen.
     *
     * @param array<string, mixed> $report
     */
    public static function fromPdfCraftReport(array $report, ?string $declaredLevel = null, string $engineVersion = ''): ?PdfAComplianceResult {
        if (!array_key_exists('compliant', $report)) {
            return null;
        }
        $issues = [];
        foreach ((array) ($report['issues'] ?? []) as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $issues[] = new PdfAComplianceIssue(
                clause: (string) ($issue['clause'] ?? ''),
                message: trim((string) ($issue['message'] ?? '')),
                page: isset($issue['page']) ? (int) $issue['page'] : null,
                fixable: isset($issue['fixable']) ? (bool) $issue['fixable'] : null,
            );
        }
        $declaredByTool = $report['declared']['pdfa'] ?? null;
        if ($declaredLevel === null && is_string($declaredByTool)) {
            $declaredLevel = self::levelFromName($declaredByTool);
        }

        return new PdfAComplianceResult(
            engine: self::ENGINE_PDFCRAFT,
            engineVersion: $engineVersion,
            level: self::levelFromName((string) ($report['level'] ?? '')) ?? '',
            declaredLevel: $declaredLevel,
            compliant: (bool) $report['compliant'],
            issues: $issues,
            failedRules: count($issues),
        );
    }

    /**
     * Zerlegt eine Ausgabe aus mehreren aufeinanderfolgenden JSON-Dokumenten
     * (PdfCraft gibt je Skriptschritt eines aus) in die einzelnen Dokumente.
     * Text außerhalb von Objekten und Listen wird übergangen.
     *
     * @return list<array<mixed>>
     */
    public static function splitJsonDocuments(string $stream): array {
        $documents = [];
        $depth = 0;
        $start = null;
        $inString = false;
        $escaped = false;
        $length = strlen($stream);
        for ($i = 0; $i < $length; $i++) {
            $char = $stream[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                if ($depth === 0) {
                    continue;
                }
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $decoded = json_decode(substr($stream, $start, $i - $start + 1), true);
                    if (is_array($decoded)) {
                        $documents[] = $decoded;
                    }
                    $start = null;
                }
            }
        }

        return $documents;
    }

    private static function verifyWithVeraPdf(string $pdfPath, string $level, ?string $declared): ?PdfAComplianceResult {
        $command = self::commandArguments(self::EXECUTABLE_VERAPDF, [
            '[FLAVOUR]' => $level,
            '[MAX-FAILURES]' => (string) self::MAX_FAILURES_DISPLAYED,
            '[INPUT]' => $pdfPath,
        ]);
        if ($command === null) {
            return null;
        }

        $result = Shell::run($command, self::TIMEOUT_VERAPDF);
        if ($result->timedOut) {
            self::logError('veraPDF abgebrochen (Zeitgrenze)', ['path' => $pdfPath, 'timeout' => self::TIMEOUT_VERAPDF]);

            return null;
        }
        // 0 = konform, 1 = nicht konform; alles andere ist ein Werkzeugfehler (4 = kein PDF).
        if (!in_array($result->exitCode, [0, 1], true)) {
            self::logError('veraPDF fehlgeschlagen', ['path' => $pdfPath, 'exitCode' => $result->exitCode, 'stderr' => self::tail($result->errorOutput)]);

            return null;
        }
        $report = json_decode($result->output, true);
        if (!is_array($report)) {
            self::logError('veraPDF lieferte kein JSON', ['path' => $pdfPath, 'stderr' => self::tail($result->errorOutput)]);

            return null;
        }
        $verdict = self::fromVeraPdfReport($report, $declared);
        if ($verdict === null) {
            self::logError('veraPDF konnte die Datei nicht prüfen', ['path' => $pdfPath, 'summary' => $report['report']['batchSummary'] ?? null]);
        }

        return $verdict;
    }

    private static function verifyWithPdfCraft(string $pdfPath, string $level, ?string $declared): ?PdfAComplianceResult {
        if (!in_array($level, self::PDFCRAFT_LEVELS, true)) {
            self::logWarning('PdfCraft prüft nur PDF/A-2b und -3b', ['level' => $level, 'path' => $pdfPath]);

            return null;
        }
        $realPath = realpath($pdfPath);
        if ($realPath === false) {
            return null;
        }

        $workDir = Platform::getTempDirectory() . '/pdfa_check_' . bin2hex(random_bytes(8));
        Folder::create($workDir, 0700);
        try {
            $script = $workDir . '/steps.json';
            File::write($script, json_encode([
                ['tool' => 'doc_open', 'args' => ['path' => $realPath]],
                ['tool' => 'pdfa_verify', 'args' => ['doc' => 1, 'level' => $level]],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $command = self::commandArguments(self::EXECUTABLE_PDFCRAFT, [
                '[SCRIPT]' => $script,
                '[ROOT]' => dirname($realPath),
            ]);
            if ($command === null) {
                return null;
            }

            $result = Shell::run($command, self::TIMEOUT_PDFCRAFT);
            if ($result->timedOut) {
                self::logError('PdfCraft abgebrochen (Zeitgrenze)', ['path' => $pdfPath, 'timeout' => self::TIMEOUT_PDFCRAFT]);

                return null;
            }
            if ($result->exitCode !== 0) {
                self::logError('PdfCraft fehlgeschlagen', ['path' => $pdfPath, 'exitCode' => $result->exitCode, 'stderr' => self::tail($result->errorOutput)]);

                return null;
            }
            $steps = self::splitJsonDocuments($result->output);
            $report = $steps[1] ?? null;
            if (!is_array($report) || !array_key_exists('compliant', $report)) {
                self::logError('PdfCraft lieferte keinen Prüfbericht', ['path' => $pdfPath, 'steps' => count($steps)]);

                return null;
            }

            return self::fromPdfCraftReport($report, $declared, self::toolVersion(self::EXECUTABLE_PDFCRAFT));
        } finally {
            Folder::delete($workDir, true);
        }
    }

    /**
     * Programm und Argumente aus der Konfiguration, Platzhalter ersetzt - als
     * Liste für {@see Shell::run()}, damit eine Zeitgrenze gilt und nichts
     * durch eine Shell läuft.
     *
     * @param array<string, string> $replacements
     * @return list<string>|null
     */
    private static function commandArguments(string $executable, array $replacements): ?array {
        $config = Config::getExecutable($executable);
        $path = $config['path'] ?? null;
        if (!is_string($path) || $path === '') {
            self::logError('Werkzeug ist nicht konfiguriert', ['executable' => $executable]);

            return null;
        }
        $arguments = [$path];
        foreach ((array) ($config['arguments'] ?? []) as $argument) {
            $argument = (string) $argument;
            $arguments[] = $replacements[$argument] ?? strtr($argument, $replacements);
        }

        return $arguments;
    }

    private static function toolAvailable(string $executable): bool {
        $config = Config::getExecutable($executable);
        $path = $config['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return false;
        }
        if (str_contains($path, '/')) {
            return Platform::isExecutable($path);
        }

        return Platform::findExecutable($path) !== null;
    }

    /** Erste Zeile von `<programm> --version`, je Prozess gemerkt. */
    private static function toolVersion(string $executable): string {
        if (isset(self::$versionCache[$executable])) {
            return self::$versionCache[$executable];
        }
        $config = Config::getExecutable($executable);
        $path = $config['path'] ?? null;
        $version = '';
        if (is_string($path) && $path !== '') {
            $result = Shell::run([$path, '--version'], 15.0);
            $firstLine = strtok($result->output, "\r\n");
            $version = is_string($firstLine) ? trim($firstLine) : '';
        }

        return self::$versionCache[$executable] = $version;
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function veraPdfVersionFromReport(array $report): string {
        $details = $report['report']['buildInformation']['releaseDetails'] ?? [];
        foreach ((array) $details as $detail) {
            if (is_array($detail) && ($detail['id'] ?? '') === 'core' && isset($detail['version'])) {
                return 'veraPDF ' . $detail['version'];
            }
        }
        foreach ((array) $details as $detail) {
            if (is_array($detail) && isset($detail['version'])) {
                return 'veraPDF ' . $detail['version'];
            }
        }

        return 'veraPDF';
    }

    /** "PDF/A-2b validation profile", "PDF/A-4F" oder "2b" -> "2b", "4f". */
    private static function levelFromName(string $name): ?string {
        if (preg_match('/PDF\/A-(\d)([A-Za-z]?)/i', $name, $m) === 1) {
            $level = strtolower($m[1] . $m[2]);
        } else {
            $level = strtolower(trim($name));
        }

        return in_array($level, self::LEVELS, true) ? $level : null;
    }

    private static function stringOrNull(mixed $value): ?string {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function tail(string $text, int $lines = 5): string {
        $parts = preg_split('/\R/', trim($text)) ?: [];

        return implode("\n", array_slice($parts, -$lines));
    }
}
