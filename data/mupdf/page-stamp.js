// Stempelt Text auf die Seiten einer PDF: Seitenzahlen oder ein Wasserzeichen.
//
// Aufruf (mutool run):
//   page-stamp.js <lib.js> <eingabe> <ausgabe> <modus> <textDatei> <position> <groesse>
//                 <randMm> <winkel> <deckkraft> <farbe> <ersteSeite> <startNummer>
//
// <modus>       number: der Text ist ein Muster mit {n} (laufende Nummer) und
//               {total} (Seiten der Datei), z. B. "Seite {n} von {total}"
//               watermark: der Text einmal an der Position oder, bei Position
//               fill, gekachelt ueber die ganze Seite
// <textDatei>   Datei mit dem Text (UTF-8, eine Zeile); Zeichen ausserhalb von
//               WinAnsi werden zu "?"
// <position>    top-left, top-center, top-right, middle-left, center,
//               middle-right, bottom-left, bottom-center, bottom-right, fill
// <groesse>     Schriftgroesse in Punkt
// <randMm>      Abstand zum Seitenrand in Millimetern
// <winkel>      Drehung des Texts in Grad, gegen den Uhrzeigersinn
// <deckkraft>   0 (unsichtbar) bis 1 (deckend)
// <farbe>       rrggbb (hexadezimal)
// <ersteSeite>  erste Seite, die gestempelt wird (1-basiert)
// <startNummer> Wert von {n} auf der ersten gestempelten Seite
//
// Der Text steht in Helvetica (Standardschrift) ueber dem Seiteninhalt, in der
// Lage, in der die Seite angezeigt wird (/Rotate wird beruecksichtigt).
// Nichts wird gerendert: Der Stempel ist ein zusaetzlicher Inhaltsstrom.

var SCRIPT = "page-stamp";
load(scriptArgs[0]);

if (args.length < 12) fail("zu wenige Argumente");

var input = args[0];
var output = args[1];
var mode = String(args[2]).toLowerCase();
var text = readTextFile(args[3]);
var position = String(args[4]).toLowerCase();
var size = floatArg(5, "Schriftgroesse");
var margin = mmToPt(floatArg(6, "Rand"));
var angle = parseFloat(args[7]);
var opacity = parseFloat(args[8]);
var color = String(args[9]).toLowerCase();
var firstPage = intArg(10, "erste Seite");
var startNumber = intArg(11, "Startnummer");

if (mode !== "number" && mode !== "watermark") fail("Modus muss number oder watermark sein");
if (text === "") fail("der Text ist leer");
var POSITIONS = ["top-left", "top-center", "top-right", "middle-left", "center", "middle-right",
    "bottom-left", "bottom-center", "bottom-right", "fill"];
if (POSITIONS.indexOf(position) < 0) fail("unbekannte Position: " + position);
if (position === "fill" && mode !== "watermark") fail("fill gibt es nur fuer watermark");
if (size < 4 || size > 400) fail("Schriftgroesse muss zwischen 4 und 400 liegen");
if (isNaN(angle)) fail("Winkel fehlt");
if (isNaN(opacity) || opacity < 0 || opacity > 1) fail("Deckkraft muss zwischen 0 und 1 liegen");
if (!/^[0-9a-f]{6}$/.test(color)) fail("Farbe muss rrggbb sein");

// WinAnsi-Byte je Zeichen; was es dort nicht gibt, wird ein Fragezeichen
var WINANSI = { 0x20AC: 0x80, 0x201A: 0x82, 0x0192: 0x83, 0x201E: 0x84, 0x2026: 0x85, 0x2020: 0x86,
    0x2021: 0x87, 0x02C6: 0x88, 0x2030: 0x89, 0x0160: 0x8A, 0x2039: 0x8B, 0x0152: 0x8C, 0x017D: 0x8E,
    0x2018: 0x91, 0x2019: 0x92, 0x201C: 0x93, 0x201D: 0x94, 0x2022: 0x95, 0x2013: 0x96, 0x2014: 0x97,
    0x02DC: 0x98, 0x2122: 0x99, 0x0161: 0x9A, 0x203A: 0x9B, 0x0153: 0x9C, 0x017E: 0x9E, 0x0178: 0x9F };

function winAnsiBytes(str) {
    var bytes = [];
    for (var i = 0; i < str.length; i++) {
        var code = str.charCodeAt(i);
        if (code >= 0xD800 && code <= 0xDBFF) { i++; bytes.push(63); continue; }
        if (code < 0x80 || (code >= 0xA0 && code <= 0xFF)) bytes.push(code);
        else if (WINANSI[code] !== undefined) bytes.push(WINANSI[code]);
        else bytes.push(63);
    }
    return bytes;
}

// PDF-Zeichenkette aus Bytes, nur ASCII (Oktal-Escapes fuer alles andere)
function pdfString(bytes) {
    var out = "(";
    for (var i = 0; i < bytes.length; i++) {
        var b = bytes[i];
        if (b === 40 || b === 41 || b === 92) out += "\\" + String.fromCharCode(b);
        else if (b < 32 || b > 126) out += "\\" + ("00" + b.toString(8)).slice(-3);
        else out += String.fromCharCode(b);
    }
    return out + ")";
}

var font = new Font("Helvetica");

// Breite des Texts in Punkt; ohne Metriken (alte Versionen) eine Schaetzung
function textWidth(bytes, fontSize) {
    var width = 0;
    for (var i = 0; i < bytes.length; i++) {
        var advance = 0.55;
        try {
            var glyph = font.encodeCharacter(bytes[i] < 0x80 ? bytes[i] : latinCode(bytes[i]));
            if (glyph > 0) advance = font.advanceGlyph(glyph, 0);
        } catch (e) {
            advance = 0.55;
        }
        width += advance * fontSize;
    }
    return width;
}

