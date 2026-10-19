<?php
/*
 * Created on   : Mon Oct 19 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : WordBox.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Entities;

/**
 * Ein Wort mit seinem Rechteck auf der Seite (seit 0.27.0). Koordinaten
 * relativ zur Seitengröße (0–1, Ursprung oben links), damit Zonen für den
 * Textlayer (Punkt) und für OCR (Pixel bei beliebigem DPI) dasselbe treffen.
 */
final class WordBox {
    public function __construct(
        public readonly int $page,
        public readonly float $x,
        public readonly float $y,
        public readonly float $w,
        public readonly float $h,
        public readonly string $text,
        public readonly ?float $confidence = null,
    ) {}

    public function centerX(): float {
        return $this->x + $this->w / 2;
    }

    public function centerY(): float {
        return $this->y + $this->h / 2;
    }

    /** Liegt der Mittelpunkt des Wortes in der Zone (relative Koordinaten)? */
    public function centerWithin(float $x, float $y, float $w, float $h): bool {
        $cx = $this->centerX();
        $cy = $this->centerY();

        return $cx >= $x && $cx <= $x + $w && $cy >= $y && $cy <= $y + $h;
    }

    /** @return array{page: int, x: float, y: float, w: float, h: float, text: string, confidence: ?float} */
    public function toArray(): array {
        return ['page' => $this->page, 'x' => $this->x, 'y' => $this->y, 'w' => $this->w, 'h' => $this->h, 'text' => $this->text, 'confidence' => $this->confidence];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self {
        return new self(
            (int) ($data['page'] ?? 1),
            (float) ($data['x'] ?? 0),
            (float) ($data['y'] ?? 0),
            (float) ($data['w'] ?? 0),
            (float) ($data['h'] ?? 0),
            (string) ($data['text'] ?? ''),
            isset($data['confidence']) && is_numeric($data['confidence']) ? (float) $data['confidence'] : null,
        );
    }
}
