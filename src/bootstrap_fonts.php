<?php

declare(strict_types=1);

/**
 * Bootstrap: Font-Pfad für TCPDF 7 (tc-lib-pdf).
 *
 * Hintergrund: Ab TCPDF 7 liegt die Implementierung in tc-lib-pdf, und die
 * Font-Definitionen werden NICHT mehr mit dem Composer-Paket ausgeliefert —
 * tc-lib-pdf-font enthält nur den Quellcode, die JSON-Metriken entstehen erst
 * beim Upstream-Build (`make fonts`) und landen in den Distributionspaketen.
 * Ohne sie scheitert bereits `new TCPDF()` an "unable to read file:
 * helvetica.json".
 *
 * Dieses Toolkit liefert die benötigten Gruppen unter data/fonts/ mit (core =
 * die 14 Standard-PDF-Fonts, pdfa = eingebettete Varianten für PDF/A, das
 * ZUGFeRD verlangt) und zeigt K_PATH_FONTS darauf.
 *
 * Wird über composer.json (autoload.files) geladen, weil K_PATH_FONTS eine
 * Konstante ist und stehen muss, bevor TCPDF zum ersten Mal instanziiert wird.
 *
 * Bewusst NICHT gesetzt, wenn:
 *  - K_PATH_FONTS bereits definiert ist (Projekt-Override gewinnt),
 *  - TCPDF 6 im Einsatz ist (erkennbar an fehlendem Com\Tecnick\Pdf\Tcpdf) —
 *    dort bringt das Paket eigene Fonts in einem anderen Format mit, und ein
 *    falscher K_PATH_FONTS würde eine funktionierende Installation brechen.
 *
 * Override per Umgebungsvariable PDF_TOOLKIT_FONT_PATH.
 *
 * @see \PDFToolkit\Helper\FontDataHelper
 */

namespace PDFToolkit;

use Com\Tecnick\Pdf\Tcpdf as TcLibPdf;

(static function (): void {
    if (\defined('K_PATH_FONTS')) {
        return;
    }

    // TCPDF 6 verwaltet seine Fonts selbst — nicht eingreifen.
    if (!\class_exists(TcLibPdf::class)) {
        return;
    }

    $envPath = \getenv('PDF_TOOLKIT_FONT_PATH');
    $path = \is_string($envPath) && $envPath !== ''
        ? $envPath
        : \dirname(__DIR__) . '/data/fonts';

    if (!\is_dir($path)) {
        return;
    }

    \define('K_PATH_FONTS', \rtrim($path, '/\\'));
})();
