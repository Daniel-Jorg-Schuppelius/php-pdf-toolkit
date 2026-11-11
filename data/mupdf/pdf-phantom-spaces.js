// Phantom-Leerzeichen aus der Textebene entfernen.
//
// Aufruf (mutool run):
//   pdf-phantom-spaces.js <lib.js> <eingabe> <ausgabe>
//
// Manche Erzeuger setzen Glyphen oder Silben einzeln (Td/Tm je Stueck) und
// geben an jeder Trennstelle eines Worts ein Leerzeichen aus, dessen
// Nachfolger an DERSELBEN Stelle beginnt - so der PDF24-Druckertreiber fuer
// die bedingten Trennstriche aus Word. Gemessen (FM-SSDV0-2ZEG0, 11.11.2026):
//
//   8.15 0 Td <0003> Tj 0 0 Td <004B> Tj            "In" | " " | "halts"
//   7.33 0 Td <0003> Tj ET BT ... 598.1 942 Tm <0010> Tj    "...un" " " | "-" am Zeilenende
//   <0024005100440003> Tj 30.2266 0 Td ...          "Ana " - Td rueckt nur um "Ana" vor
//
// Das Leerzeichen zeichnet nichts, steht aber in der Textebene. pdftotext
// entscheidet Wortgrenzen nach Lage und verwirft es; LibreOffice und MuPDF
// (pdf2docx) uebernehmen es - aus "Inhaltsverzeichnis" wird
// "In halts ver zeich nis".
//
// Regel: Endet die Zeichenkette eines Tj auf Leerzeichen und beginnt das
// naechste gezeigte Zeichen dort, wo das erste dieser Leerzeichen beginnt,
// fallen die Leerzeichen weg (der ganze Tj, wenn er nur aus Leerzeichen
// besteht). Td/Tm rechnen vom Anfang der Zeile, nicht vom Ende des Gezeigten:
// Die folgenden Zeichen bleiben, wo sie waren, die Seite sieht gleich aus.
// Wo das Leerzeichen beginnt, ergibt sich aus den Breiten der Glyphen davor
// (/W bzw. /Widths, Schriftgroesse, Tc, Tw, Tz); ohne Breiten wird nur ein Tj
// aus lauter Leerzeichen geprueft. Zwischen Kandidat und Nachfolger duerfen
// nur Positionierung, Textzustand und Farbe stehen - alles andere (cm, q/Q,
// Pfade, XObjects) verwirft den Kandidaten.
//
// Ein Leerzeichen ist ein Code, den die ToUnicode-Tabelle der Schrift auf
// U+0020 abbildet; eine einfache Schrift ohne ToUnicode nimmt Code 32 bzw.
// /space aus den Differences, eine CID-Schrift ohne ToUnicode bleibt
// unberuehrt.
//
// Seiten und Form-XObjects werden je Strom bearbeitet (gemeinsam genutzte
// Stroeme einmal). Die Ausgabe entsteht nur, wenn etwas entfernt wurde. Die
// vorletzte Ausgabezeile nennt die Zahl der bereinigten Stellen, die letzte
// die Seitenzahl der Datei.

var SCRIPT = "pdf-phantom-spaces";
load(scriptArgs[0]);

if (args.length < 2) fail("zu wenige Argumente");

var input = args[0];
var output = args[1];

var doc = openPdf(input);
var count = doc.countPages();

// ------------------------------------------------------------ Bytes

function isWhite(b) {
    return b === 0 || b === 9 || b === 10 || b === 12 || b === 13 || b === 32;
}

function isDelimiter(b) {
    // ( ) < > [ ] { } / %
    return b === 40 || b === 41 || b === 60 || b === 62 || b === 91 || b === 93 || b === 123 || b === 125 || b === 47 || b === 37;
}

function hexValue(b) {
    if (b >= 48 && b <= 57) return b - 48;
    if (b >= 65 && b <= 70) return b - 55;
    if (b >= 97 && b <= 102) return b - 87;
    return -1;
}

