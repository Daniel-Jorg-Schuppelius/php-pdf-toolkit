# PHP PDF Toolkit

A PHP 8.2+ library for extracting text from PDF documents with intelligent reader selection.

## Features

- **Multiple PDF Readers** with automatic fallback:
  - `pdftotext` (poppler-utils) - Fast extraction for text-based PDFs
  - `PDFBox` (Apache, Java) - Better handling of complex layouts
  - `Tesseract` - OCR for scanned documents
  - `OCRmyPDF` - High-quality OCR with preprocessing

- **Automatic Reader Selection** - Tries text extraction first, falls back to OCR if needed
- **Caching** - Extracted text is cached to avoid redundant processing
- **Language Support** - Configurable OCR languages (German + English by default)

## Requirements

- PHP 8.2+
- At least one of the following tools:
  - `pdftotext` (`apt install poppler-utils`)
  - `tesseract-ocr` (`apt install tesseract-ocr tesseract-ocr-deu`)
  - `ocrmypdf` (`apt install ocrmypdf`)
  - Java + PDFBox JAR (optional)

## Installation
