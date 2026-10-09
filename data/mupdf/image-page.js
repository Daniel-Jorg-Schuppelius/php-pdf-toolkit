// Legt Bilder je auf eine Seite eines festen Blatts, ohne sie neu zu kodieren.
//
// Aufruf (mutool run):
//   image-page.js <lib.js> <ausgabe> <blatt> <randMm> <ausrichtung> <bild1> [bild2 ...]
//
// <blatt>       a3, a4, a5, letter, legal
// <randMm>      Rand rundum in Millimetern
// <ausrichtung> auto (Blatt dreht sich nach dem Bild), portrait oder landscape
//
// Jedes Bild wird gleichmaessig so verkleinert, dass es in den Bereich
// innerhalb des Rands passt, und darin zentriert; ein kleines Bild wird nicht
// vergroessert ueber seine Groesse bei 150 dpi hinaus. Die Bilddaten (JPEG,
// PNG, ...) uebernimmt MuPDF unveraendert in die PDF.

var SCRIPT = "image-page";
load(scriptArgs[0]);

if (args.length < 5) fail("zu wenige Argumente");

var output = args[0];
var sheetName = args[1];
var margin = mmToPt(floatArg(2, "Rand"));
var orientation = String(args[3]).toLowerCase();
var files = args.slice(4);

var out = new PDFDocument();

for (var i = 0; i < files.length; i++) {
    var image = new Image(files[i]);
    var iw = image.getWidth(), ih = image.getHeight();
    if (iw < 1 || ih < 1) fail("Bild ohne Masse: " + files[i]);

    var landscape = orientation === "landscape" ? true : orientation === "portrait" ? false : iw > ih;
    var sheet = sheetSize(sheetName, landscape);
    var areaWidth = sheet[0] - 2 * margin, areaHeight = sheet[1] - 2 * margin;
    if (areaWidth <= 1 || areaHeight <= 1) fail("Rand ist groesser als das Blatt");

    // Bei 150 dpi entspricht ein Pixel 72/150 Punkt; darueber hinaus nicht vergroessern
    var natural = 72 / 150;
    var scale = Math.min(areaWidth / iw, areaHeight / ih, natural);
    var w = iw * scale, h = ih * scale;
    var x = margin + (areaWidth - w) / 2, y = margin + (areaHeight - h) / 2;

    var resources = out.newDictionary();
    var xobjects = out.newDictionary();
    xobjects.put("Im0", out.addImage(image));
    resources.put("XObject", xobjects);
    var content = "q " + w + " 0 0 " + h + " " + x + " " + y + " cm /Im0 Do Q";
    out.insertPage(-1, out.addPage([0, 0, sheet[0], sheet[1]], 0, resources, content));
}

out.save(output, "garbage,compress");
print(files.length);