// Zahl aus dem Inhaltsstrom. Das Vorzeichen wird selbst abgetrennt: MuJS in
// MuPDF 1.19 und 1.21 liefert fuer parseFloat("-1") und Number("-1") NaN
// (gemessen; "-1.5" und "-.5" gehen) - jede Tm-Matrix "1 0 0 -1 ..." waere
// sonst unbrauchbar.
function toNumber(word) {
    var sign = 1;
    var first = word.charAt(0);
    if (first === "-" || first === "+") {
        if (first === "-") sign = -1;
        word = word.substring(1);
    }
    return sign * parseFloat(word);
}

function bufferText(buffer) {
    var text = "";
    for (var i = 0; i < buffer.length; i++) text += String.fromCharCode(buffer[i]);
    return text;
}

function objectNumber(obj) {
    return isMissing(obj) ? 0 : toNumber(String(obj.valueOf()));
}

// ------------------------------------------------------------ Schriften

function nameOf(obj) {
    return isMissing(obj) || !obj.isName() ? "" : obj.asName();
}

function hexNumber(text) {
    return parseInt(text, 16);
}

// Codes, die die ToUnicode-Tabelle auf U+0020 abbildet, und die Breite eines Codes in Bytes
function spacesFromToUnicode(cmap) {
    var text = bufferText(cmap.readStream());
    var result = { width: 0, codes: {} };
    var range = /begincodespacerange([\s\S]*?)endcodespacerange/g;
    var m;
    while ((m = range.exec(text)) !== null) {
        var first = /<([0-9A-Fa-f]+)>/.exec(m[1]);
        if (first) result.width = Math.max(result.width, Math.ceil(first[1].length / 2));
    }
    var chars = /beginbfchar([\s\S]*?)endbfchar/g;
    while ((m = chars.exec(text)) !== null) {
        var pair = /<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/g;
        var p;
        while ((p = pair.exec(m[1])) !== null) {
            if (result.width === 0) result.width = Math.ceil(p[1].length / 2);
            if (p[2].length <= 4 && hexNumber(p[2]) === 0x20) result.codes[hexNumber(p[1])] = true;
        }
    }
    var ranges = /beginbfrange([\s\S]*?)endbfrange/g;
    while ((m = ranges.exec(text)) !== null) {
        var entry = /<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<([0-9A-Fa-f]+)>|\[([^\]]*)\])/g;
        var r;
        while ((r = entry.exec(m[1])) !== null) {
            var lo = hexNumber(r[1]);
            var hi = hexNumber(r[2]);
            if (result.width === 0) result.width = Math.ceil(r[1].length / 2);
            if (r[4] !== undefined && r[4] !== "") {
                var start = hexNumber(r[4]);
                if (r[4].length <= 4 && start <= 0x20 && 0x20 - start <= hi - lo) result.codes[lo + 0x20 - start] = true;
            } else if (r[5] !== undefined) {
                var items = r[5].match(/<([0-9A-Fa-f]+)>/g) || [];
                for (var k = 0; k < items.length && lo + k <= hi; k++) {
                    var value = items[k].substring(1, items[k].length - 1);
                    if (value.length <= 4 && hexNumber(value) === 0x20) result.codes[lo + k] = true;
                }
            }
        }
    }
    if (result.width === 0) result.width = 1;
    return result;
}

// Leerzeichen einer einfachen Schrift ohne ToUnicode: Code 32, ausser die
// Differences belegen ihn anders; dazu jeder Code, den sie /space nennen
function spacesFromEncoding(font) {
    var result = { width: 1, codes: { 32: true } };
    var encoding = font.get("Encoding");
    if (isMissing(encoding) || !encoding.isDictionary()) return result;
    var differences = encoding.get("Differences");
    if (isMissing(differences) || !differences.isArray()) return result;
    var code = 0;
    for (var i = 0; i < differences.length; i++) {
        var item = differences.get(i);
        if (item.isNumber()) {
            code = objectNumber(item);
            continue;
        }
        if (item.isName()) {
            if (item.asName() === "space") result.codes[code] = true;
            else delete result.codes[code];
            code++;
        }
    }
    return result;
}

