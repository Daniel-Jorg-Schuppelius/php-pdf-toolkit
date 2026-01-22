# Tesseract Training Data

Place your Tesseract trained data files (`.traineddata`) in this directory.

## Download

Download from: <https://github.com/tesseract-ocr/tessdata>

Common languages:

- `eng.traineddata` - English
- `deu.traineddata` - German

## Usage

If you place files here, set the `tesseract_data_path` config option to this directory.

Otherwise, Tesseract will use the system default (`/usr/share/tesseract-ocr/*/tessdata/`).
