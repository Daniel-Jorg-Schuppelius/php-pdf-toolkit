// Lesezeichen (Outline) einer PDF setzen: eine Ebene, in Seitenreihenfolge.
//
// Aufruf (mutool run):
//   pdf-outline.js <lib.js> <eingabe> <ausgabe> <datei>
//
// Die Datei (UTF-8) traegt je Zeile "Seite<TAB>Titel" (Seite 1-basiert).
// Vorhandene Lesezeichen werden ersetzt; der Betrachter oeffnet die Datei
// mit sichtbarer Lesezeichenleiste (PageMode UseOutlines). Jedes Lesezeichen
// zeigt auf den Seitenanfang (/XYZ ohne Zoom), damit der Betrachter die
// Darstellung des Lesers behaelt.
//
// Die letzte Ausgabezeile ist die Zahl der gesetzten Lesezeichen.

var SCRIPT = "pdf-outline";
load(scriptArgs[0]);

if (args.length < 3) fail("zu wenige Argumente");

var input = args[0];
var output = args[1];
var lines = String(read(args[2])).split("\n");

var doc = openPdf(input);
var count = doc.countPages();
var root = doc.getTrailer().get("Root");
if (isMissing(root)) fail("PDF ohne Root");

var entries = [];
for (var l = 0; l < lines.length; l++) {
    var line = lines[l].replace(/\r$/, "");
    if (line === "") continue;
    var tab = line.indexOf("\t");
    if (tab < 1) fail("Zeile ohne Seite: " + line);
    var page = parseInt(line.substring(0, tab), 10);
    var title = line.substring(tab + 1);
    if (isNaN(page) || page < 1 || page > count) fail("Seite " + line.substring(0, tab) + " gibt es nicht (" + count + " Seiten)");
    if (title === "") fail("Lesezeichen ohne Titel fuer Seite " + page);
    entries.push({ page: page, title: title });
}
if (entries.length === 0) fail("keine Lesezeichen");

var outlines = doc.addObject(doc.newDictionary());
outlines.put("Type", doc.newName("Outlines"));
outlines.put("Count", entries.length);

var previous = null;
for (var i = 0; i < entries.length; i++) {
    var item = doc.addObject(doc.newDictionary());
    item.put("Title", doc.newString(entries[i].title));
    item.put("Parent", outlines);
    var dest = doc.newArray();
    dest.push(doc.findPage(entries[i].page - 1));
    dest.push(doc.newName("XYZ"));
    dest.push(doc.newNull());
    dest.push(doc.newNull());
    dest.push(doc.newNull());
    item.put("Dest", dest);
    if (previous === null) {
        outlines.put("First", item);
    } else {
        previous.put("Next", item);
        item.put("Prev", previous);
    }
    previous = item;
}
outlines.put("Last", previous);

root.put("Outlines", outlines);
root.put("PageMode", doc.newName("UseOutlines"));

doc.save(output, "garbage,compress");
print(entries.length);
