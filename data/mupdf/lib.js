// Gemeinsame Hilfen der MuPDF-Skripte (mutool run).
//
// Jedes Skript bekommt den Pfad dieser Datei als erstes Argument und laedt sie
// mit load(); die eigenen Argumente stehen danach in args (scriptArgs ohne das
// erste). Vor dem Laden setzt das Skript SCRIPT auf seinen Namen, damit
// Fehlermeldungen ihn tragen.
//
// Die JS-Schnittstelle von MuPDF unterscheidet sich je Version (geprueft mit
// 1.17, 1.21 und 1.25):
// - Document.openDocument gibt es erst nach 1.21: new PDFDocument(pfad) geht
//   ueberall.
// - PDFObject.forEach ruft alt mit (Schluessel, Wert), neu mit (Wert,
//   Schluessel): eachEntry() erkennt die Reihenfolge am Typ.
// - Ein fehlender Schluessel ist alt undefined, neu null: isMissing().
// - graftPage fehlt in 1.17.
// - Inhaltsstroeme als Buffer weiterreichen (writeBuffer), nie als Text:
//   readStream() liefert in alten Versionen kein asString().

var args = scriptArgs.slice(1);

function fail(message) {
    throw new Error((typeof SCRIPT === "string" ? SCRIPT : "mupdf") + ": " + message);
}

function intArg(index, name) {
    var value = parseInt(args[index], 10);
    if (isNaN(value)) fail(name + " fehlt oder ist keine Zahl");
    return value;
}

function floatArg(index, name) {
    var value = parseFloat(args[index]);
    if (isNaN(value) || value < 0) fail(name + " fehlt oder ist negativ");
    return value;
}

function isMissing(obj) {
    return obj === null || obj === undefined || obj.isNull();
}

// Eintraege eines Dictionaries als (Schluessel, Wert), in jeder MuPDF-Version
function eachEntry(dict, fn) {
    dict.forEach(function (a, b) {
        if (typeof a === "string") fn(a, b);
        else fn(b, a);
    });
}

// Oeffnet eine PDF; verschluesselte Dateien mit Benutzerpasswort lehnt das
// Skript ab, dafuer gibt es pdf-protect.js
function openPdf(path) {
    var doc = new PDFDocument(path);
    if (doc.needsPassword()) fail("PDF ist passwortgeschuetzt");
    return doc;
}

// Seitenattribut, notfalls vom Seitenbaum geerbt
function lookup(page, key) {
    var node = page;
    for (var depth = 0; depth < 64 && !isMissing(node); depth++) {
        var value = node.get(key);
        if (!isMissing(value)) return value;
        node = node.get("Parent");
    }
    return null;
}

function toRect(obj) {
    if (isMissing(obj) || !obj.isArray() || obj.length !== 4) return null;
    var v = [];
    for (var i = 0; i < 4; i++) v.push(obj.get(i).valueOf());
    return [Math.min(v[0], v[2]), Math.min(v[1], v[3]), Math.max(v[0], v[2]), Math.max(v[1], v[3])];
}

// Sichtbarer Bereich: CropBox geschnitten mit MediaBox
function visibleBox(page) {
    var media = toRect(lookup(page, "MediaBox"));
    if (media === null) fail("Seite ohne MediaBox");
    var crop = toRect(lookup(page, "CropBox"));
    if (crop === null) return media;
    var box = [Math.max(media[0], crop[0]), Math.max(media[1], crop[1]), Math.min(media[2], crop[2]), Math.min(media[3], crop[3])];
    return (box[2] > box[0] && box[3] > box[1]) ? box : media;
}

// Drehung der Seite in der Anzeige, 0/90/180/270
function rotation(page) {
    var value = lookup(page, "Rotate");
    var angle = isMissing(value) ? 0 : Math.round(value.valueOf());
    return ((angle % 360) + 360) % 360;
}

function normalizeAngle(angle) {
    return ((Math.round(angle / 90) * 90) % 360 + 360) % 360;
}

var INHERITED = ["Resources", "MediaBox", "CropBox", "Rotate"];

// Flache Kopie des Seitenobjekts mit aufgeloesten geerbten Attributen
function copyPage(doc, page, withAnnots) {
    var copy = doc.newDictionary();
    eachEntry(page, function (key, value) {
        if (key === "Parent" || (key === "Annots" && !withAnnots)) return;
        copy.put(key, value);
    });
    for (var i = 0; i < INHERITED.length; i++) {
        var key = INHERITED[i];
        if (isMissing(copy.get(key))) {
            var value = lookup(page, key);
            if (!isMissing(value)) copy.put(key, value);
        }
    }
    return copy;
}

// Inhaltsstroeme einer Seite als ein Buffer (mehrere Teile werden mit
// Zeilenumbruch verkettet, so wie der PDF-Betrachter sie liest)
function contentBuffer(page) {
    var buffer = new Buffer();
    var contents = page.get("Contents");
    if (isMissing(contents)) return buffer;
    if (contents.isArray()) {
        for (var i = 0; i < contents.length; i++) {
            buffer.writeBuffer(contents.get(i).readStream());
            buffer.writeLine("");
        }
    } else {
        buffer.writeBuffer(contents.readStream());
    }
    return buffer;
}

// Seite als Form-XObject in einem anderen Dokument (Inhalt und Ressourcen
// werden uebernommen, nichts gerendert)
function pageAsXObject(target, doc, page) {
    var box = visibleBox(page);
    var dict = target.newDictionary();
    dict.put("Type", target.newName("XObject"));
    dict.put("Subtype", target.newName("Form"));
    dict.put("BBox", box);
    var resources = lookup(page, "Resources");
    if (!isMissing(resources)) dict.put("Resources", target.graftObject(resources));
    return target.addStream(contentBuffer(page), dict);
}

// Liest eine Datei als Text (z. B. ein Passwort); genau ein abschliessender
// Zeilenumbruch wird entfernt, alles andere bleibt
function readTextFile(path) {
    var text = String(read(path));
    if (text.length > 0 && text.charAt(text.length - 1) === "\n") text = text.substring(0, text.length - 1);
    if (text.length > 0 && text.charAt(text.length - 1) === "\r") text = text.substring(0, text.length - 1);
    return text;
}

function padNumber(value, width) {
    var text = String(value);
    while (text.length < width) text = "0" + text;
    return text;
}

// Blattgroessen in Punkten (hoch)
var SHEETS = {
    a3: [841.89, 1190.55],
    a4: [595.28, 841.89],
    a5: [419.53, 595.28],
    letter: [612, 792],
    legal: [612, 1008]
};

function sheetSize(name, landscape) {
    var size = SHEETS[String(name).toLowerCase()];
    if (!size) fail("unbekanntes Blatt: " + name);
    return landscape ? [size[1], size[0]] : [size[0], size[1]];
}

function mmToPt(mm) {
    return mm * 72 / 25.4;
}
