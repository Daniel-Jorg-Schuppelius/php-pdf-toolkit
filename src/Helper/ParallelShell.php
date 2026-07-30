<?php
/*
 * Created on   : Thu Jul 30 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ParallelShell.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use ERRORToolkit\Traits\ErrorLog;

/**
 * Führt mehrere Shell-Kommandos nebenläufig aus.
 *
 * Gedacht für die seitenweise OCR: ein Tesseract-Prozess je Seite, mehrere
 * gleichzeitig. Das lohnt erst, seit jeder Prozess auf einen Thread begrenzt
 * ist ({@see \PDFToolkit\Readers\TesseractReader::OMP_SINGLE_THREAD}) — ohne
 * das Limit konkurrieren die Prozesse um dieselben Kerne und der parallele
 * Lauf ist langsamer als der sequentielle (gemessen 2026-07-30: 3:12 gegen
 * 2:45 für sieben Seiten; mit Limit 11,6s).
 */
final class ParallelShell {
    use ErrorLog;

    /** Obergrenze gleichzeitiger Prozesse, unabhängig von der Kernzahl. */
    private const MAX_PROCESSES = 16;

    /**
     * Führt die Kommandos aus und liefert die Exit-Codes unter denselben Schlüsseln.
     *
     * Die Ausgabe der Prozesse wird verworfen — die Aufrufer lesen ihre
     * Ergebnisse aus Dateien. Bei einem einzelnen Kommando wird ohne Umweg
     * direkt ausgeführt.
     *
     * @param array<string|int, string> $commands
     * @param int|null $maxParallel null = Kernzahl (auf MAX_PROCESSES begrenzt)
     * @return array<string|int, int> Exit-Code je Schlüssel
     */
    public static function run(array $commands, ?int $maxParallel = null): array {
        if ($commands === []) {
            return [];
        }

        $limit = max(1, min($maxParallel ?? self::cpuCount(), self::MAX_PROCESSES));
        $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];

        $pending = $commands;
        $running = [];
        $exitCodes = [];

        while ($pending !== [] || $running !== []) {
            while ($pending !== [] && count($running) < $limit) {
                $key = array_key_first($pending);
                $command = $pending[$key];
                unset($pending[$key]);

                $pipes = [];
                $process = @proc_open($command, $descriptors, $pipes);
                if (!is_resource($process)) {
                    self::logWarning("Prozess nicht startbar: {$command}");
                    $exitCodes[$key] = -1;
                    continue;
                }
                $running[$key] = $process;
            }

            if ($running === []) {
                continue;
            }

            // Auf Abschluss warten, ohne die CPU mit Leerlauf zu belasten.
            $finished = false;
            foreach ($running as $key => $process) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    continue;
                }
                $exitCodes[$key] = $status['exitcode'];
                proc_close($process);
                unset($running[$key]);
                $finished = true;
            }

            if (!$finished && $running !== []) {
                usleep(20000);
            }
        }

        return $exitCodes;
    }

    /**
     * Zahl der nutzbaren CPU-Kerne (Fallback 4, wenn nicht ermittelbar).
     */
    public static function cpuCount(): int {
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo)) {
            $cores = preg_match_all('/^processor\s*:/m', $cpuInfo);
            if ($cores > 0) {
                return $cores;
            }
        }

        return 4;
    }
}
