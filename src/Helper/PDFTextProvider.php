<?php
/*
 * Created on   : Sat Apr 12 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFTextProvider.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Helper;

use CommonToolkit\Helper\FileSystem\File;
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;
use PDFToolkit\Entities\PDFDocument;
use PDFToolkit\Enums\{PDFReaderType, PDFTextVariant};
use PDFToolkit\Helper\PDFHelper;
use PDFToolkit\Registries\PDFReaderRegistry;

/**
 * Lazy-Loading Provider für PDF-Text-Extraktion in verschiedenen Varianten.
 *
 * Stellt eine einfache API bereit, um unterschiedliche Reader-Ergebnisse
 * auf Abruf nachzuladen – ohne manuelles Options-Array-Handling.
 *
 * Jede Variante wird beim ersten Aufruf lazy extrahiert und danach gecacht.
 *
 * Verwendung:
 *
 *     $provider = new PDFTextProvider($pdfPath);
 *     $text     = $provider->rawText();        // pdftotext ohne Layout (Regex)
 *     $layout   = $provider->layoutText();     // pdftotext mit Layout (Tabellen)
 *     $ocr      = $provider->ocrText('deu');   // OCR erzwingen
 *
 *     // Mit PDFDocument vom Detector (vermeidet redundante Extraktion):
 *     $provider = new PDFTextProvider($pdfPath, $pdfDocument);
 *     $reader   = $provider->usedReader();      // Kein Re-Extract nötig
 */
final class PDFTextProvider {
    use ErrorLog;

    /** @var array<string, ?string> Cache: key => extrahierter Text */
    private array $textCache = [];

    /** @var array<string, PDFDocument> Cache: key => PDFDocument */
    private array $documentCache = [];

    /**
     * @param string $pdfPath Pfad zur PDF-Datei
     * @param PDFDocument|string|null $initialResult Bereits extrahierter Text oder PDFDocument (z.B. vom Detector)
     */
    public function __construct(
        private readonly string $pdfPath,
        PDFDocument|string|null $initialResult = null,
    ) {
        if (!File::exists($pdfPath)) {
            $this->logErrorAndThrow(InvalidArgumentException::class, "PDF-Datei existiert nicht: {$pdfPath}");
        }

        if (!PDFHelper::isValidPdf($pdfPath)) {
            $this->logErrorAndThrow(InvalidArgumentException::class, "Keine gültige PDF-Datei: {$pdfPath}");
        }

        if ($initialResult instanceof PDFDocument) {
            // Vollständiges Document cachen (für usedReader(), isScanned())
            $this->documentCache[$this->buildCacheKey([])] = $initialResult;
            $best = $initialResult->getBestResult();
            $text = $best !== null ? $best['text'] : $initialResult->text;
            if ($text !== null && trim($text) !== '') {
                $this->textCache[PDFTextVariant::Default->value] = $text;
                $this->logDebug("PDFTextProvider initialisiert mit PDFDocument für: {$this->pdfPath}");
            } else {
                $this->logDebug("PDFTextProvider initialisiert mit leerem PDFDocument für: {$this->pdfPath}");
            }
        } elseif (is_string($initialResult) && trim($initialResult) !== '') {
            $this->textCache[PDFTextVariant::Default->value] = $initialResult;
            $this->logDebug("PDFTextProvider initialisiert mit Text (" . strlen($initialResult) . " Bytes) für: {$this->pdfPath}");
        } else {
            $this->logDebug("PDFTextProvider initialisiert ohne Initialtext für: {$this->pdfPath}");
        }
    }

    /**
     * Pfad zur PDF-Datei.
     */
    public function getPath(): string {
        return $this->pdfPath;
    }

    // ========================================================================
    // Convenience-Methoden: Häufige Extraktionsmodi
    // ========================================================================

    /**
     * Prüft ob für die Standard-Variante Text vorhanden ist.
     *
     * Triggert KEINE neue Extraktion – prüft nur den Cache.
     * Nützlich um schnell zu entscheiden ob eine andere Variante versucht werden soll.
     */
    public function hasText(): bool {
        return isset($this->textCache[PDFTextVariant::Default->value])
            && $this->textCache[PDFTextVariant::Default->value] !== null;
    }

    /**
     * Standard-Text (schnellster verfügbarer Reader, mit OCR-Fallback).
     *
     * Entspricht dem Text, der bei der Erkennung extrahiert wurde.
     * Falls der initiale Text vorhanden ist, wird er direkt zurückgegeben.
     */
    public function text(): ?string {
        return $this->getCachedOrExtract(PDFTextVariant::Default, []);
    }

