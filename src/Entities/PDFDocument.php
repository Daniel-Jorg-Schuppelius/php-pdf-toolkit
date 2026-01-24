<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFDocument.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Entities;

use PDFToolkit\Enums\PDFReaderType;

/**
 * Value Object für ein verarbeitetes PDF-Dokument.
 * 
 * Enthält den extrahierten Text, Informationen über den verwendeten Reader
 * und Metadaten.
 */
final readonly class PDFDocument {
    public function __construct(
        /** Der extrahierte Text (null wenn Extraktion fehlgeschlagen) */
        public ?string $text,
        /** Reader-Typ, der den Text extrahiert hat */
        public ?PDFReaderType $reader,
        /** Ob OCR verwendet wurde (gescanntes Dokument) */
        public bool $isScanned,
        /** Absoluter Pfad zur Quelldatei */
        public string $sourcePath,
        /** Zusätzliche Metadaten */
        public array $metadata = [],
        /** Alternative Ergebnisse von anderen Readern [PDFReaderType->value => ['text' => string, 'isScanned' => bool]] */
        public array $alternatives = []
    ) {
    }

    /**
     * Prüft ob die Extraktion erfolgreich war.
     */
    public function hasText(): bool {
        return $this->text !== null && trim($this->text) !== '';
    }

    /**
     * Gibt den Text zurück oder einen Fallback-Wert.
     */
    public function getTextOrDefault(string $default = ''): string {
        return $this->hasText() ? $this->text : $default;
    }

    /**
     * Gibt die Anzahl der extrahierten Zeichen zurück.
     */
    public function getTextLength(): int {
        return $this->text !== null ? mb_strlen($this->text) : 0;
    }

    /**
     * Gibt die Anzahl der Zeilen im extrahierten Text zurück.
     */
    public function getLineCount(): int {
        if ($this->text === null) {
            return 0;
        }
        return count(preg_split('/\r\n|\r|\n/', $this->text));
    }

    /**
     * Prüft ob ein bestimmter Metadaten-Schlüssel existiert.
     */
    public function hasMeta(string $key): bool {
        return isset($this->metadata[$key]);
    }

    /**
     * Gibt einen Metadaten-Wert zurück.
     */
    public function getMeta(string $key, mixed $default = null): mixed {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Erstellt eine neue Instanz mit zusätzlichen Metadaten.
     */
    public function withMetadata(array $additionalMetadata): self {
        return new self(
            $this->text,
            $this->reader,
            $this->isScanned,
            $this->sourcePath,
            array_merge($this->metadata, $additionalMetadata),
            $this->alternatives
        );
    }

    /**
     * Prüft ob alternative Ergebnisse vorhanden sind.
     */
    public function hasAlternatives(): bool {
        return count($this->alternatives) > 0;
    }

    /**
     * Gibt die Reader-Typen aller Reader zurück, die Ergebnisse geliefert haben.
     * 
     * @return PDFReaderType[]
     */
    public function getAvailableReaders(): array {
        $readers = [];

        if ($this->reader !== null) {
            $readers[] = $this->reader;
        }

        foreach (array_keys($this->alternatives) as $readerValue) {
            $type = PDFReaderType::tryFrom($readerValue);
            if ($type !== null && !in_array($type, $readers, true)) {
                $readers[] = $type;
            }
        }

        return $readers;
    }

    /**
     * Gibt den Text eines bestimmten Readers zurück.
     * 
     * @param PDFReaderType $readerType Reader-Typ
     * @return string|null Text oder null wenn nicht vorhanden
     */
    public function getTextByReader(PDFReaderType $readerType): ?string {
        if ($this->reader === $readerType) {
            return $this->text;
        }
        return $this->alternatives[$readerType->value]['text'] ?? null;
    }

    /**
     * Prüft ob ein bestimmter Reader OCR verwendet hat.
     */
    public function isScannedByReader(PDFReaderType $readerType): bool {
        if ($this->reader === $readerType) {
            return $this->isScanned;
        }
        return $this->alternatives[$readerType->value]['isScanned'] ?? false;
    }

    /**
     * Gibt das beste Ergebnis basierend auf Textlänge zurück.
     * Nützlich wenn verschiedene OCR-Reader unterschiedliche Qualität liefern.
     * 
     * @return array{text: string, reader: PDFReaderType, isScanned: bool}|null
     */
    public function getBestResult(): ?array {
        $best = null;
        $bestLength = 0;

        // Primäres Ergebnis
        if ($this->text !== null && $this->reader !== null) {
            $best = [
                'text' => $this->text,
                'reader' => $this->reader,
                'isScanned' => $this->isScanned
            ];
            $bestLength = mb_strlen($this->text);
        }

        // Alternative Ergebnisse prüfen
        foreach ($this->alternatives as $readerValue => $data) {
            $length = mb_strlen($data['text'] ?? '');
            if ($length > $bestLength) {
                $type = PDFReaderType::tryFrom($readerValue);
                if ($type !== null) {
                    $best = [
                        'text' => $data['text'],
                        'reader' => $type,
                        'isScanned' => $data['isScanned']
                    ];
                    $bestLength = $length;
                }
            }
        }

        return $best;
    }

    /**
     * Erstellt eine neue Instanz mit zusätzlichen alternativen Ergebnissen.
     */
    public function withAlternatives(array $additionalAlternatives): self {
        return new self(
            $this->text,
            $this->reader,
            $this->isScanned,
            $this->sourcePath,
            $this->metadata,
            array_merge($this->alternatives, $additionalAlternatives)
        );
    }
}
