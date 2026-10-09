// Ordnet die Seiten einer PDF neu: Reihenfolge, Drehung je Seite, Weglassen
// und Verdoppeln in einem Schritt.
//
// Aufruf (mutool run):
//   page-arrange.js <lib.js> <eingabe> <ausgabe> <folge>
//
// <folge> nennt die Zielseiten in ihrer Reihenfolge als Quellseiten (1-basiert),
// je mit optionaler Drehung in Grad, die zur bestehenden Drehung der Seite
// hinzukommt: "3:90,1,1,2:180" ergibt vier Seiten: Seite 3 um 90 Grad gedreht,
// Seite 1 zweimal, Seite 2 um 180 Grad gedreht. Nicht genannte Seiten fallen
// weg. Anmerkungen (Formularfelder, Kommentare) bleiben an ihrer Seite; eine
// verdoppelte Seite teilt sie sich mit ihrer Kopie.
//
// Nichts wird gerendert: Jede Zielseite nutzt den Inhaltsstrom der Quellseite.

var SCRIPT = "page-arrange";
load(scriptArgs[0]);

if (args.length < 3) fail("zu wenige Argumente");

var input = args[0];
var output = args[1];
var sequence = String(args[2]).split(",");

var doc = openPdf(input);
var count = doc.countPages();

var pages = [];
for (var i = 0; i < sequence.length; i++) {
    var item = sequence[i].trim();
    if (item === "") continue;
    var parts = item.split(":");
    var number = parseInt(parts[0], 10);
    var delta = parts.length > 1 ? parseInt(parts[1], 10) : 0;
    if (isNaN(number) || number < 1 || number > count) fail("Seite " + parts[0] + " gibt es nicht (" + count + " Seiten)");
    if (isNaN(delta) || delta % 90 !== 0) fail("Drehung " + parts[1] + " ist kein Vielfaches von 90");

    var source = doc.findPage(number - 1);
    var copy = copyPage(doc, source, true);
    var angle = normalizeAngle(rotation(source) + delta);
    if (angle === 0) copy.delete("Rotate");
    else copy.put("Rotate", angle);
    pages.push(copy);
}

if (pages.length === 0) fail("die Folge ist leer");

for (var p = 0; p < pages.length; p++) doc.insertPage(-1, doc.addObject(pages[p]));
for (var d = 0; d < count; d++) doc.deletePage(0);

doc.save(output, "garbage,compress");
print(pages.length);