    /**
     * Text mit Layout-Erhaltung (pdftotext -layout).
     *
     * Ideal für PDFs mit tabellarischen Daten, bei denen die Spaltenausrichtung
     * durch Whitespace erhalten bleiben muss.
     *
     * Nur Text-Reader, kein OCR-Fallback.
     * Dual-Strategy ist deaktiviert, damit garantiert der Layout-Text zurückkommt.
     */
    public function layoutText(): ?string {
        return $this->getCachedOrExtract(PDFTextVariant::Layout, [
            'layout' => true,
            'textOnly' => true,
            'dualStrategy' => false,
        ]);
    }

    /**
     * Text ohne Layout (pdftotext ohne -layout, Fließtext).
     *
     * Ideal für Regex-basierte Extraktion, da keine Whitespace-Spalten stören.
     * Nur Text-Reader, kein OCR-Fallback.
     * Dual-Strategy ist deaktiviert, damit garantiert der Raw-Text zurückkommt.
     */
    public function rawText(): ?string {
        return $this->getCachedOrExtract(PDFTextVariant::Raw, [
            'layout' => false,
            'textOnly' => true,
            'dualStrategy' => false,
        ]);
    }

    /**
     * Nur Text-Reader (kein OCR), mit automatischer Layout-Auswahl.
     *
     * Wie text(), aber ohne OCR-Fallback – für digitale PDFs wo OCR
     * die Ergebnisse verschlechtert (z.B. Beträge).
     */
    public function textOnly(): ?string {
        return $this->getCachedOrExtract(PDFTextVariant::TextOnly, [
            'textOnly' => true,
        ]);
    }

    /**
     * OCR-Text erzwingen – auch wenn ein Textlayer vorhanden ist.
     *
     * Nützlich wenn der eingebettete Text fehlerhaft oder unvollständig ist,
     * oder wenn die visuelle Darstellung vom Textlayer abweicht.
     *
     * @param string $language Tesseract-Sprache (z.B. 'deu', 'eng', 'deu+eng')
     */
    public function ocrText(string $language = 'deu+eng'): ?string {
        $key = 'ocr:' . $language;
        return $this->getCachedOrExtract($key, [
            'forceOcr' => true,
            'language' => $language,
        ]);
    }

    /**
     * Text mit Qualitätsprüfung und automatischem OCR-Fallback.
     *
     * Extrahiert zunächst mit Text-Readern. Wenn der Qualitäts-Score
     * unter dem Schwellwert liegt, wird OCR als Fallback versucht
     * und das bessere Ergebnis verwendet.
     *
     * @param int $threshold Score-Schwelle (0-100). Standard: 60
     * @param string $language OCR-Sprache für den Fallback
     */
    public function qualityCheckedText(int $threshold = 60, string $language = 'deu+eng'): ?string {
        $key = "quality:{$threshold}:{$language}";
        return $this->getCachedOrExtract($key, [
            'qualityCheck' => true,
            'qualityThreshold' => $threshold,
            'language' => $language,
        ]);
    }

    /**
     * Versucht mehrere Extraktionsvarianten der Reihe nach und gibt den
     * ersten nicht-leeren Text zurück.
     *
     * Nützlich wenn unklar ist, welche Variante die besten Ergebnisse liefert:
     *
     *     $text = $provider->textWithFallback(
     *         fn($p) => $p->rawText(),
     *         fn($p) => $p->layoutText(),
     *         fn($p) => $p->ocrText('deu'),
     *     );
     *
     * @param callable(self): ?string ...$extractors Extraktions-Callables, werden nacheinander ausgeführt
     * @return string|null Erster nicht-leerer Text oder null
     */
    public function textWithFallback(callable ...$extractors): ?string {
        foreach ($extractors as $extractor) {
            $text = $extractor($this);
            if ($text !== null && trim($text) !== '') {
                return $text;
            }
        }

        $this->logDebug("textWithFallback: Keine Variante lieferte Text für: {$this->pdfPath}");
        return null;
    }

