// Metadaten einer PDF setzen oder entfernen (Info-Woerterbuch und XMP).
//
// Aufruf (mutool run):
//   pdf-metadata.js <lib.js> <eingabe> <ausgabe> clear
//   pdf-metadata.js <lib.js> <eingabe> <ausgabe> set <datei>
//
// clear leert das Info-Woerterbuch und entfernt den XMP-Strom (/Metadata).
// set liest Zeilen "Schluessel=Wert" aus der Datei (UTF-8): Title, Author,
// Subject, Keywords, Creator, Producer. Ein leerer Wert entfernt den Eintrag.
// Auch set entfernt den XMP-Strom, sonst zeigten Betrachter weiter die alten
// Werte daraus. Nicht genannte Eintraege bleiben.
//
// Die letzte Ausgabezeile ist die Seitenzahl der Datei.

var SCRIPT = "pdf-metadata";
load(scriptArgs[0]);

if (args.length < 3) fail("zu wenige Argumente");

var input = args[0];
var output = args[1];
var mode = String(args[2]).toLowerCase();
var KEYS = ["Title", "Author", "Subject", "Keywords", "Creator", "Producer"];

var doc = openPdf(input);
var trailer = doc.getTrailer();

function infoDictionary(create) {
    var info = trailer.get("Info");
    if (!isMissing(info)) return info;
    if (!create) return null;
    var dict = doc.addObject(doc.newDictionary());
    trailer.put("Info", dict);
    return dict;
}

function removeXmp() {
    var root = trailer.get("Root");
    if (!isMissing(root) && !isMissing(root.get("Metadata"))) root.delete("Metadata");
}

if (mode === "clear") {
    var existing = infoDictionary(false);
    if (existing !== null) {
        var keys = [];
        eachEntry(existing, function (key) { keys.push(key); });
        for (var i = 0; i < keys.length; i++) existing.delete(keys[i]);
    }
    removeXmp();
} else if (mode === "set") {
    if (args.length < 4) fail("Datei mit den Werten fehlt");
    var lines = String(read(args[3])).split("\n");
    var info = infoDictionary(true);
    for (var l = 0; l < lines.length; l++) {
        var line = lines[l].replace(/\r$/, "");
        if (line === "") continue;
        var eq = line.indexOf("=");
        if (eq < 1) fail("Zeile ohne Schluessel: " + line);
        var key = line.substring(0, eq);
        var value = line.substring(eq + 1);
        if (KEYS.indexOf(key) < 0) fail("unbekannter Schluessel: " + key);
        if (value === "") info.delete(key);
        else info.put(key, doc.newString(value));
    }
    removeXmp();
} else {
    fail("Modus muss clear oder set sein");
}

doc.save(output, "garbage,compress");
print(doc.countPages());
