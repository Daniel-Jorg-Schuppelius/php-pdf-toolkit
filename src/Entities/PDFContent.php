<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFContent.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace PDFToolkit\Entities;

/**
 * Value Object für zu erstellenden PDF-Inhalt.
 * 
 * Unterstützt verschiedene Quellformate: HTML, Text, Datei.
 */
final readonly class PDFContent {
    public const TYPE_HTML = 'html';
    public const TYPE_TEXT = 'text';
    public const TYPE_FILE = 'file';

    private function __construct(
        /** Der Inhalt (HTML, Text oder Dateipfad) */
        public string $content,
        /** Art des Inhalts: 'html', 'text' oder 'file' */
        public string $type,
        /** Optionale Metadaten für das PDF */
        public array $metadata = []
    ) {
    }

    /**
     * Erstellt Content aus HTML.
     */
    public static function fromHtml(string $html, array $metadata = []): self {
        return new self($html, self::TYPE_HTML, $metadata);
    }

    /**
     * Erstellt Content aus reinem Text.
     */
    public static function fromText(string $text, array $metadata = []): self {
        return new self($text, self::TYPE_TEXT, $metadata);
    }

    /**
     * Erstellt Content aus einer HTML-Datei.
     */
    public static function fromFile(string $filePath, array $metadata = []): self {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }
        return new self($filePath, self::TYPE_FILE, $metadata);
    }

    /**
     * Prüft ob der Content HTML ist.
     */
    public function isHtml(): bool {
        return $this->type === self::TYPE_HTML;
    }

    /**
     * Prüft ob der Content reiner Text ist.
     */
    public function isText(): bool {
        return $this->type === self::TYPE_TEXT;
    }

    /**
     * Prüft ob der Content eine Datei ist.
     */
    public function isFile(): bool {
        return $this->type === self::TYPE_FILE;
    }

    /**
     * Gibt den Inhalt als HTML zurück.
     * Bei Text wird dieser in HTML eingebettet.
     */
    public function getAsHtml(): string {
        if ($this->isHtml()) {
            return $this->content;
        }

        if ($this->isFile()) {
            return file_get_contents($this->content) ?: '';
        }

        // Text zu HTML konvertieren
        $escaped = htmlspecialchars($this->content, ENT_QUOTES, 'UTF-8');
        $escaped = nl2br($escaped);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12pt; line-height: 1.5; }
    </style>
</head>
<body>
    <pre style="white-space: pre-wrap; font-family: inherit;">{$escaped}</pre>
</body>
</html>
HTML;
    }

    /**
     * Gibt den reinen Text zurück.
     */
    public function getAsText(): string {
        if ($this->isText()) {
            return $this->content;
        }

        if ($this->isFile()) {
            $html = file_get_contents($this->content) ?: '';
            return strip_tags($html);
        }

        // HTML zu Text
        return strip_tags($this->content);
    }

    /**
     * Gibt Metadaten zurück.
     */
    public function getMeta(string $key, mixed $default = null): mixed {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Erstellt eine Kopie mit zusätzlichen Metadaten.
     */
    public function withMetadata(array $metadata): self {
        return new self($this->content, $this->type, array_merge($this->metadata, $metadata));
    }

    /**
     * Gibt den Titel aus den Metadaten zurück.
     */
    public function getTitle(): ?string {
        return $this->metadata['title'] ?? null;
    }

    /**
     * Gibt den Autor aus den Metadaten zurück.
     */
    public function getAuthor(): ?string {
        return $this->metadata['author'] ?? null;
    }

    /**
     * Gibt das Thema aus den Metadaten zurück.
     */
    public function getSubject(): ?string {
        return $this->metadata['subject'] ?? null;
    }
}
