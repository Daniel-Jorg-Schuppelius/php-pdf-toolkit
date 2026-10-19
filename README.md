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
- **Stationery Overlay** - `StationeryOverlayWriter` places a letterhead PDF as a layer *under* imported content pages, with separate assets for the first and following pages

### Additional Helpers

- **Word boxes** - `PDFWordBoxHelper` and `PDFTextProvider::wordBoxes()` return every word with its rectangle relative to the page size (0–1), from the text layer (`pdftotext -bbox-layout`) or via OCR (Tesseract TSV on rendered pages, shared pipeline `TesseractPageOcr`); the basis for zone-based extraction such as "the invoice number of this supplier sits top right" (since 0.27.0)
- **Image OCR** - `TesseractReader::extractTextFromImage()` runs OCR on a single image file instead of a PDF; the image is only deskewed when it is skewed by at least `TesseractReader::IMAGE_DESKEW_MIN_ANGLE` (0.5 degrees), because resampling a straight scan blurs thin characters such as the commas in amounts (needs `convert` for the measurement, otherwise every image is deskewed as before)
- **Searchable PDF** - `PDFOcrHelper::makeSearchable()` puts an invisible OCRmyPDF text layer over scanned pages (optionally with the recognised text as a sidecar file); it is the one OCRmyPDF path of the package, `OcrMyPDFReader` uses it as well (see [Searchable PDF and OCRmyPDF](#searchable-pdf-and-ocrmypdf-since-0250))
- **Encrypted Layers** - `CipherLayerSolver` resolves encrypted content layers so that text extraction also works on protected documents
- **Broken ToUnicode Tables** - `GlyphNameLayer` (via `PDFTextProvider::glyphNameText()`) rebuilds the text layer from the fonts' glyph names, so a PDF whose ToUnicode table maps every glyph to the wrong character still yields its exact text - no OCR, no guessing. Needs `mutool` (mupdf-tools)
- **Pages** - `PDFPageHelper` arranges pages (order, rotation per page, drop, duplicate, blank pages), rotates, puts several pages on one sheet (`nup`, 1-16, optional frame; 1 per sheet resizes), stamps page numbers and text watermarks (`stamp`), renders thumbnails and page images, extracts embedded images (`extractImages`, pdfimages); `PDFCropHelper::cropToGrid()` splits every page into a grid of parts. All page operations keep the content streams - nothing is rasterised, text stays text. Needs `mutool`; the scripts under `data/mupdf/` run with MuPDF 1.17, 1.21 and 1.25
- **Security** - `PDFSecurityHelper` probes, sets (AES-256 with permissions) and removes password protection. Passwords never travel on the command line. Needs `mutool`
- **Optimize** - `PDFOptimizeHelper` shrinks PDFs (`strong` via Ghostscript, `lossless` via OCRmyPDF's optimizer, `custom` with resolution and JPEG quality), repairs them (`clean`, rewrite without object streams) and converts to PDF/A-2b
- **PDF/A compliance** - `PDFAComplianceHelper::verify()` checks a file against ISO 19005 (the level declared in its XMP, else 2b) and returns the verdict with the violated clauses. veraPDF is the reference checker; PdfCraft CLI is the fallback without Java (2b/3b only, fewer rules), and the result always names the engine. Both are optional and self-installing via `installscript/install-dependencies.sh` (the `install` objects in `config/executables.json`)
- **Metadata** - `PDFMetadataHelper` sets or clears the Info dictionary (and drops stale XMP); reading stays in `PDFHelper::getMetadata()`
- **Bookmarks** - `PDFOutlineHelper::set()` replaces the outline with one level of bookmarks (page and title, in page order) and opens the file with the bookmark pane. Needs `mutool`
- **Attachments** - `PDFAttachmentHelper` lists and extracts embedded files (`pdfdetach`, poppler) and embeds files (`attach`, EmbeddedFiles name tree; a file of the same name is replaced). The ZUGFeRD invoice XML keeps its own reader
- **Sanitize** - `PDFSanitizeHelper::flatten()` burns form fields and annotations into the page content (Ghostscript), `sanitize()` removes annotations, links, forms, JavaScript and actions, or embedded files (`mutool`). Content streams stay untouched
- **Redaction** - `PDFRedactHelper::findText()` locates search terms per page from `pdftotext -bbox-layout` (word boxes in display space, or the whole visual line across all columns) and `redact()` removes text and image pixels under the boxes for real (MuPDF redaction annotations). Image pixels need MuPDF 1.19 or newer; older versions are refused for pages with images instead of covering them. `isAvailable()` also runs a probe (`data/redact-probe.pdf`, two words in one `TJ` array): MuPDF 1.23 leaves the second word in place, so redaction reports itself unavailable there instead of leaking text. Needs `mutool` and `pdftotext`
- **Barcodes** - `BarcodeHelper` reads bar and QR codes on an image or on the rendered pages of a PDF (`zbarimg` from zbar-tools, optional); separator sheets with a barcode are the scanning standard in tax offices
- **OCR languages** - `TesseractDataHelper::SUPPORTED_LANGUAGES` is the allow-list for language codes that reach Tesseract and the tessdata download

## Requirements

- PHP 8.2+

### For Text Extraction (at least one)

- `pdftotext` (`apt install poppler-utils`)
- `tesseract-ocr` (`apt install tesseract-ocr tesseract-ocr-deu`)
- `ocrmypdf` (`apt install ocrmypdf`)
- `mutool` (`apt install mupdf-tools`) - optional, needed for `glyphNameText()` on PDFs with broken ToUnicode tables and for all page and security helpers
- `gs` (`apt install ghostscript`) - optional, cropping and strong compression
- Java + PDFBox JAR (optional)
- `verapdf` (optional, PDF/A compliance; veraPDF 1.30 from software.verapdf.org, needs Java 8+) or `pdfcraft-cli` (optional fallback, glibc 2.35+) - both installed by `installscript/install-dependencies.sh`

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

$registry = PDFReaderRegistry::getInstance();
$document = $registry->extractText('/path/to/file.pdf', [
    'language' => 'deu+eng'
]);

if ($document->hasText()) {
    echo $document->text;
    echo "Reader: " . $document->reader;
    echo "Scanned: " . ($document->isScanned ? 'Yes' : 'No');
}

// Ohne OCR-Fallback (schneller für Text-PDFs wie Kontoauszüge)
$document = $registry->extractTextOnly('/path/to/bankstatement.pdf', [
    'layout' => false  // Ohne Layout-Formatierung für bessere Regex-Extraktion
]);
```

### PDF Creation

```php
use PDFToolkit\Registries\PDFWriterRegistry;
use PDFToolkit\Entities\PDFContent;

$registry = PDFWriterRegistry::getInstance();

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
$readerRegistry = PDFReaderRegistry::getInstance();
foreach ($readerRegistry->getReaderInfo() as $info) {
    echo "{$info['name']}: " . ($info['available'] ? '✓' : '✗') . "\n";
}
}

// Writers
$writerRegistry = PDFWriterRegistry::getInstance();
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

### Time Limits

Every external tool runs through `CommonToolkit\Helper\Shell` with a time limit (php-common-toolkit ≥ 2.9). The limits live in one place, `PDFToolkit\Helper\ToolTimeout`:

| Class | Limit | Used for |
| ----- | ----- | -------- |
| `PROBE` | 30 s | `pdfinfo`, `pdfdetach -list`, password probe, `--version` |
| `PAGE` | 120 s | one page: `pdftoppm` of a single page, first-page text probe, `tesseract`/`mogrify` per page |
| `forSize()` | 180 s + 10 s per MB | whole-document text and page work: `pdftotext`, pdfbox, MuPDF scripts, `pdftk`, poppler split/merge, Ghostscript |
| `forRendering()` | 180 s + 10 s per page | `pdftoppm`/`pdfimages` over many pages, `mutool draw -F trace` |
| `forOcr()` | 180 s + 60 s per page | OCRmyPDF (also its optimizer and PDF/A, which OCR scanned pages via `--skip-text`) |
| `VERAPDF` | 300 s | veraPDF (PDF/A compliance) |
| `PDFCRAFT` | 120 s | PdfCraft CLI (PDF/A fallback) |

Scaled limits are capped at 4 h (`ToolTimeout::MAX`) and never drop below `Shell::getDefaultTimeout()`, so `Shell::setDefaultTimeout(…)` in a worker raises them for long OCR runs. When a limit is hit, the whole process tree is killed and the call reports a failure — OCR never returns the text of the remaining pages as a result; the optimizer and single-page rendering also remove a half-written output file. `PDFOptimizeHelper::compress()` tells a failure (including a time-out) apart from "nothing saved" through its optional by-reference parameter `$failed`.

`phpstan.neon.dist` includes the common-toolkit rule that reports direct process calls (`exec`, `proc_open`, backticks, Symfony Process …) outside `Shell`.

### Searchable PDF and OCRmyPDF (since 0.25.0)

`PDFOcrHelper::makeSearchable()` is the one OCRmyPDF path of the package: applications use it for a searchable PDF (an invisible text layer over the page images), `OcrMyPDFReader` uses it with `--sidecar` to read the recognised text. Language data (`TESSDATA_PREFIX` with `configs/`), one Tesseract thread per call and the time limit `ToolTimeout::forOcr()` are set in this one place. All four entries in `config/executables.json` (`ocrmypdf`, `ocrmypdf-sidecar`, `ocrmypdf-force`, `ocrmypdf-force-sidecar`) carry the same switches and differ only in `--skip-text`/`--force-ocr` and the sidecar file; `PDFOcrHelperTest` keeps it that way.

| Switch | Why |
| ------ | --- |
| `--rotate-pages --rotate-pages-threshold 5` | Turns pages that were scanned sideways or upside down before recognition. Tesseract's orientation detection names the right orientation reliably, but with low confidences: on 8 real scanned statement pages turned by 90/180/270 degrees OCRmyPDF reported 1.4 to 14.0 and, at its default threshold of 14, turned none of the 24 pages; at 5 it turns 22. Over 106 catalogue pages (first and last pages, 387 orientations) the detected orientation was right every time, and no upright page would have been turned at any threshold down to 2. |
| `--deskew` | Straightens skewed pages, in the output as well. |
| no `--clean` | unpaper only cleans the image handed to Tesseract. On the scans measured it found no additional amount, cost 20 to 50 % more CPU time and needs `unpaper`, which is only a recommended package of OCRmyPDF. |

Measured on 14.10.2026 on the scanned bank statements of the conversion catalogue: how many booking amounts of the converted statement appear in the text layer, and the CPU time. All four variants (no switch, the former application setting `--deskew --rotate-pages`, the former reader setting `--deskew --clean`, all three) found the same amounts within one or two per document (upright pages). `--deskew --rotate-pages` cost 35 to 70 % more CPU time than no switch, `--clean` another 20 to 50 % on top (six scans, 4 to 8 pages each). The page segmentation mode stays as in v1: `ocrmypdf_psm` (11), 12 for pages that are not A4.

### Temporary Files (since 0.25.0)

Rendered pages, OCR output and extracted text are document content. All work files of the tool calls go through `TempPath`: directories `0700`, files created exclusively with `0600`, names from `random_bytes` instead of `uniqid()`. A tool that writes into a pre-created file only truncates it, so the permissions stay. The cross-process OCR cache (`OcrCache`, default `<temp>/pdftoolkit_ocr`) is created with `0700` and its entries with `0600`; if the default directory belongs to another user, the cache switches itself off instead of reading or writing there. Since 0.25.3 entries live 24 hours by default (`PDFSettings.ocr_cache_ttl`, seconds) and expired entries are removed while writing (at most once per hour per process) or with `OcrCache::prune()` - before, an entry was only deleted when the same file was read again, so a document converted once stayed on disk. Tools that want a longer cache (e.g. a catalog test run) call `OcrCache::configure(ttl: ..., directory: ...)` for their own process and directory. `ZugferdReader` no longer builds the output path from the attachment name inside the PDF.

### Hardening of Ghostscript and ImageMagick (since 0.24.1)

The input PDFs usually come from third parties, so the two programs that can execute or load more than the page content are restricted explicitly:

- **Ghostscript** - all Ghostscript commands in `config/executables.json` (`gs-crop`, `gs-resize`, `gs-compress`, `gs-compress-custom`) pass `-dSAFER` as their first argument instead of relying on the default of the installed version. If you override one of these commands in your own configuration, keep `-dSAFER`.
- **ImageMagick** - the deskew before OCR (`mogrify-deskew`, `mogrify-deskew-denoise` in `TesseractReader`) runs with `MAGICK_CONFIGURE_PATH` pointing to `config/imagemagick/` of this package. Its `policy.xml` only adds restrictions: no delegates, no `@file` arguments, no file, network or script coders (`URL`, `FILE`, `HTTP`, `HTTPS`, `FTP`, `EPHEMERAL`, `MSL`, `TEXT`, `LABEL`, `CAPTION`, `PANGO`, `SCRIPT`), and the resource limits of the Debian default policy. ImageMagick applies it together with the server's own policy, so the restrictions hold even where the system policy allows everything. mogrify only sees PNG pages that `pdftoppm` rendered itself; the raster coders stay available. `config/imagemagick/` is part of the Composer package and must be deployed with it - without the folder, mogrify runs under the server policy alone.

## Architecture

```text
PDFReaderRegistry → [Readers by Priority] → PDFDocument
                          ↓
              PDFToTextReader (10)     # Fast, for text PDFs
              PDFBoxReader (30)        # Complex layouts
              TesseractReader (50)     # OCR for scans
              OcrMyPDFReader (60)      # Best OCR quality

PDFWriterRegistry → [Writers by Priority] → PDF File
                          ↓
              DompdfWriter (10)        # HTML→PDF, pure PHP
              TcpdfWriter (20)         # Programmatic, pure PHP
              WkhtmltopdfWriter (30)   # Best HTML rendering
```

## License

AGPL-3.0-or-later - see [LICENSE](LICENSE) file.

## Versions

Releases are tagged in Git; `git tag --sort=-v:refname` lists them (latest: v0.25.3). There is no separate changelog file — the tags and the commit history are the record.

The CI runs the MuPDF-based tests against MuPDF 1.17 and 1.21 as well (`scripts/ci/mupdf-alt.sh`, packages from the Debian pool with pinned checksums), because servers often run an older MuPDF than the development machine.
