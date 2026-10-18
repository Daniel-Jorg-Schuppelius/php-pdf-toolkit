// Dateien in eine PDF einbetten (EmbeddedFiles-Namensbaum, PDF 1.4).
//
// Aufruf (mutool run):
//   pdf-attach.js <lib.js> <eingabe> <ausgabe> <datei>
//
// Die Datei (UTF-8) traegt je Zeile "Pfad<TAB>Name<TAB>MIME-Typ<TAB>Beschreibung";
// MIME-Typ und Beschreibung duerfen leer sein. Der Name ist der Dateiname im
// Betrachter und zugleich der Schluessel im Namensbaum; ein schon vorhandener
// Anhang gleichen Namens wird ersetzt. Vorhandene Anhaenge bleiben.
//
// Die letzte Ausgabezeile ist die Zahl der Anhaenge in der Datei danach.

var SCRIPT = "pdf-attach";
load(scriptArgs[0]);

if (args.length < 3) fail("zu wenige Argumente");

var input = args[0];
var output = args[1];
var lines = String(read(args[2])).split("\n");

var doc = openPdf(input);
var root = doc.getTrailer().get("Root");
if (isMissing(root)) fail("PDF ohne Root");

// Vorhandene Eintraege (nur flache Namensbaeume; verschachtelte Kids werden
// flach uebernommen)
var existing = {};
var order = [];
function collect(node, depth) {
    if (isMissing(node) || !node.isDictionary() || depth > 32) return;
    var pairs = node.get("Names");
    if (!isMissing(pairs) && pairs.isArray()) {
        for (var i = 0; i + 1 < pairs.length; i += 2) {
            var key = pairs.get(i);
            var keyText = key.isString() ? key.asString() : String(key);
            if (!(keyText in existing)) order.push(keyText);
            existing[keyText] = pairs.get(i + 1);
        }
    }
    var kids = node.get("Kids");
    if (!isMissing(kids) && kids.isArray()) {
        for (var k = 0; k < kids.length; k++) collect(kids.get(k), depth + 1);
    }
}
var nameTree = root.get("Names");
if (!isMissing(nameTree) && nameTree.isDictionary()) collect(nameTree.get("EmbeddedFiles"), 0);

var added = 0;
for (var l = 0; l < lines.length; l++) {
    var line = lines[l].replace(/\r$/, "");
    if (line === "") continue;
    var parts = line.split("\t");
    if (parts.length < 2 || parts[0] === "" || parts[1] === "") fail("Zeile ohne Pfad oder Name: " + line);
    var path = parts[0];
    var name = parts[1];
    var mime = parts.length > 2 ? parts[2] : "";
    var description = parts.length > 3 ? parts[3] : "";

    var data = readFile(path);
    var streamDict = doc.newDictionary();
    streamDict.put("Type", doc.newName("EmbeddedFile"));
    if (mime !== "") streamDict.put("Subtype", doc.newName(mime));
    var params = doc.newDictionary();
    params.put("Size", data.length);
    streamDict.put("Params", params);
    var stream = doc.addStream(data, streamDict);

    var ef = doc.newDictionary();
    ef.put("F", stream);
    ef.put("UF", stream);
    var spec = doc.addObject(doc.newDictionary());
    spec.put("Type", doc.newName("Filespec"));
    spec.put("F", doc.newString(name));
    spec.put("UF", doc.newString(name));
    if (description !== "") spec.put("Desc", doc.newString(description));
    spec.put("EF", ef);

    if (!(name in existing)) order.push(name);
    existing[name] = spec;
    added++;
}
if (added === 0) fail("keine Datei zum Einbetten");

// Namensbaum: Schluessel sortiert (Pflicht der PDF-Spezifikation)
order.sort();
var pairsOut = doc.newArray();
for (var o = 0; o < order.length; o++) {
    pairsOut.push(doc.newString(order[o]));
    pairsOut.push(existing[order[o]]);
}
var embedded = doc.newDictionary();
embedded.put("Names", pairsOut);
if (isMissing(nameTree) || !nameTree.isDictionary()) {
    nameTree = doc.newDictionary();
    root.put("Names", nameTree);
}
nameTree.put("EmbeddedFiles", embedded);

doc.save(output, "garbage,compress");
print(order.length);