    /**
     * Text von einem bestimmten Reader.
     *
     * Versucht den angegebenen Reader zuerst, fällt bei Fehler
     * auf den normalen Ablauf zurück.
     *
     * @param PDFReaderType $reader Der bevorzugte Reader
     * @param bool $exclusive Wenn true, NUR diesen Reader verwenden (kein Fallback)
     * @param array $options Zusätzliche Optionen (z.B. 'language' für OCR-Reader)
     */
    public function textByReader(PDFReaderType $reader, bool $exclusive = false, array $options = []): ?string {
        $key = 'reader:' . $reader->value . ($exclusive ? ':exclusive' : '');
        if (isset($options['language'])) {
            $key .= ':lang=' . $options['language'];
        }

        if (isset($this->textCache[$key])) {
            return $this->textCache[$key];
        }

        if ($exclusive) {
            // Direkt den Reader ansprechen ohne Fallback
            $registry = PDFReaderRegistry::getInstance();
            $readerInstance = $registry->getByType($reader);

            if ($readerInstance === null || !$readerInstance->canHandle($this->pdfPath)) {
                $this->logDebug("Reader {$reader->value} nicht verfügbar für: {$this->pdfPath}");
                $this->textCache[$key] = null;
                return null;
            }

            $text = $readerInstance->extractText($this->pdfPath, $options);
            $this->textCache[$key] = ($text !== null && trim($text) !== '') ? $text : null;
            return $this->textCache[$key];
        }

        return $this->getCachedOrExtract($key, array_merge($options, [
            'preferredReader' => $reader,
        ]));
    }

    // ========================================================================
    // PDFDocument-Zugriff (für erweiterte Kontrolle)
    // ========================================================================

    /**
     * Gibt das PDFDocument für bestimmte Optionen zurück.
     *
     * Für Zugriff auf Metadaten, Reader-Typ, Alternativen etc.
     */
    public function document(array $options = []): PDFDocument {
        $key = $this->buildCacheKey($options);

        if (isset($this->documentCache[$key])) {
            $this->logDebug("Document Cache-Hit ({$key}): {$this->pdfPath}");
            return $this->documentCache[$key];
        }

        $registry = PDFReaderRegistry::getInstance();
        $textOnly = $options['textOnly'] ?? false;

        $this->logDebug("Lade PDFDocument ({$key}, textOnly: " . ($textOnly ? 'ja' : 'nein') . "): {$this->pdfPath}");

        $doc = $textOnly
            ? $registry->extractTextOnly($this->pdfPath, $options)
            : $registry->extractText($this->pdfPath, $options);

        $this->documentCache[$key] = $doc;
        return $doc;
    }

    /**
     * Welcher Reader wurde für die Standard-Extraktion verwendet?
     */
    public function usedReader(): ?PDFReaderType {
        return $this->document()->reader;
    }

    /**
     * Ist das PDF ein Scan (OCR war nötig)?
     */
    public function isScanned(): bool {
        return $this->document()->isScanned;
    }

    /**
     * Alle verfügbaren Reader-Ergebnisse extrahieren (für Debugging/Analyse).
     *
     * Befüllt den Cache mit allen Reader-Ergebnissen, sodass nachfolgende
     * textByReader()-Aufrufe sofort aus dem Cache bedient werden.
     */
    public function extractAll(): PDFDocument {
        $this->logInfo("Extrahiere mit allen Readern: {$this->pdfPath}");
        $registry = PDFReaderRegistry::getInstance();
        $doc = $registry->extractAllText($this->pdfPath);

        // Primäres Ergebnis cachen
        if ($doc->reader !== null && $doc->text !== null && trim($doc->text) !== '') {
            $key = 'reader:' . $doc->reader->value . ':exclusive';
            $this->textCache[$key] = $doc->text;
        }

        // Alternative Ergebnisse cachen
        foreach ($doc->alternatives as $readerValue => $data) {
            $text = $data['text'] ?? null;
            if ($text !== null && trim($text) !== '') {
                $key = 'reader:' . $readerValue . ':exclusive';
                $this->textCache[$key] = $text;
            }
        }

        // Default-Variante mit bestem Ergebnis befüllen (falls noch leer)
        if (!isset($this->textCache[PDFTextVariant::Default->value])) {
            $best = $doc->getBestResult();
            if ($best !== null) {
                $this->textCache[PDFTextVariant::Default->value] = $best['text'];
            }
        }

        return $doc;
    }

    // ========================================================================
    // Cache-Verwaltung & Introspection
    // ========================================================================

    /**
     * Cache leeren (alle Varianten).
     */
    public function clearCache(): void {
        $variants = count($this->textCache);
        $documents = count($this->documentCache);
        $this->textCache = [];
        $this->documentCache = [];
        $this->logDebug("Cache geleert ({$variants} Text-Varianten, {$documents} Dokumente): {$this->pdfPath}");
    }