// Glyphbreiten in 1/1000 Schriftgroesse: { byCode, fallback } oder null
function glyphWidths(font, subtype) {
    if (subtype === "Type0") {
        var descendants = font.get("DescendantFonts");
        if (isMissing(descendants) || !descendants.isArray() || descendants.length < 1) return null;
        var cid = descendants.get(0);
        if (isMissing(cid) || !cid.isDictionary()) return null;
        var widths = { byCode: {}, fallback: isMissing(cid.get("DW")) ? 1000 : objectNumber(cid.get("DW")) };
        var w = cid.get("W");
        if (!isMissing(w) && w.isArray()) {
            var i = 0;
            while (i < w.length) {
                var first = objectNumber(w.get(i));
                var next = w.get(i + 1);
                if (!isMissing(next) && next.isArray()) {
                    for (var k = 0; k < next.length; k++) widths.byCode[first + k] = objectNumber(next.get(k));
                    i += 2;
                } else {
                    var last = objectNumber(next);
                    var value = objectNumber(w.get(i + 2));
                    for (var c = first; c <= last && c - first < 65536; c++) widths.byCode[c] = value;
                    i += 3;
                }
            }
        }
        // Nur Identity-Kodierung: Code = CID
        return nameOf(font.get("Encoding")).indexOf("Identity") === 0 ? widths : null;
    }
    var list = font.get("Widths");
    if (isMissing(list) || !list.isArray()) return null;
    var simple = { byCode: {}, fallback: 0 };
    var descriptor = font.get("FontDescriptor");
    if (!isMissing(descriptor) && descriptor.isDictionary() && !isMissing(descriptor.get("MissingWidth"))) {
        simple.fallback = objectNumber(descriptor.get("MissingWidth"));
    }
    var firstChar = isMissing(font.get("FirstChar")) ? 0 : objectNumber(font.get("FirstChar"));
    for (var j = 0; j < list.length; j++) simple.byCode[firstChar + j] = objectNumber(list.get(j));
    return simple;
}

// Je Schriftname der Ressourcen: Leerzeichen-Codes, Codebreite in Bytes, Glyphbreiten
function fontInfo(resources) {
    var map = {};
    if (isMissing(resources)) return map;
    var fonts = resources.get("Font");
    if (isMissing(fonts) || !fonts.isDictionary()) return map;
    eachEntry(fonts, function (key, font) {
        if (isMissing(font) || !font.isDictionary()) return;
        var subtype = nameOf(font.get("Subtype"));
        if (subtype === "Type3") return;
        var toUnicode = font.get("ToUnicode");
        var info = null;
        if (!isMissing(toUnicode) && toUnicode.isStream()) info = spacesFromToUnicode(toUnicode);
        else if (subtype !== "Type0") info = spacesFromEncoding(font);
        if (info === null) return;
        info.widths = glyphWidths(font, subtype);
        map[key] = info;
    });
    return map;
}

// ------------------------------------------------------------ Inhaltsstrom

