// Zerlegt die Seiten einer PDF in ein Raster aus gleich grossen Teilen.
//
// Aufruf (mutool run):
//   grid-split.js <lib.js> <eingabe> <ausgabe> <zeilen> <spalten> <von> <bis>
//                 <rand-oben> <rand-rechts> <rand-unten> <rand-links> <single|separate>
//
// Jeder Teil wird eine eigene Seite, die denselben Inhaltsstrom wie die
// Originalseite nutzt und nur einen anderen Seitenrahmen bekommt - nichts
// wird neu gerendert, Text bleibt Text. Reihenfolge: Seite fuer Seite, je
// Seite zeilenweise von oben nach unten und links nach rechts, so wie die
// Seite angezeigt wird (/Rotate ist beruecksichtigt). Raender in Punkten,
// ebenfalls in Anzeigeausrichtung. Seiten ausserhalb von <von>..<bis>
// bleiben unveraendert an ihrer Stelle.
//
// single:   <ausgabe> ist die Ziel-PDF.
// separate: <ausgabe> ist ein Verzeichnis; jede Seite des Ergebnisses wird
//           eine eigene Datei 000001.pdf, 000002.pdf, ...

var SCRIPT = "grid-split";
load(scriptArgs[0]);

var BOXES = ["MediaBox", "CropBox", "BleedBox", "TrimBox", "ArtBox"];

// Punkt in Anzeigekoordinaten (u nach rechts, v nach unten, Ursprung oben
// links der angezeigten Seite) in PDF-Koordinaten des Seitenrahmens
function toUser(box, angle, u, v) {
    switch (angle) {
        case 90: return [box[0] + v, box[1] + u];
        case 180: return [box[2] - u, box[1] + v];
        case 270: return [box[2] - v, box[3] - u];
        default: return [box[0] + u, box[3] - v];
    }
}

function cellPages(doc, page, rows, cols, margins) {
    var box = visibleBox(page);
    var angle = rotation(page);
    var turned = angle === 90 || angle === 270;
    var width = turned ? box[3] - box[1] : box[2] - box[0];
    var height = turned ? box[2] - box[0] : box[3] - box[1];

    var usableWidth = width - margins.left - margins.right;
    var usableHeight = height - margins.top - margins.bottom;
    if (usableWidth <= 1 || usableHeight <= 1) fail("Rand ist groesser als die Seite");

    var cellWidth = usableWidth / cols;
    var cellHeight = usableHeight / rows;
    var result = [];

    for (var row = 0; row < rows; row++) {
        for (var col = 0; col < cols; col++) {
            var u0 = margins.left + col * cellWidth;
            var v0 = margins.top + row * cellHeight;
            var a = toUser(box, angle, u0, v0);
            var b = toUser(box, angle, u0 + cellWidth, v0 + cellHeight);
            var rect = [Math.min(a[0], b[0]), Math.min(a[1], b[1]), Math.max(a[0], b[0]), Math.max(a[1], b[1])];

            var cell = copyPage(doc, page, false);
            for (var i = 0; i < BOXES.length; i++) cell.delete(BOXES[i]);
            cell.put("MediaBox", rect);
            cell.put("CropBox", rect);
            if (angle !== 0) cell.put("Rotate", angle);
            result.push(cell);
        }
    }

    return result;
}

if (args.length < 11) fail("zu wenige Argumente");

var input = args[0];
var output = args[1];
var rows = intArg(2, "Zeilen");
var cols = intArg(3, "Spalten");
var first = intArg(4, "Von-Seite");
var last = intArg(5, "Bis-Seite");
var margins = {
    top: floatArg(6, "Rand oben"),
    right: floatArg(7, "Rand rechts"),
    bottom: floatArg(8, "Rand unten"),
    left: floatArg(9, "Rand links"),
};
var separate = args[10] === "separate";

if (rows < 1 || cols < 1 || rows * cols < 2) fail("mindestens zwei Teile noetig");

var doc = openPdf(input);

var count = doc.countPages();
if (first < 1) first = 1;
if (last < 1 || last > count) last = count;
if (first > last) fail("Seitenbereich " + first + "-" + last + " liegt ausserhalb der " + count + " Seiten");

// Neue Seitenfolge aufbauen, dann die Originale aus dem Seitenbaum nehmen
var pages = [];
for (var index = 0; index < count; index++) {
    var page = doc.findPage(index);
    if (index + 1 >= first && index + 1 <= last) {
        pages = pages.concat(cellPages(doc, page, rows, cols, margins));
    } else {
        pages.push(copyPage(doc, page, true));
    }
}

for (var p = 0; p < pages.length; p++) doc.insertPage(-1, doc.addObject(pages[p]));
for (var d = 0; d < count; d++) doc.deletePage(0);

if (separate) {
    for (var n = 0; n < pages.length; n++) {
        var single = new PDFDocument();
        if (typeof single.graftPage === "function") {
            single.graftPage(-1, doc, n);
        } else {
            // MuPDF 1.17: Kopie ohne Parent und Anmerkungen uebertragen - deren
            // Verweis auf die Ursprungsseite zoege sonst das ganze Dokument mit
            single.insertPage(-1, single.addObject(single.graftObject(copyPage(doc, doc.findPage(n), false))));
        }
        single.save(output + "/" + padNumber(n + 1, 6) + ".pdf", "garbage,compress");
    }
} else {
    doc.save(output, "garbage,compress");
}

print(pages.length);
