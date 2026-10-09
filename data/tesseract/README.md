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

## Usage

If you place files here, set the `tesseract_data_path` config option to this directory.

Otherwise, Tesseract will use the system default (`/usr/share/tesseract-ocr/*/tessdata/`).
