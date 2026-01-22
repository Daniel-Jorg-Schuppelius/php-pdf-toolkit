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
        /** Name des Readers, der den Text extrahiert hat */
        public ?string $reader,
        /** Ob OCR verwendet wurde (gescanntes Dokument) */
        public bool $isScanned,
        /** Absoluter Pfad zur Quelldatei */
        public string $sourcePath,
        /** Zusätzliche Metadaten */
        public array $metadata = []
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
            array_merge($this->metadata, $additionalMetadata)
        );
    }
}
