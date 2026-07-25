# TCPDF-7-Font-Definitionen

Diese Dateien sind **Voraussetzung für jede PDF-Erzeugung** mit TCPDF 7.

## Warum sie hier liegen

Ab TCPDF 7 liegt die Implementierung in `tc-lib-pdf`, und die Font-Metriken
werden **nicht mehr mit dem Composer-Paket ausgeliefert**. `tc-lib-pdf-font`
enthält nur den Quellcode; die JSON-Metriken entstehen erst beim Upstream-Build
(`make fonts`) und werden über Distributionspakete (deb/rpm) verteilt.

Ohne sie scheitert bereits `new TCPDF()`:

```
Com\Tecnick\Pdf\Font\Exception: unable to read file: helvetica.json
```

`src/bootstrap_fonts.php` setzt `K_PATH_FONTS` beim Autoload auf dieses
Verzeichnis. Bei TCPDF 6 unterbleibt das bewusst — dort bringt das Paket eigene
Fonts in einem anderen Format mit.

## Enthaltene Gruppen

| Gruppe | Größe | Zweck |
| --- | --- | --- |
| `core` | 164 KB | Die 14 Standard-PDF-Fonts (Helvetica, Times, Courier, Symbol, ZapfDingbats). Ohne diese Gruppe geht gar nichts. |
| `pdfa` | 620 KB | Eingebettete Varianten für PDF/A — Voraussetzung für ZUGFeRD/Factur-X. |

Nicht mitgeliefert werden `dejavu`, `freefont`, `cid0` und `unifont`
(zusammen ~40 MB). Sie werden nur für Unicode-Schriftsysteme außerhalb von
Latin-1 gebraucht; bei Bedarf nach der Anleitung unten erzeugen und über die
Umgebungsvariable `PDF_TOOLKIT_FONT_PATH` auf ein eigenes Verzeichnis zeigen.

## Neu erzeugen

Der Upstream-Weg ist `make fonts` in `tc-lib-pdf-font`. Zwei Stolpersteine:

1. **Die im Konverter hinterlegte Adobe-URL für die Core-14-AFMs ist tot**
   (`partners.adobe.com/public/developer/en/pdf/Core14_AFMs.zip` liefert HTML).
   Die Quellen kommen stattdessen aus `tecnickcom/tc-font-mirror`, das die
   AFM- und TTF-Dateien spiegelt.
2. `bulk_convert.php` erwartet eine **eigenständige** Installation von
   `tc-lib-pdf-font` (`vendor/autoload.php` innerhalb des Pakets). In einer
   verschachtelten Composer-Installation muss dorthin ein Autoloader gelegt
   werden, der auf den Projekt-Autoloader verweist.

```bash
FD=vendor/tecnickcom/tc-lib-pdf-font

# 1) Font-Quellen spiegeln (AFM + TTF, ~34 MB)
curl -sSL -o /tmp/mirror.zip \
  https://github.com/tecnickcom/tc-font-mirror/archive/refs/tags/2.2.0.zip
unzip -q /tmp/mirror.zip -d /tmp
mkdir -p "$FD/util/vendor/tecnickcom"
cp -r /tmp/tc-font-mirror-2.2.0 "$FD/util/vendor/tecnickcom/tc-font-mirror"

# 2) Autoloader-Brücke für das Konverterskript
mkdir -p "$FD/vendor"
printf '<?php\nrequire "%s/vendor/autoload.php";\n' "$(pwd)" > "$FD/vendor/autoload.php"

# 3) Konvertieren (erzeugt 71 Fonts nach target/fonts/)
(cd "$FD/util" && php bulk_convert.php)

# 4) Benötigte Gruppen übernehmen
cp -r "$FD"/target/fonts/{core,pdfa} data/fonts/

# 5) Aufräumen — vendor/ wieder in den Auslieferungszustand
rm -rf "$FD/vendor" "$FD/util/vendor" "$FD/target"
```

Erwartete Ausgabe in Schritt 3: `PROCESS COMPLETED: 71 CONVERTED FONT(S), 0 ERROR(S)!`

## Prüfen

```php
use PDFToolkit\Helper\FontDataHelper;

FontDataHelper::isAvailable();      // Standard-Fonts nutzbar
FontDataHelper::isPdfaAvailable();  // PDF/A (ZUGFeRD) möglich
FontDataHelper::getActiveFontPath();
```

## Lizenzen

Die Lizenzangaben der Quellen liegen als `LICENSE`/`README` in den jeweiligen
Gruppenverzeichnissen und stammen unverändert aus `tc-font-mirror`.
