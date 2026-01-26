# PHP PDF Toolkit

A PHP 8.2+ library for extracting text from PDF documents and creating PDFs with intelligent reader/writer selection.

## Features

### PDF Text Extraction (Readers)

- **Multiple PDF Readers** with automatic fallback:
  - `pdftotext` (poppler-utils) - Fast extraction for text-based PDFs
  - `PDFBox` (Apache, Java) - Better handling of complex layouts
  - `Tesseract` - OCR for scanned documents
  - `OCRmyPDF` - High-quality OCR with preprocessing

- **Automatic Reader Selection** - Tries text extraction first, falls back to OCR if needed
- **Caching** - Extracted text is cached to avoid redundant processing
- **Language Support** - Configurable OCR languages (German + English by default)

### PDF Creation (Writers)

- **Multiple PDF Writers** with automatic fallback:
  - `Dompdf` - HTML to PDF conversion (pure PHP, LGPL)
  - `TCPDF` - Programmatic PDF creation (pure PHP, LGPL)
  - `wkhtmltopdf` - High-quality HTML rendering via WebKit (external tool)

- **Automatic Writer Selection** - Uses the first available writer by priority
- **Multiple Input Formats** - HTML, plain text, or HTML files
- **Metadata Support** - Title, author, subject for generated PDFs

## Requirements

- PHP 8.2+

### For Text Extraction (at least one)

- `pdftotext` (`apt install poppler-utils`)
- `tesseract-ocr` (`apt install tesseract-ocr tesseract-ocr-deu`)
- `ocrmypdf` (`apt install ocrmypdf`)
- Java + PDFBox JAR (optional)

### For PDF Creation (at least one)

- `dompdf/dompdf` (`composer require dompdf/dompdf`)
- `tecnickcom/tcpdf` (`composer require tecnickcom/tcpdf`)
- `wkhtmltopdf` (`apt install wkhtmltopdf`)

## Installation

### Via Composer

```bash
composer require daniel-jorg-schuppelius/php-pdf-toolkit
```

### Clone with Submodules

```bash
git clone --recurse-submodules https://github.com/Daniel-Jorg-Schuppelius/php-pdf-toolkit.git
```

Or if already cloned:

```bash
git submodule update --init
```

### Install System Dependencies

Use the included install script for system dependencies:

```bash
# Install PDF extraction tools (poppler-utils, tesseract, ocrmypdf)
sudo ./installscript/install-dependencies.sh
```

### Install PHP Libraries for PDF Creation

```bash
# Dompdf (recommended, pure PHP)
composer require dompdf/dompdf

# Or TCPDF (alternative, pure PHP)
composer require tecnickcom/tcpdf

# Or wkhtmltopdf (external tool, best quality)
sudo apt install wkhtmltopdf
```

## Usage

### Text Extraction

```php
use PDFToolkit\Registries\PDFReaderRegistry;

$registry = new PDFReaderRegistry();
$document = $registry->extractText('/path/to/file.pdf', [
    'language' => 'deu+eng'
]);

if ($document->hasText()) {
    echo $document->text;
    echo "Reader: " . $document->reader;
    echo "Scanned: " . ($document->isScanned ? 'Yes' : 'No');
}
```

### PDF Creation

```php
use PDFToolkit\Registries\PDFWriterRegistry;
use PDFToolkit\Entities\PDFContent;

$registry = new PDFWriterRegistry();

// Simple: HTML to PDF
$registry->htmlToPdf('<h1>Hello World</h1><p>Content</p>', '/path/to/output.pdf');

// Simple: Text to PDF
$registry->textToPdf('Plain text content', '/path/to/output.pdf');

// Advanced: With metadata and options
$content = PDFContent::fromHtml($html, [
    'title' => 'My Document',
    'author' => 'John Doe',
    'subject' => 'Example PDF'
]);

$registry->createPdf($content, '/path/to/output.pdf', [
    'paper_size' => 'A4',
    'orientation' => 'portrait',
    'margins' => ['top' => 15, 'bottom' => 15, 'left' => 15, 'right' => 15]
]);

// Use specific writer
$registry->createPdf($content, '/path/to/output.pdf', [], 'dompdf');

// Get PDF as string (for download/streaming)
$pdfString = $registry->createPdfString($content);
header('Content-Type: application/pdf');
echo $pdfString;
```

### Check Available Tools

```php
// Readers
$readerRegistry = new PDFReaderRegistry();
foreach ($readerRegistry->getReaderInfo() as $info) {
    echo "{$info['name']}: " . ($info['available'] ? '✓' : '✗') . "\n";
}

// Writers
$writerRegistry = new PDFWriterRegistry();
foreach ($writerRegistry->getWriterInfo() as $info) {
    echo "{$info['name']}: " . ($info['available'] ? '✓' : '✗') . "\n";
}
```

## Configuration

Tool paths can be configured in `config/executables.json`:

```json
{
    "shellExecutables": {
        "pdftotext": {
            "path": "/usr/bin/pdftotext",
            "required": true
        },
        "wkhtmltopdf": {
            "path": "/usr/bin/wkhtmltopdf",
            "required": false
        }
    }
}
```

## Architecture

```text
PDFReaderRegistry → [Readers by Priority] → PDFDocument
                          ↓
              PdftotextReader (10)     # Fast, for text PDFs
              PdfboxReader (30)        # Complex layouts
              TesseractReader (50)     # OCR for scans
              OcrmypdfReader (60)      # Best OCR quality

PDFWriterRegistry → [Writers by Priority] → PDF File
                          ↓
              DompdfWriter (10)        # HTML→PDF, pure PHP
              TcpdfWriter (20)         # Programmatic, pure PHP
              WkhtmltopdfWriter (30)   # Best HTML rendering
```

## License

MIT License - see [LICENSE](LICENSE) file.
