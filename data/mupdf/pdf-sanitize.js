// PDF bereinigen: Anmerkungen, Verknuepfungen, Formulare, JavaScript und
// eingebettete Dateien entfernen.
//
// Aufruf (mutool run):
//   pdf-sanitize.js <lib.js> <eingabe> <ausgabe> <was>
//
// <was> ist eine Liste aus annots, links, forms, javascript, attachments,
// durch Komma getrennt:
//   annots      - Kommentare, Stempel, Markierungen (alle Anmerkungen ausser
//                 Verknuepfungen, Formularfeldern und Dateianhaengen)
//   links       - Verknuepfungen (Link)
//   forms       - Formularfelder: AcroForm und Widget-Anmerkungen. Ohne
//                 vorheriges Einbrennen verschwinden die eingetragenen Werte
//                 mit; dafuer gibt es PDFSanitizeHelper::flatten() (Ghostscript).
//   javascript  - Names/JavaScript, OpenAction mit JavaScript sowie alle
//                 AA-Aktionen an Dokument, Seiten, Anmerkungen und Feldern
//   attachments - Names/EmbeddedFiles und FileAttachment-Anmerkungen
//
// Nichts wird gerendert, der Seiteninhalt bleibt. Die letzte Ausgabezeile ist
// die Seitenzahl der Datei.

var SCRIPT = "pdf-sanitize";
load(scriptArgs[0]);

if (args.length < 3) fail("zu wenige Argumente");

var input = args[0];
var output = args[1];
var what = {};
var names = String(args[2]).split(",");
for (var n = 0; n < names.length; n++) {
    var name = names[n].trim().toLowerCase();
    if (name === "") continue;
    if (["annots", "links", "forms", "javascript", "attachments"].indexOf(name) < 0) fail("unbekannte Angabe: " + name);
    what[name] = true;
}
if (Object.keys(what).length === 0) fail("nichts zu bereinigen");

var doc = openPdf(input);
var count = doc.countPages();
var root = doc.getTrailer().get("Root");
if (isMissing(root)) fail("PDF ohne Root");

function nameOf(obj) {
    return isMissing(obj) || !obj.isName() ? "" : obj.asName();
}

function isJavaScriptAction(action) {
    return !isMissing(action) && action.isDictionary() && nameOf(action.get("S")) === "JavaScript";
}

// Entfernt die Aktionen eines Objekts: AA immer, A nur bei JavaScript
function stripActions(obj) {
    if (isMissing(obj) || !obj.isDictionary()) return;
    if (!isMissing(obj.get("AA"))) obj.delete("AA");
    if (isJavaScriptAction(obj.get("A"))) obj.delete("A");
}

// Soll die Anmerkung weg?
function dropAnnotation(annot) {
    var subtype = nameOf(annot.get("Subtype"));
    if (subtype === "Link") return what.links === true;
    if (subtype === "Widget") return what.forms === true;
    if (subtype === "FileAttachment") return what.attachments === true || what.annots === true;
    if (subtype === "Popup") return what.annots === true;
    return what.annots === true;
}

for (var p = 0; p < count; p++) {
    var page = doc.findPage(p);
    if (what.javascript) stripActions(page);
    var annots = page.get("Annots");
    if (isMissing(annots) || !annots.isArray()) continue;
    var kept = doc.newArray();
    for (var a = 0; a < annots.length; a++) {
        var annot = annots.get(a);
        if (isMissing(annot) || !annot.isDictionary()) continue;
        if (dropAnnotation(annot)) continue;
        if (what.javascript) stripActions(annot);
        kept.push(annot);
    }
    if (kept.length === 0) page.delete("Annots");
    else page.put("Annots", kept);
}

if (what.forms && !isMissing(root.get("AcroForm"))) root.delete("AcroForm");

if (what.javascript) {
    stripActions(root);
    if (isJavaScriptAction(root.get("OpenAction"))) root.delete("OpenAction");
    var acro = root.get("AcroForm");
    if (!isMissing(acro) && acro.isDictionary()) {
        var fields = acro.get("Fields");
        if (!isMissing(fields) && fields.isArray()) {
            for (var f = 0; f < fields.length; f++) stripActions(fields.get(f));
        }
    }
}

var nameTree = root.get("Names");
if (!isMissing(nameTree) && nameTree.isDictionary()) {
    if (what.javascript && !isMissing(nameTree.get("JavaScript"))) nameTree.delete("JavaScript");
    if (what.attachments && !isMissing(nameTree.get("EmbeddedFiles"))) nameTree.delete("EmbeddedFiles");
    var empty = true;
    eachEntry(nameTree, function () { empty = false; });
    if (empty) root.delete("Names");
}

doc.save(output, "garbage,compress");
print(count);
