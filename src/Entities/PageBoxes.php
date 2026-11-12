<?php
/*
 * Created on   : Thu Nov 12 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PageBoxes.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Entities;

/**
 * MediaBox und CropBox einer Seite (seit 0.32.1), aus `pdfinfo -box`.
 *
 * pdfinfo nennt als Seitengröße die CropBox, die sichtbare Seite - sie liefert
 * auch {@see \PDFToolkit\Helper\PDFHelper::getAllPageSizes()}. pdftoppm rastert
 * ohne -cropbox aber die MediaBox: Eine PDF mit A4-CropBox und 9000 x 9000 pt
 * MediaBox besteht jede Prüfung der Seitengröße und kostet beim Rastern
 * Gigabytes. Für Grenzen vor dem Rastern zählt deshalb {@see largest()}.
 *
 * Beide Boxen tragen die Drehung der Seite ({@see PageSize::displayWidthPt()}).
 */
final readonly class PageBoxes {
    /**
     * @param int $pageNumber Seitennummer (1-basiert)
     * @param PageSize $mediaBox Breite und Höhe der MediaBox in Points
     * @param PageSize $cropBox Breite und Höhe der CropBox in Points (poppler beschneidet sie auf die MediaBox)
     * @param int $rotation Anzeigedrehung aus /Rotate (0, 90, 180, 270)
     */
    public function __construct(
        public int $pageNumber,
        public PageSize $mediaBox,
        public PageSize $cropBox,
        public int $rotation = 0,
    ) {}

    /**
     * Größere der beiden Boxen je Kante, mit der Drehung der Seite: so groß
     * wird die Seite höchstens gerastert, mit oder ohne -cropbox. Für
     * Seitenmaß-Prüfungen vor dem Rastern (statt der CropBox allein).
     */
    public function largest(): PageSize {
        return new PageSize(
            widthPt: max($this->mediaBox->widthPt, $this->cropBox->widthPt),
            heightPt: max($this->mediaBox->heightPt, $this->cropBox->heightPt),
            pageNumber: $this->pageNumber,
            rotation: $this->rotation,
        );
    }
}