function latinCode(byte) {
    for (var code in WINANSI) if (WINANSI[code] === byte) return parseInt(code, 10);
    return byte;
}

function hexColor(hex) {
    var r = parseInt(hex.substring(0, 2), 16) / 255;
    var g = parseInt(hex.substring(2, 4), 16) / 255;
    var b = parseInt(hex.substring(4, 6), 16) / 255;
    return r.toFixed(3) + " " + g.toFixed(3) + " " + b.toFixed(3) + " rg";
}

// Eigenes (nicht geerbtes, nicht geteiltes) Unterwoerterbuch einer Seite
function ownDictionary(doc, page, key) {
    var own = page.get(key);
    var copy = doc.newDictionary();
    var source = isMissing(own) ? lookup(page, key) : own;
    if (!isMissing(source)) eachEntry(source, function (k, v) { copy.put(k, v); });
    page.put(key, copy);
    return copy;
}

function ownSubDictionary(doc, dict, key) {
    var copy = doc.newDictionary();
    var source = dict.get(key);
    if (!isMissing(source)) eachEntry(source, function (k, v) { copy.put(k, v); });
    dict.put(key, copy);
    return copy;
}

var doc = openPdf(input);
var count = doc.countPages();
if (firstPage < 1 || firstPage > count) fail("erste Seite " + firstPage + " gibt es nicht (" + count + " Seiten)");

var pdfFont = doc.addSimpleFont(font, "Latin");
var state = doc.newDictionary();
state.put("Type", doc.newName("ExtGState"));
state.put("CA", opacity);
state.put("ca", opacity);
var pdfState = doc.addObject(state);
var fill = hexColor(color);
var rad = angle * Math.PI / 180;
var cosA = Math.cos(rad), sinA = Math.sin(rad);

// Ein Text an (x, y) der Anzeige, um seine Mitte gedreht
function textOperator(bytes, x, y, width) {
    var h = size * 0.7;
    var cx = x + width / 2, cy = y + h / 2;
    var tx = cx - (cosA * width / 2 - sinA * h / 2);
    var ty = cy - (sinA * width / 2 + cosA * h / 2);
    return "BT /CKStampF " + size + " Tf " + cosA.toFixed(6) + " " + sinA.toFixed(6) + " "
        + (-sinA).toFixed(6) + " " + cosA.toFixed(6) + " " + tx.toFixed(3) + " " + ty.toFixed(3)
        + " Tm " + pdfString(bytes) + " Tj ET\n";
}

var stamped = 0;
for (var i = firstPage - 1; i < count; i++) {
    var page = doc.findPage(i);
    var box = visibleBox(page);
    var pw = box[2] - box[0], ph = box[3] - box[1];
    var rot = rotation(page);
    var dw = (rot === 90 || rot === 270) ? ph : pw;
    var dh = (rot === 90 || rot === 270) ? pw : ph;

    // Anzeigekoordinaten -> Seitenkoordinaten (Drehung und sichtbarer Bereich)
    var cm;
    if (rot === 90) cm = "0 1 -1 0 " + (box[0] + pw) + " " + box[1];
    else if (rot === 180) cm = "-1 0 0 -1 " + (box[0] + pw) + " " + (box[1] + ph);
    else if (rot === 270) cm = "0 -1 1 0 " + box[0] + " " + (box[1] + ph);
    else cm = "1 0 0 1 " + box[0] + " " + box[1];

    var label = mode === "number"
        ? text.replace(/\{n\}/g, String(startNumber + stamped)).replace(/\{total\}/g, String(count))
        : text;
    var bytes = winAnsiBytes(label);
    var width = textWidth(bytes, size);

    var ops = "";
    if (position === "fill") {
        var stepX = width + 3 * size, stepY = 6 * size;
        for (var y = -dh; y < 2 * dh; y += stepY) {
            for (var x = -dw; x < 2 * dw; x += stepX) ops += textOperator(bytes, x, y, width);
        }
    } else {
        var x0 = position.indexOf("left") >= 0 ? margin
            : position.indexOf("right") >= 0 ? dw - margin - width
            : (dw - width) / 2;
        var y0 = position.indexOf("top") >= 0 ? dh - margin - size * 0.7
            : position.indexOf("bottom") >= 0 ? margin
            : (dh - size * 0.7) / 2;
        ops = textOperator(bytes, x0, y0, width);
    }

    var resources = ownDictionary(doc, page, "Resources");
    ownSubDictionary(doc, resources, "Font").put("CKStampF", pdfFont);
    ownSubDictionary(doc, resources, "ExtGState").put("CKStampG", pdfState);

    // Den bisherigen Inhalt in q ... Q einschliessen, dann der Stempel
    var before = new Buffer();
    before.writeLine("q");
    var after = new Buffer();
    after.writeLine("Q q /CKStampG gs " + fill);
    after.writeLine(ops);
    after.writeLine("Q");

    var contents = doc.newArray();
    contents.push(doc.addStream(before));
    var old = page.get("Contents");
    if (!isMissing(old)) {
        if (old.isArray()) for (var k = 0; k < old.length; k++) contents.push(old.get(k));
        else contents.push(old);
    }
    var stampStream = new Buffer();
    stampStream.writeLine("Q q /CKStampG gs " + fill + " q " + cm + " cm");
    stampStream.writeLine(ops);
    stampStream.writeLine("Q Q");
    contents.push(doc.addStream(stampStream));
    page.put("Contents", contents);
    stamped++;
}

doc.save(output, "garbage,compress");
print(stamped);