// Zerlegt den Strom; liefert je Operator { op, start, end, operands } mit
// operands als { type, start, end, value | bytes }
function operators(buffer) {
    var n = buffer.length;
    var i = 0;
    var list = [];
    var operands = [];
    var opStart = -1;

    function push(token) {
        if (opStart < 0) opStart = token.start;
        operands.push(token);
    }

    while (i < n) {
        var b = buffer[i];
        if (isWhite(b)) { i++; continue; }
        if (b === 37) { // Kommentar
            while (i < n && buffer[i] !== 10 && buffer[i] !== 13) i++;
            continue;
        }
        var start = i;
        if (b === 40) { // ( literale Zeichenkette )
            var bytes = [];
            var depth = 1;
            i++;
            while (i < n && depth > 0) {
                var c = buffer[i];
                if (c === 92 && i + 1 < n) { // Backslash
                    var e = buffer[i + 1];
                    if (e === 110) { bytes.push(10); i += 2; }
                    else if (e === 114) { bytes.push(13); i += 2; }
                    else if (e === 116) { bytes.push(9); i += 2; }
                    else if (e === 98) { bytes.push(8); i += 2; }
                    else if (e === 102) { bytes.push(12); i += 2; }
                    else if (e >= 48 && e <= 55) {
                        var octal = 0;
                        var digits = 0;
                        i++;
                        while (digits < 3 && i < n && buffer[i] >= 48 && buffer[i] <= 55) { octal = octal * 8 + buffer[i] - 48; i++; digits++; }
                        bytes.push(octal & 255);
                    }
                    else if (e === 13) { i += (i + 2 < n && buffer[i + 2] === 10) ? 3 : 2; }
                    else if (e === 10) { i += 2; }
                    else { bytes.push(e); i += 2; }
                    continue;
                }
                if (c === 40) depth++;
                if (c === 41) { depth--; if (depth === 0) { i++; break; } }
                bytes.push(c);
                i++;
            }
            push({ type: "string", start: start, end: i, bytes: bytes });
            continue;
        }
        if (b === 60 && i + 1 < n && buffer[i + 1] === 60) { push({ type: "delim", start: i, end: i + 2 }); i += 2; continue; }
        if (b === 62 && i + 1 < n && buffer[i + 1] === 62) { push({ type: "delim", start: i, end: i + 2 }); i += 2; continue; }
        if (b === 60) { // <hex>
            var hex = [];
            i++;
            while (i < n && buffer[i] !== 62) {
                var h = hexValue(buffer[i]);
                if (h >= 0) hex.push(h);
                i++;
            }
            i++;
            if (hex.length % 2 === 1) hex.push(0);
            var hb = [];
            for (var k = 0; k < hex.length; k += 2) hb.push(hex[k] * 16 + hex[k + 1]);
            push({ type: "string", start: start, end: i, bytes: hb });
            continue;
        }
        if (b === 91 || b === 93 || b === 123 || b === 125) { push({ type: "delim", start: i, end: i + 1 }); i++; continue; }
        if (b === 47) { // /Name
            i++;
            while (i < n && !isWhite(buffer[i]) && !isDelimiter(buffer[i])) i++;
            var name = "";
            for (var q = start + 1; q < i; q++) name += String.fromCharCode(buffer[q]);
            push({ type: "name", start: start, end: i, value: name });
            continue;
        }
        // Zahl oder Operator
        while (i < n && !isWhite(buffer[i]) && !isDelimiter(buffer[i])) i++;
        if (i === start) { i++; continue; } // einzelnes ")" oder ">" ohne Gegenstueck
        var word = "";
        for (var w = start; w < i; w++) word += String.fromCharCode(buffer[w]);
        if (/^[+-]?(\d+\.?\d*|\.\d+)$/.test(word)) {
            push({ type: "number", start: start, end: i, value: toNumber(word) });
            continue;
        }
        list.push({ op: word, start: opStart < 0 ? start : opStart, end: i, operands: operands });
        operands = [];
        opStart = -1;
        if (word === "BI") { // Inline-Bild: Daten nach ID bis EI ueberspringen
            while (i < n) {
                if (buffer[i] === 73 && i + 1 < n && buffer[i + 1] === 68 && isWhite(buffer[i - 1]) && (i + 2 >= n || isWhite(buffer[i + 2]))) { i += 3; break; }
                i++;
            }
            while (i < n) {
                if (buffer[i] === 69 && i + 1 < n && buffer[i + 1] === 73 && isWhite(buffer[i - 1]) && (i + 2 >= n || isWhite(buffer[i + 2]) || isDelimiter(buffer[i + 2]))) { i += 2; break; }
                i++;
            }
        }
    }
    return list;
}

function numbers(entry, count) {
    if (entry.operands.length !== count) return null;
    var values = [];
    for (var i = 0; i < count; i++) {
        if (entry.operands[i].type !== "number") return null;
        values.push(entry.operands[i].value);
    }
    return values;
}

// Zeilenmatrix nach "tx ty Td": [1 0 0 1 tx ty] x m
function translate(m, tx, ty) {
    return [m[0], m[1], m[2], m[3], tx * m[0] + ty * m[2] + m[4], tx * m[1] + ty * m[3] + m[5]];
}

