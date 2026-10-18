// Bereiche einer PDF schwaerzen: Text und Bildpixel unter dem Rechteck
// werden entfernt, nicht nur ueberdeckt (Redact-Anmerkung + applyRedactions).
//
// Aufruf (mutool run):
//   pdf-redact.js <lib.js> <eingabe> <ausgabe> <datei>
//
// Die Datei traegt je Zeile "Seite<TAB>x0<TAB>y0<TAB>x1<TAB>y1" in Punkten
// (1/72 Zoll) im Seitenraum der Anzeige: Ursprung oben links, y nach unten,
// Drehung der Seite bereits angewandt - so wie pdftotext -bbox die Woerter
// liefert. Seite 1-basiert.
//
// Bilder: nur die Pixel unter dem Rechteck werden geschwaerzt (Methode 2),
// Vektorgrafik bleibt (MuPDF kennt dafuer erst ab 1.24 eine Methode).
//
// Die letzte Ausgabezeile ist die Zahl der geschwaerzten Rechtecke.

var SCRIPT = "pdf-redact";
load(scriptArgs[0]);

if (args.length < 3) fail("zu wenige Argumente");

var input = args[0];
var output = args[1];
var lines = String(read(args[2])).split("\n");

var doc = openPdf(input);
var count = doc.countPages();

var perPage = {};
var total = 0;
for (var l = 0; l < lines.length; l++) {
    var line = lines[l].replace(/\r$/, "");
    if (line === "") continue;
    var parts = line.split("\t");
    if (parts.length < 5) fail("Zeile unvollstaendig: " + line);
    var page = parseInt(parts[0], 10);
    if (isNaN(page) || page < 1 || page > count) fail("Seite " + parts[0] + " gibt es nicht (" + count + " Seiten)");
    var box = [];
    for (var i = 1; i < 5; i++) {
        var v = parseFloat(parts[i]);
        if (isNaN(v)) fail("Koordinate fehlt: " + line);
        box.push(v);
    }
    var rect = [Math.min(box[0], box[2]), Math.min(box[1], box[3]), Math.max(box[0], box[2]), Math.max(box[1], box[3])];
    if (rect[2] <= rect[0] || rect[3] <= rect[1]) fail("leeres Rechteck: " + line);
    if (!(page in perPage)) perPage[page] = [];
    perPage[page].push(rect);
    total++;
}
if (total === 0) fail("kein Rechteck");

var pages = Object.keys(perPage);
for (var p = 0; p < pages.length; p++) {
    var number = parseInt(pages[p], 10);
    var pdfPage = doc.loadPage(number - 1);
    var rects = perPage[number];
    for (var r = 0; r < rects.length; r++) {
        var annot = pdfPage.createAnnotation("Redact");
        annot.setRect(rects[r]);
        if (typeof annot.update === "function") annot.update();
    }
    // true = schwarze Flaeche zeichnen, 2 = Bildpixel unter der Flaeche entfernen
    pdfPage.applyRedactions(true, 2);
}

doc.save(output, "garbage,compress");
print(total);
