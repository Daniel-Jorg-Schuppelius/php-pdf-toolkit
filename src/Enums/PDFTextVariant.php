<?php
/*
 * Created on   : Sat Apr 12 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PDFTextVariant.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace PDFToolkit\Enums;

/**
 * Enum für die Standard-Extraktionsvarianten des PDFTextProvider.
 *
 * Wird für typsichere Cache-Prüfung via `isCached()` verwendet.
 * Dynamische Varianten (OCR mit Sprache, Quality-Check, Reader-spezifisch)
 * werden weiterhin als String-Keys verwaltet.
 */
enum PDFTextVariant: string {
    /** Standard-Extraktion (schnellster Reader, mit OCR-Fallback) */
    case Default = 'default';

    /** Layout-Erhaltung (pdftotext -layout, Spaltenausrichtung) */
    case Layout = 'layout';

    /** Ohne Layout (Fließtext, ideal für Regex) */
    case Raw = 'raw';

    /** Nur Text-Reader, kein OCR-Fallback */
    case TextOnly = 'textonly';
}