// Gleiche Stelle: dieselbe Matrix, Ursprung bis auf 1 % der Schriftgroesse
function sameOrigin(a, b, size) {
    for (var i = 0; i < 4; i++) if (Math.abs(a[i] - b[i]) > 1e-6) return false;
    var scale = Math.sqrt(Math.abs(a[0] * a[3] - a[1] * a[2])) || 1;
    var tolerance = Math.max(0.01 * Math.abs(size) * scale, 1e-3);
    return Math.abs(a[4] - b[4]) <= tolerance && Math.abs(a[5] - b[5]) <= tolerance;
}

// Codes einer Zeichenkette nach der Codebreite der Schrift
function codesOf(bytes, width) {
    if (bytes.length === 0 || bytes.length % width !== 0) return null;
    var codes = [];
    for (var i = 0; i < bytes.length; i += width) {
        var code = 0;
        for (var k = 0; k < width; k++) code = code * 256 + bytes[i + k];
        codes.push(code);
    }
    return codes;
}

// Vorschub der Codes [0, upTo) im Textraum (vor der Zeilenmatrix), oder null ohne Breiten
function advance(codes, upTo, info, state) {
    if (upTo === 0) return 0;
    if (info.widths === null) return null;
    var total = 0;
    for (var i = 0; i < upTo; i++) {
        var w = info.widths.byCode[codes[i]];
        if (w === undefined) w = info.widths.fallback;
        var wordSpace = (info.width === 1 && codes[i] === 32) ? state.wordSpacing : 0;
        total += (w / 1000 * state.size + state.charSpacing + wordSpace) * state.scale;
    }
    return total;
}

function hexString(bytes) {
    var digits = "0123456789ABCDEF";
    var text = "<";
    for (var i = 0; i < bytes.length; i++) text += digits.charAt(bytes[i] >> 4) + digits.charAt(bytes[i] & 15);
    return text + ">";
}

// Operatoren, die weder den Ursprung des naechsten Zeichens noch das
// Koordinatensystem aendern: Textzustand und Farbe
var NEUTRAL = { Tz: 1, Ts: 1, Tr: 1, g: 1, G: 1, rg: 1, RG: 1, k: 1, K: 1, cs: 1, CS: 1, sc: 1, SC: 1, scn: 1, SCN: 1, BT: 1, ET: 1 };

var SHOW = { Tj: 1, TJ: 1, "'": 1, '"': 1 };

function copyState(s) {
    return { font: s.font, size: s.size, charSpacing: s.charSpacing, wordSpacing: s.wordSpacing, scale: s.scale, leading: s.leading };
}

