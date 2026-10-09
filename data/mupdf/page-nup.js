// Legt mehrere Seiten verkleinert auf ein Blatt ("Seiten pro Blatt", N-up).
//
// Aufruf (mutool run):
//   page-nup.js <lib.js> <eingabe> <ausgabe> <proBlatt> <blatt> <abstandMm> <ausrichtung>
//
// <proBlatt>    2, 4, 6 oder 9
// <blatt>       a3, a4, a5, letter, legal
// <abstandMm>   Abstand zwischen den Seiten und zum Blattrand in Millimetern
// <ausrichtung> auto (quer bei 2 und 6 quer liegenden Seiten, sonst hoch),
//               portrait oder landscape
//
// Reihenfolge auf dem Blatt: zeilenweise, links -> rechts, oben -> unten. Jede
// Seite wird gleichmaessig so verkleinert, dass sie in ihre Zelle passt, und
// darin zentriert; ihre Drehung (/Rotate) und ihr sichtbarer Bereich (CropBox)
// werden beruecksichtigt. Nichts wird gerendert: Die Seiten werden als
// Form-XObjects eingebettet, Text bleibt Text.

var SCRIPT = "page-nup";
load(scriptArgs[0]);

if (args.length < 6) fail("zu wenige Argumente");

var input = args[0];
var output = args[1];
var perSheet = intArg(2, "Seiten je Blatt");
var sheetName = args[3];
var gap = mmToPt(floatArg(4, "Abstand"));
var orientation = String(args[5]).toLowerCase();

var GRIDS = { 2: [1, 2], 4: [2, 2], 6: [3, 2], 9: [3, 3] };
var grid = GRIDS[perSheet];
if (!grid) fail("Seiten je Blatt muss 2, 4, 6 oder 9 sein");
var rows = grid[0], cols = grid[1];

var doc = openPdf(input);
var count = doc.countPages();

// Anzeigemasse der Seiten, um die Blattausrichtung zu waehlen
var displayed = [];
for (var i = 0; i < count; i++) {
    var page = doc.findPage(i);
    var box = visibleBox(page);
    var turned = rotation(page) === 90 || rotation(page) === 270;
    displayed.push(turned ? [box[3] - box[1], box[2] - box[0]] : [box[2] - box[0], box[3] - box[1]]);
}

var landscape;
if (orientation === "landscape") landscape = true;
else if (orientation === "portrait") landscape = false;
else {
    // Zellen sollen zur Mehrheit der Seiten passen: Hochformatseiten zu zweit
    // nebeneinander brauchen ein Querblatt, zu viert passen sie hoch
    var wide = 0;
    for (var w = 0; w < displayed.length; w++) if (displayed[w][0] > displayed[w][1]) wide++;
    var pagesAreWide = wide > displayed.length / 2;
    landscape = (cols > rows) !== pagesAreWide;
}

var sheet = sheetSize(sheetName, landscape);
var cellWidth = (sheet[0] - gap * (cols + 1)) / cols;
var cellHeight = (sheet[1] - gap * (rows + 1)) / rows;
if (cellWidth <= 1 || cellHeight <= 1) fail("Abstand ist groesser als das Blatt");

var out = new PDFDocument();
var sheets = 0;

for (var start = 0; start < count; start += perSheet) {
    var resources = out.newDictionary();
    var xobjects = out.newDictionary();
    resources.put("XObject", xobjects);
    var content = "";

    for (var slot = 0; slot < perSheet && start + slot < count; slot++) {
        var index = start + slot;
        var src = doc.findPage(index);
        var b = visibleBox(src);
        var angle = rotation(src);
        var pw = b[2] - b[0], ph = b[3] - b[1];
        var dw = displayed[index][0], dh = displayed[index][1];
        var scale = Math.min(cellWidth / dw, cellHeight / dh);

        var row = Math.floor(slot / cols), col = slot % cols;
        var cellX = gap + col * (cellWidth + gap);
        var cellY = sheet[1] - gap - (row + 1) * cellHeight - row * gap;
        var x = cellX + (cellWidth - dw * scale) / 2;
        var y = cellY + (cellHeight - dh * scale) / 2;

        // Inhalt der Quellseite: erst in den Ursprung, dann wie angezeigt drehen,
        // dann skalieren und in die Zelle schieben
        var m;
        if (angle === 90) m = [0, -1, 1, 0, 0, pw];
        else if (angle === 180) m = [-1, 0, 0, -1, pw, ph];
        else if (angle === 270) m = [0, 1, -1, 0, ph, 0];
        else m = [1, 0, 0, 1, 0, 0];

        var name = "P" + slot;
        xobjects.put(name, pageAsXObject(out, doc, src));
        content += "q " + scale + " 0 0 " + scale + " " + x + " " + y + " cm "
            + m.join(" ") + " cm "
            + "1 0 0 1 " + (-b[0]) + " " + (-b[1]) + " cm "
            + "/" + name + " Do Q\n";
    }

    out.insertPage(-1, out.addPage([0, 0, sheet[0], sheet[1]], 0, resources, content));
    sheets++;
}

out.save(output, "garbage,compress");
print(sheets);
