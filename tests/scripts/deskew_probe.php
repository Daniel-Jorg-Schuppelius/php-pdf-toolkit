<?php
/**
 * Subprozess-Probe fuer TesseractReader::deskewPageImages().
 *
 * Der Aufrufer stellt ein Verzeichnis mit einer mogrify-Attrappe in PATH voran;
 * die Konfiguration loest den Namen in diesem frischen Prozess ueber PATH auf.
 *
 * Aufruf: php deskew_probe.php <bild> [<bild> ...]
 * Ausgabe: eine JSON-Zeile mit usable.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PDFToolkit\Config\Config;
use PDFToolkit\Readers\TesseractReader;

$usable = TesseractReader::deskewPageImages(Config::getInstance(), array_slice($argv, 1));

echo json_encode(['usable' => $usable], JSON_THROW_ON_ERROR), "\n";