    /**
     * Prüft ob eine bestimmte Variante bereits gecacht ist.
     */
    public function isCached(PDFTextVariant|string $variant): bool {
        $key = $variant instanceof PDFTextVariant ? $variant->value : $variant;
        return array_key_exists($key, $this->textCache);
    }

    /**
     * Gibt die Namen aller bereits gecachten Varianten zurück.
     *
     * Nützlich für Debugging und um zu sehen, welche Extraktionen bereits durchgeführt wurden.
     *
     * @return string[] Liste der Cache-Keys (z.B. ['default', 'layout', 'ocr:deu+eng'])
     */
    public function cachedVariants(): array {
        return array_keys($this->textCache);
    }

    /**
     * Textlänge (Zeichen) für eine bestimmte Variante.
     *
     * Gibt null zurück wenn die Variante nicht gecacht oder leer ist.
     * Triggert KEINE neue Extraktion.
     */
    public function textLength(PDFTextVariant|string $variant = PDFTextVariant::Default): ?int {
        $key = $variant instanceof PDFTextVariant ? $variant->value : $variant;
        if (!isset($this->textCache[$key]) || $this->textCache[$key] === null) {
            return null;
        }
        return mb_strlen($this->textCache[$key]);
    }

    /**
     * Zeilenanzahl für eine bestimmte Variante.
     *
     * Gibt null zurück wenn die Variante nicht gecacht oder leer ist.
     * Triggert KEINE neue Extraktion.
     */
    public function lineCount(PDFTextVariant|string $variant = PDFTextVariant::Default): ?int {
        $key = $variant instanceof PDFTextVariant ? $variant->value : $variant;
        if (!isset($this->textCache[$key]) || $this->textCache[$key] === null) {
            return null;
        }
        return substr_count($this->textCache[$key], "\n") + 1;
    }

    // ========================================================================
    // Interne Methoden
    // ========================================================================

    /**
     * Extrahiert Text mit den gegebenen Optionen oder gibt gecachten Text zurück.
     */
    private function getCachedOrExtract(PDFTextVariant|string $cacheKey, array $options): ?string {
        $key = $cacheKey instanceof PDFTextVariant ? $cacheKey->value : $cacheKey;

        if (array_key_exists($key, $this->textCache)) {
            $this->logDebug("Cache-Hit für Variante '{$key}': {$this->pdfPath}");
            return $this->textCache[$key];
        }

        $this->logInfo("Extrahiere Text (Variante '{$key}') für: {$this->pdfPath}");
        $doc = $this->document($options);

        // Bestes Ergebnis verwenden wenn Alternativen vorhanden
        $best = $doc->getBestResult();
        $text = $best !== null ? $best['text'] : $doc->text;

        $this->textCache[$key] = ($text !== null && trim($text) !== '') ? $text : null;

        if ($this->textCache[$key] !== null) {
            $reader = $best !== null ? $best['reader']->value : ($doc->reader?->value ?? 'unbekannt');
            $this->logDebug("Extraktion erfolgreich (Variante '{$key}', Reader: {$reader}, " . strlen($this->textCache[$key]) . " Bytes): {$this->pdfPath}");
        } else {
            $this->logWarning("Extraktion lieferte keinen Text (Variante '{$key}'): {$this->pdfPath}");
        }

        return $this->textCache[$key];
    }

    /**
     * Baut einen eindeutigen Cache-Key aus den Optionen.
     */
    private function buildCacheKey(array $options): string {
        $parts = ['doc'];

        if (!empty($options['forceOcr'])) {
            $parts[] = 'ocr';
        }
        if (!empty($options['textOnly'])) {
            $parts[] = 'textonly';
        }
        if (isset($options['layout'])) {
            $parts[] = 'layout=' . ($options['layout'] ? '1' : '0');
        }
        if (isset($options['dualStrategy']) && !$options['dualStrategy']) {
            $parts[] = 'nodual';
        }
        if (isset($options['qualityCheck'])) {
            $parts[] = 'qc=' . ($options['qualityCheck'] ? '1' : '0');
        }
        if (isset($options['qualityThreshold'])) {
            $parts[] = 'qt=' . $options['qualityThreshold'];
        }
        if (isset($options['language'])) {
            $parts[] = 'lang=' . $options['language'];
        }
        if (isset($options['preferredReader']) && $options['preferredReader'] instanceof PDFReaderType) {
            $parts[] = 'reader=' . $options['preferredReader']->value;
        }

        return implode(':', $parts);
    }
}
