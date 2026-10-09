// Passwortschutz einer PDF: pruefen, setzen, entfernen.
//
// Aufruf (mutool run):
//   pdf-protect.js <lib.js> probe   <eingabe>
//   pdf-protect.js <lib.js> encrypt <eingabe> <ausgabe> <nutzerPwDatei> <besitzerPwDatei> <rechte>
//   pdf-protect.js <lib.js> decrypt <eingabe> <ausgabe> <pwDatei>
//
// Passwoerter kommen aus Dateien, nie aus der Befehlszeile: Die stuende in
// der Prozessliste und im Protokoll. Die Datei enthaelt das Passwort, genau
// ein abschliessender Zeilenumbruch wird entfernt. Ein Komma darf das
// Passwort nicht enthalten: MuPDF trennt seine Speicheroptionen am Komma.
//
// <rechte> ist der Wert des /P-Eintrags (Bitmaske nach PDF 32000-1, 7.6.3.2);
// die Berechnung uebernimmt der Aufrufer.
//
// probe gibt "encrypted=yes|no needsPassword=yes|no" aus.
// decrypt endet mit Code 2 und der Zeile FALSCHES_PASSWORT, wenn das
// Passwort nicht passt; das Ergebnis wird dann nicht geschrieben.

var SCRIPT = "pdf-protect";
load(scriptArgs[0]);

if (args.length < 2) fail("zu wenige Argumente");

var mode = String(args[0]);
var input = args[1];

if (mode === "probe") {
    var probe = new PDFDocument(input);
    var trailer = probe.getTrailer();
    var encrypted = !isMissing(trailer.get("Encrypt"));
    print("encrypted=" + (encrypted ? "yes" : "no") + " needsPassword=" + (probe.needsPassword() ? "yes" : "no"));
} else if (mode === "encrypt") {
    if (args.length < 6) fail("zu wenige Argumente fuer encrypt");
    var output = args[2];
    var userPassword = readTextFile(args[3]);
    var ownerPassword = readTextFile(args[4]);
    var permissions = parseInt(args[5], 10);
    if (userPassword === "" && ownerPassword === "") fail("mindestens ein Passwort noetig");
    if (userPassword.indexOf(",") >= 0 || ownerPassword.indexOf(",") >= 0) fail("Passwort darf kein Komma enthalten");
    if (isNaN(permissions)) fail("Rechte fehlen");

    var doc = openPdf(input);
    doc.save(output, "garbage,compress,encrypt=aes-256,user-password=" + userPassword
        + ",owner-password=" + ownerPassword + ",permissions=" + permissions);
    print("ok");
} else if (mode === "decrypt") {
    if (args.length < 4) fail("zu wenige Argumente fuer decrypt");
    var out = args[2];
    var password = readTextFile(args[3]);

    var locked = new PDFDocument(input);
    if (locked.needsPassword() && !locked.authenticatePassword(password)) {
        print("FALSCHES_PASSWORT");
        quit(2);
    }
    locked.save(out, "garbage,compress,decrypt");
    print("ok");
} else {
    fail("unbekannter Modus: " + mode);
}