// Aenderungen { start, end, text } eines Stroms: Phantom-Leerzeichen am Ende
// einer Zeichenkette (oder der ganze Tj)
function phantomEdits(buffer, fonts) {
    var ops = operators(buffer);
    var edits = [];
    var state = { font: null, size: 0, charSpacing: 0, wordSpacing: 0, scale: 1, leading: 0 };
    var stack = [];
    var line = [1, 0, 0, 1, 0, 0];
    var fresh = false;      // Textmatrix == Zeilenmatrix: seit der Positionierung nichts gezeigt
    var candidate = null;   // { origin, size, edit }
    for (var i = 0; i < ops.length; i++) {
        var entry = ops[i];
        var op = entry.op;
        var v;
        if (op === "BT") { line = [1, 0, 0, 1, 0, 0]; fresh = true; continue; }
        if (op === "Td" || op === "TD") {
            v = numbers(entry, 2);
            if (v === null) { candidate = null; fresh = false; continue; }
            if (op === "TD") state.leading = -v[1];
            line = translate(line, v[0], v[1]);
            fresh = true;
            continue;
        }
        if (op === "T*") { line = translate(line, 0, -state.leading); fresh = true; continue; }
        if (op === "TL") { v = numbers(entry, 1); if (v !== null) state.leading = v[0]; continue; }
        if (op === "Tc") { v = numbers(entry, 1); if (v !== null) state.charSpacing = v[0]; continue; }
        if (op === "Tw") { v = numbers(entry, 1); if (v !== null) state.wordSpacing = v[0]; continue; }
        if (op === "Tz") { v = numbers(entry, 1); if (v !== null) state.scale = v[0] / 100; continue; }
        if (op === "Tm") {
            v = numbers(entry, 6);
            if (v === null) { candidate = null; fresh = false; continue; }
            line = v;
            fresh = true;
            continue;
        }
        if (op === "Tf") {
            if (entry.operands.length === 2 && entry.operands[0].type === "name" && entry.operands[1].type === "number") {
                state.font = entry.operands[0].value;
                state.size = entry.operands[1].value;
            }
            continue;
        }
        if (SHOW[op]) {
            if (op === "'" || op === '"') { line = translate(line, 0, -state.leading); fresh = true; }
            if (candidate !== null) {
                if (fresh && sameOrigin(candidate.origin, line, candidate.size)) edits.push(candidate.edit);
                candidate = null;
            }
            var info = state.font === null ? null : fonts[state.font];
            if (op === "Tj" && fresh && info && entry.operands.length === 1 && entry.operands[0].type === "string") {
                var token = entry.operands[0];
                var codes = codesOf(token.bytes, info.width);
                if (codes !== null && info.codes[codes[codes.length - 1]]) {
                    var keep = codes.length;
                    while (keep > 0 && info.codes[codes[keep - 1]]) keep--;
                    var moved = advance(codes, keep, info, state);
                    if (moved !== null) {
                        candidate = {
                            origin: translate(line, moved, 0),
                            size: state.size,
                            edit: keep === 0
                                ? { start: entry.start, end: entry.end, text: "" }
                                : { start: token.start, end: token.end, text: hexString(token.bytes.slice(0, keep * info.width)) }
                        };
                    }
                }
            }
            fresh = false;
            continue;
        }
        if (NEUTRAL[op]) continue;
        if (op === "q") stack.push(copyState(state));
        else if (op === "Q" && stack.length > 0) state = stack.pop();
        candidate = null;
    }
    return edits;
}

function applyEdits(buffer, edits) {
    var result = new Buffer();
    var e = 0;
    var i = 0;
    while (i < buffer.length) {
        if (e < edits.length && i === edits[e].start) {
            var text = edits[e].text;
            for (var k = 0; k < text.length; k++) result.writeByte(text.charCodeAt(k));
            i = edits[e].end;
            e++;
            continue;
        }
        result.writeByte(buffer[i]);
        i++;
    }
    return result;
}

// ------------------------------------------------------------ Durchlauf

var done = {};
var cleaned = 0;

function streamKey(obj) {
    return obj.isIndirect() ? String(obj.asIndirect()) : null;
}

function repairStream(stream, fonts) {
    var key = streamKey(stream);
    if (key !== null) {
        if (done[key]) return;
        done[key] = true;
    }
    var buffer = stream.readStream();
    var edits = phantomEdits(buffer, fonts);
    if (edits.length === 0) return;
    stream.writeStream(applyEdits(buffer, edits));
    cleaned += edits.length;
}

// Form-XObjects der Ressourcen, mit eigenen Ressourcen oder denen des Aufrufers
function repairForms(resources, depth) {
    if (isMissing(resources) || depth > 16) return;
    var xobjects = resources.get("XObject");
    if (isMissing(xobjects) || !xobjects.isDictionary()) return;
    eachEntry(xobjects, function (key, xobject) {
        if (isMissing(xobject) || !xobject.isStream() || nameOf(xobject.get("Subtype")) !== "Form") return;
        var formKey = streamKey(xobject);
        if (formKey !== null && done[formKey]) return;
        var own = xobject.get("Resources");
        var formResources = isMissing(own) ? resources : own;
        repairStream(xobject, fontInfo(formResources));
        repairForms(formResources, depth + 1);
    });
}

for (var p = 0; p < count; p++) {
    var page = doc.findPage(p);
    var resources = lookup(page, "Resources");
    var fonts = fontInfo(resources);
    var contents = page.get("Contents");
    if (!isMissing(contents)) {
        if (contents.isArray()) {
            for (var c = 0; c < contents.length; c++) repairStream(contents.get(c), fonts);
        } else if (contents.isStream()) {
            repairStream(contents, fonts);
        }
    }
    repairForms(resources, 0);
}

if (cleaned > 0) doc.save(output, "garbage,compress");
print(cleaned);
print(count);
