# PHP PDF Toolkit – Copilot Instructions

## Projektübersicht

PHP 8.2+ Bibliothek zur Text-Extraktion aus PDF-Dokumenten mit intelligenter Reader-Auswahl. Unterstützt sowohl Text-PDFs als auch gescannte Dokumente via OCR.

## Architektur

```
PDFReaderRegistry → [Reader nach Priorität] → PDFDocument
                          ↓
              PdftotextReader (10)     # Schnell, für Text-PDFs
              PdfboxReader (30)        # Komplexe Layouts
              TesseractReader (50)     # OCR für Scans
              OcrmypdfReader (60)      # Beste OCR-Qualität
```

### Workflow
1. `PDFReaderRegistry` lädt alle verfügbaren Reader
2. Sortiert nach Priorität (niedriger = zuerst)
3. Probiert erst Text-Reader, dann OCR-Reader
4. Gibt `PDFDocument` mit extrahiertem Text und Metadaten zurück

## Projektstruktur

```
php-pdf-toolkit/
├── config/
│   └── executables.json.sample  # Tool-Pfade
├── data/
│   └── tesseract/               # Traineddata für OCR
├── src/
│   ├── Contracts/
│   │   └── PDFReaderInterface.php
│   ├── Entities/
│   │   └── PDFDocument.php      # Value Object
│   ├── Helper/
│   │   └── PDFHelper.php        # Validierung, Metadaten
│   ├── Readers/
│   │   ├── PdftotextReader.php
│   │   ├── PdfboxReader.php
│   │   ├── TesseractReader.php
│   │   └── OcrmypdfReader.php
│   └── Registries/
│       └── PDFReaderRegistry.php
└── tests/
```

## Neuen Reader erstellen

1. Erstelle Klasse in `src/Readers/`
2. Implementiere `PDFReaderInterface`:

```php
final class MyReader implements PDFReaderInterface {
    public static function getName(): string {
        return 'myreader';
    }

    public static function getPriority(): int {
        return 40; // Zwischen pdfbox und tesseract
    }

    public static function supportsScannedPdfs(): bool {
        return false; // true für OCR-Reader
    }

    public static function supportsTextPdfs(): bool {
        return true;
    }

    public function isAvailable(): bool {
        // Prüfen ob externes Tool installiert ist
        exec('which mytool 2>/dev/null', $output, $returnCode);
        return $returnCode === 0;
    }

    public function canHandle(string $pdfPath): bool {
        return $this->isAvailable();
    }

    public function extractText(string $pdfPath, array $options = []): ?string {
        // Text extrahieren und zurückgeben
        // null bei Fehler
    }
}
```

Der Reader wird automatisch von der Registry erkannt.

## Reader-Prioritäten

| Bereich | Beschreibung |
|---------|--------------|
| 10-20 | Schnelle Text-Extraktion (pdftotext) |
| 30-40 | Komplexe Layouts (pdfbox, mupdf) |
| 50-70 | OCR für gescannte Dokumente |
| 80+ | Spezielle/langsame Reader |

## Abhängigkeiten

### php-common-toolkit
- `CommonToolkit\Helper\FileSystem\File` – Datei-Operationen
- `CommonToolkit\Helper\FileSystem\Folder` – Verzeichnis-Operationen
- `ConfigToolkit\ConfigLoader` – Konfiguration laden
- `ERRORToolkit\Traits\ErrorLog` – Logging

### Externe Tools
- `pdftotext`, `pdfinfo`, `pdftoppm` – poppler-utils
- `tesseract` – tesseract-ocr
- `ocrmypdf` – ocrmypdf
- `java` + PDFBox JAR – Apache PDFBox

## Konfiguration

`config/executables.json` (Key-Value-Arrays mit `ConfigToolkit`):

```json
{
    "PDFTools": [
        {"key": "pdftotext", "value": "/usr/bin/pdftotext", "enabled": true},
        {"key": "tesseract_lang", "value": "deu+eng", "enabled": true}
    ]
}
```

## Code-Konventionen

- **declare(strict_types=1)** in jeder PHP-Datei
- **MIT-Lizenz Header** mit Autor-Info
- **final class** für konkrete Implementierungen
- **ErrorLog Trait** für alle Klassen mit Logging

## Logging

```php
use ERRORToolkit\Traits\ErrorLog;

final class MyReader implements PDFReaderInterface {
    use ErrorLog;
    
    public function extractText(string $pdfPath, array $options = []): ?string {
        $this->logDebug('Starting extraction', ['path' => $pdfPath]);
        $this->logError('Extraction failed', ['error' => $message]);
    }
}
```

## Verwendung

```php
use PDFToolkit\Registries\PDFReaderRegistry;

// Singleton-Pattern
$registry = PDFReaderRegistry::getInstance();
$document = $registry->extractText('/path/to/file.pdf', [
    'language' => 'deu+eng'
]);

if ($document->hasText()) {
    echo $document->text;
    echo "Reader: " . $document->reader;
    echo "Gescannt: " . ($document->isScanned ? 'Ja' : 'Nein');
}

// Ohne OCR-Fallback (schneller für Text-PDFs wie Kontoauszüge)
$document = $registry->extractTextOnly('/path/to/bankstatement.pdf', [
    'layout' => false  // Ohne Layout-Formatierung für bessere Regex-Extraktion
]);
```
