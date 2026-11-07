# Tesseract Training Data

Place your Tesseract trained data files (`.traineddata`) in this directory.
`TesseractDataHelper` downloads missing languages here on demand.

## Download

Download from: <https://github.com/tesseract-ocr/tessdata>

Common languages:

- `eng.traineddata` - English
- `deu.traineddata` - German

## Config files (shipped)

`configs/`, `tessconfigs/` and `pdf.ttf` come from the Tesseract project
(<https://github.com/tesseract-ocr/tesseract/tree/main/tessdata>, Apache-2.0)
and are part of this package on purpose: whenever `TESSDATA_PREFIX` points
to this directory, Tesseract looks up its parameter files here, not in the
system directory. `ocrmypdf` passes the names `pdf txt` (sandwich renderer)
or `hocr txt` (hOCR renderer) on the command line; without
`configs/pdf`, `configs/txt` and `configs/hocr` Tesseract prints
`read_params_file: Can't open pdf` and writes no PDF or hOCR at all.
`TesseractDataHelper::ensureConfigs()` copies them into any other data
directory that is used as `TESSDATA_PREFIX`.

## Custom models (since 0.30.0)

Any `<name>.traineddata` in this directory (or in the configured
`tesseract_data_path`) is accepted as a language code, even when it is not in
`TesseractDataHelper::SUPPORTED_LANGUAGES` - for example a fine-tuned
`deu_finetuned.traineddata`. Such models are never downloaded; the file has to
be put here. The name must match what tessdata itself uses (lowercase letters,
digits, underscore).

To use a custom model without changing callers, map the language code to the
model in `config/settings.json`:

```json
{"key": "tesseract_models", "value": {"deu": "deu_finetuned"}, "type": "json", "enabled": true}
```

`TesseractDataHelper::resolveModels('deu+eng')` then yields `deu_finetuned+eng`
wherever the language reaches Tesseract (text path, row-aligned OCR, word
boxes, OCRmyPDF) and in the OCR cache keys. A mapped model that is missing is
reported once and the standard model stays in use.

## Usage

If you place files here, set the `tesseract_data_path` config option to this directory.

Otherwise, Tesseract will use the system default (`/usr/share/tesseract-ocr/*/tessdata/`).
