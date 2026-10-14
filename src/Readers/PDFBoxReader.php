<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFBoxReader.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Readers;

use CommonToolkit\Helper\FileSystem\File;
use CommonToolkit\Helper\Shell;
use ERRORToolkit\Traits\ErrorLog;
use PDFToolkit\Config\Config;
use PDFToolkit\Contracts\PDFReaderInterface;
use PDFToolkit\Enums\PDFReaderType;
use PDFToolkit\Helper\{TempPath, ToolTimeout};

/**
 * PDF-Reader basierend auf Apache PDFBox (Java).
 *
 * Bessere Ergebnisse bei komplexen Layouts als pdftotext.
 * Benötigt Java und pdfbox-app.jar.
 */
final class PDFBoxReader implements PDFReaderInterface {
    use ErrorLog;

    private ?bool $available = null;
    private Config $config;

    public function __construct() {
        $this->config = Config::getInstance();
    }

    public static function getType(): PDFReaderType {
        return PDFReaderType::Pdfbox;
    }

    public static function getPriority(): int {
        return PDFReaderType::Pdfbox->getPriority();
    }

    public static function supportsScannedPdfs(): bool {
        return PDFReaderType::Pdfbox->supportsScannedPdfs();
    }

    public static function supportsTextPdfs(): bool {
        return PDFReaderType::Pdfbox->supportsTextPdfs();
    }

    public function isAvailable(): bool {
        if ($this->available !== null) {
            return $this->available;
        }

        // Der Config-Eintrag existiert auch dann, wenn die JAR nicht auffindbar war –
        // dann ist 'path' null. Deshalb den aufgelösten Pfad prüfen, nicht nur den Eintrag.
        $this->available = $this->config->getShellExecutable('java') !== null
            && $this->config->getJavaExecutablePath('pdfbox') !== '';
        return $this->available;
    }

    public function canHandle(string $pdfPath): bool {
        return $this->isAvailable();
    }

    public function extractText(string $pdfPath, array $options = []): ?string {
        if (!$this->isAvailable()) {
            return null;
        }

        // Vorab exklusiv angelegt (0600): PDFBox schreibt den Dokumenttext hinein
        $tempFile = TempPath::file('pdfbox', 'txt');
        try {
            // PDFBox Befehl aus Config bauen
            $command = $this->config->buildJavaCommand('pdfbox', [
                '[INPUT]' => $pdfPath,
                '[OUTPUT]' => $tempFile,
            ]);

            if ($command === null) {
                $this->logDebug("PDFBox command could not be built");
                return null;
            }

            $output = [];
            $returnCode = 0;
            Shell::executeShellCommand($command, $output, $returnCode, timeout: ToolTimeout::forSize($pdfPath));

            if ($returnCode !== 0) {
                $this->logDebug("PDFBox failed with code $returnCode for: $pdfPath");
                return null;
            }

            $text = File::read($tempFile);
        } finally {
            File::delete($tempFile);
        }

        // Prüfen ob relevanter Text extrahiert wurde
        $trimmed = preg_replace('/\s+/', '', $text);
        if (strlen($trimmed) < 10) {
            $this->logDebug("PDFBox extracted too little text from: $pdfPath");
            return null;
        }

        return $this->logDebugAndReturn($text, "PDFBox successfully extracted " . strlen($text) . " chars from: $pdfPath");
    }
}
