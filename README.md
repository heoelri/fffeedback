# FFFeedback – anonyme Umfrage für die Feuerwehr

Web-basierte, anonyme Mitgliederbefragung für die Einsatzabteilung der **Einheit Dahlbruch der Feuerwehr Hilchenbach**. Das Tool lässt sich für jede weitere Umfrage wiederverwenden.

## Projekt und Ziele

**Warum?** Wir wollen besser werden. Dafür müssen wir wissen, was gut läuft und was schlecht läuft – was wir verbessern müssen und was nicht.

Zum Jahresende werden alle Angehörigen der Einsatzabteilung per E-Mail eingeladen. Befragt wird zu diesen Themen: Gesamteindruck, Beteiligung, Einsätze, Atemschutz, Gerätehaus, Schutzausrüstung, Ausbildung, Übungsdienste, Zusammenarbeit, Kommunikation und Führung sowie Anerkennung und Wertschätzung (inkl. Übungs-/Einsatzgeld).

Ziele:

- **Ehrliche Antworten** durch echte, technisch umgesetzte Anonymität
- **Hohe Beteiligung** durch kurze, klare Fragen, Nutzung am Handy, Zwischenspeichern und Erinnerungen
- **Konkrete Verbesserungen** durch Freitext überall, besonders bei negativen Antworten
- **Vergleichbarkeit über die Jahre:** Die Umfrage ist eine JSON-Datei und kann jedes Jahr wiederverwendet werden
- **Einfacher Betrieb:** Läuft auf jedem normalen Webspace mit PHP und MySQL

## Funktionen

| Anforderung | Umsetzung |
|---|---|
| Web-basiert, für Handys optimiert | Eine Kategorie pro Seite, große Antwortflächen, Fortschrittsbalken, Dark Mode |
| Einladung per E-Mail mit Link | Adminoberfläche: Adressen einfügen → jede Person bekommt einen persönlichen Link |
| Erneut einladen | Button „Erneut einladen“ je Person, z. B. wenn die Mail im Spam gelandet ist. Der Link bleibt gleich |
| Zwischenspeichern, Absenden nur auf Wunsch | Automatisches Speichern während der Eingabe. Nach „Absenden“ sind keine Änderungen mehr möglich |
| Anonymität | Siehe [Anonymitätskonzept](#anonymitätskonzept) |
| Nutzungsstatistik | Eingeladen, Begonnen, Abgesendet, versendete Erinnerungen, Status je Einladung |
| Auswertung | Nach Ende der Umfrage: Verteilungen, Mittelwerte, Kommentare und Freitexte, CSV-Export der Zahlen |
| Auswertung nach Altersklasse, Mitgliedsdauer und Qualifikation | Filter im Bericht, nur für Gruppen ab 5 Antworten, ohne Freitexte |
| Erinnerungen | Ein Klick (mit Bestätigung) schreibt allen, die noch nicht abgesendet haben |
| Abhängigkeiten zwischen Fragen | `showIf`, z. B. Fragen zu Lehrgängen nur für Personen, die Lehrgänge besucht haben |
| Freitext zu jeder Kategorie | Automatisch am Ende jeder Kategorie. Jede Auswahlfrage hat ein optionales Kommentarfeld |
| Hinweis bei negativen Antworten | Bei „schlecht“ oder „Nein“ öffnet sich das Kommentarfeld mit der Frage „Was läuft nicht gut?“ |
| Wiederverwendbar | Neue Umfrage = neue JSON-Datei in `public/surveys/` |

## Anonymitätskonzept

1. **Trennung beim Absenden.** In einer Transaktion wird die Einladung als „abgesendet“ markiert und die Antwort in die Tabelle `responses` geschrieben. Diese Tabelle hat **keine** Verbindung zur Einladung, keine Zeitstempel und eine zufällige ID. Eine Antwort lässt sich so weder über Reihenfolge noch über Zeitpunkt einer Person zuordnen.
2. **Verschlüsselte Entwürfe.** Solange noch nicht abgesendet wurde, gehört der Entwurf zur Einladung. Er ist deshalb mit AES-256-GCM verschlüsselt. Datenbank-Dumps und Backups sind ohne `app_secret` nicht lesbar. Beim Absenden wird der Entwurf gelöscht.
3. **Keine Klartext-Links.** Der Link-Token wird aus `app_secret` abgeleitet (HMAC). In der Datenbank steht nur sein SHA-256-Hash.
4. **Auswertung erst nach dem Ende.** Solange die Umfrage läuft, zeigt die Adminoberfläche nur die Beteiligung. So kann niemand die Auswertung vor und nach der Teilnahme einer bestimmten Person vergleichen.
5. **Mindestgruppengröße (k = 5).** Gefilterte Auswertungen erscheinen nur, wenn die Gruppe **und** alle übrigen Antworten jeweils mindestens 5 umfassen. Pro Ansicht gibt es nur einen Filter, damit kleine Gruppen nicht durch den Vergleich von Ansichten herausgerechnet werden können. Das Geschlecht ist bewusst kein Filter.
6. **Freitexte sind sortiert**, nicht in Speicherreihenfolge. So lassen sich die Texte einer Person nicht über mehrere Fragen hinweg zusammensetzen. Gefilterte Ansichten zeigen **keine** Freitexte und Kommentare: Taucht ein Text unter zwei Filtern auf (z. B. Alter und Qualifikation), wäre seine Autorin oder sein Autor sonst auf die Schnittmenge eingegrenzt.
7. **Datensparsamkeit.** Die Anwendung speichert keine IP-Adressen und setzt `Referrer-Policy: no-referrer`. Beim Beenden der Umfrage werden alle E-Mail-Adressen gelöscht.

**Verbleibendes Risiko:** Wer während der Umfrage Zugriff auf Webspace **und** Datenbank hat, könnte Entwürfe vor dem Absenden lesen. Den Webspace sollte deshalb möglichst jemand betreuen, der **nicht** zur Einheitsführung gehört. Der Webserver des Hosters protokolliert in der Regel IP-Adressen und aufgerufene URLs. Diese Logs enthalten aber keine Antworten. Freitexte können durch ihren Inhalt Rückschlüsse zulassen, darauf weist die Umfrage hin.

## Architektur

Bewusst minimal, damit das Tool auf günstigem Webspace läuft und auch Ehrenamtliche es warten können:

- **Server:** PHP ≥ 8.1 mit `pdo_mysql` und `openssl`, dazu MySQL ≥ 5.7 oder MariaDB ≥ 10.3. Es gibt keine Abhängigkeiten, kein Composer, keinen Build-Schritt, kein mod_rewrite und keinen Cronjob. Die mitgelieferte `.htaccess` setzt nur `DirectoryIndex index.php` für ältere Links ohne `index.php`; die Links in den Einladungen zeigen immer direkt auf `index.php`. Die Tabellen legt die Anwendung beim ersten Aufruf von `admin.php` selbst an.
- **E-Mail:** PHPs `mail()`, sofern der Hoster es aktiviert hat (bei den meisten Webspaces der Fall), oder optional SMTP mit STARTTLS (ohne Bibliothek).
- **Frontend:** HTML, CSS und Vanilla-JS. Der Server prüft alle Eingaben selbst und verwirft ungültige Werte sowie Antworten auf ausgeblendete Fragen. Tests stellen sicher, dass `logic.js` (Browser) und `lib.php` (Server) die gleichen Regeln anwenden.

```
public/                   ← dieser Ordner kommt auf den Webspace
  index.php               Umfrage-Seite und JSON-API (index.php?t=TOKEN)
  .htaccess               nur DirectoryIndex (Apache), optional
  admin.php               Adminoberfläche: importieren, einladen, erinnern, beenden, auswerten
  lib.php                 Datenbank, Verschlüsselung, Regeln, Auswertung, E-Mail
  app.js, logic.js, style.css
  config.example.php      Vorlage für config.php
  surveys/*.json          Umfragen (Fragenkatalog)
tests/                    PHP- und JS-Tests
tools/catalog.mjs         erzeugt docs/fragenkatalog.md aus der JSON-Datei
```

## Fragenkatalog

Der vollständige Katalog steht in **[docs/fragenkatalog.md](docs/fragenkatalog.md)**. Die Datei wird aus `public/surveys/dahlbruch-2026.json` erzeugt. CI prüft, ob beides übereinstimmt.

Angewendete Best Practices für Mitgliederbefragungen:

- **Kurz:** ca. 10–15 Minuten, alle Fragen freiwillig, eine Kategorie pro Seite
- **Einheitliche 5er-Skalen** mit beschrifteten Stufen und „Kann ich nicht beurteilen“. Es gibt keine erzwungenen Antworten
- **Eine Aussage pro Frage:** Zusammengefasste Themen (z. B. „DLRG, DRK und THW“) sind in einzelne Fragen aufgeteilt
- **Leichter Einstieg, Persönliches am Ende:**
  - Die Umfrage beginnt mit dem Gesamteindruck, damit dieser unbeeinflusst bleibt.
  - Die offenen Fragen „Was lief gut/schlecht?“ folgen erst nach den Themenblöcken, wenn schon nachgedacht wurde.
  - Angaben zur Person (Alter, Geschlecht, Qualifikation) stehen ganz am Ende. Das senkt Abbrüche und Anonymitätsbedenken.
- **Altersgruppen ohne Überschneidung:** 18–29, 30–45, 46 und älter. Beim Geschlecht ist „Divers / keine Angabe“ ergänzt
- **Positiv und negativ fragen**, dazu gezielte Nachfragen („Warum?“) nur bei Bedarf
- **Den Kreis schließen:** Ergebnisse und Maßnahmen allen vorstellen. Das ist der wichtigste Faktor für die Beteiligung im nächsten Jahr

## Installation auf dem Webspace

1. Beim Hoster eine **MySQL-Datenbank** anlegen und Zugangsdaten notieren.
2. `public/config.example.php` nach `public/config.php` kopieren und ausfüllen:
   - `db_dsn`, `db_user`, `db_pass`: Zugangsdaten der Datenbank
   - `app_secret`: mindestens 32 zufällige Zeichen, z. B. von `php -r "echo bin2hex(random_bytes(24));"` oder einem Passwortgenerator. **Darf während einer laufenden Umfrage nicht geändert werden**, sonst werden alle Links und Entwürfe ungültig.
   - `admin_password`: Passwort für die Adminoberfläche, mindestens 12 Zeichen
   - `base_url`: Adresse des Ordners, z. B. `https://www.feuerwehr-example.de/umfrage`
   - `mail_from`: Absender, am besten ein Postfach **der eigenen Domain beim selben Hoster**. Das verringert die Gefahr, dass Mails im Spam landen.
   - Optional, empfohlen: `smtp_host`, `smtp_port`, `smtp_username`, `smtp_password` für den Versand über das Postfach aus `mail_from` (bei Strato: `smtp.strato.de`, Port `587`, Benutzername = vollständige E-Mail-Adresse). Ist `smtp_host` gesetzt, wird statt PHPs `mail()` per SMTP verschickt. Das ist zuverlässiger und funktioniert auch, wenn der Hoster `mail()` deaktiviert hat. Die Verbindung wird immer per STARTTLS mit geprüftem Zertifikat verschlüsselt, bevor die Zugangsdaten gesendet werden.
3. Den **Inhalt** von `public/` in einen Ordner auf dem Webspace hochladen, z. B. `/umfrage`. Das geht per FTP-Programm (z. B. FileZilla) oder automatisch, siehe [Automatisches Deployment](#automatisches-deployment-mit-github-actions). `config.php` gehört in denselben Ordner.
4. **HTTPS** für die Domain aktivieren. Das bieten fast alle Hoster kostenlos über Let's Encrypt an.
5. `https://…/umfrage/admin.php` öffnen und anmelden.

### Für die eigene Feuerwehr nutzen

1. Das Repository auf GitHub **forken** (oder den Code herunterladen).
2. Die Umfrage anpassen, siehe [Neue Umfrage erstellen](#neue-umfrage-erstellen).
3. Wie oben installieren, von Hand oder mit dem Workflow unten.

### Automatisches Deployment mit GitHub Actions

`.github/workflows/deploy.yml` lädt `public/` per **SFTP** oder **FTPS** auf den Webspace:

- automatisch, sobald die Tests (CI) nach einem Push auf `main` erfolgreich waren. Ist `main` inzwischen weiter, wird der ältere Stand übersprungen.
- von Hand unter *Actions → Deploy → Run workflow*

Ohne Konfiguration wird der Workflow übersprungen. Einrichtung:

1. `config.php` **einmalig von Hand** in den Zielordner hochladen (siehe oben). Der Workflow überträgt und überschreibt sie nie. So stehen `app_secret` und Passwörter nicht in GitHub.
2. Im Repository unter *Settings → Environments* eine Umgebung **`webspace`** anlegen und dort das **Secret** `DEPLOY_PASSWORD` (Passwort des FTP- bzw. SFTP-Benutzers) eintragen. Empfohlen: Unter *Required reviewers* eine Person eintragen, dann muss jedes Deployment bestätigt werden.
3. Unter *Settings → Secrets and variables → Actions → **Variables*** diese **Repository-Variablen** anlegen (nicht in der Umgebung, sonst sieht der Workflow sie beim Start nicht):

   | Name | Beispiel |
   |---|---|
   | `DEPLOY_URL` | `sftp://ssh.example-hoster.de/html/umfrage` oder `ftp://ftp.example-hoster.de/umfrage` |
   | `DEPLOY_USER` | FTP- bzw. SFTP-Benutzer des Hosters |
   | `DEPLOY_KNOWN_HOSTS` | nur bei `sftp://`, Pflicht: Ausgabe von `ssh-keyscan ssh.example-hoster.de`, also Zeilen der Form `host ssh-ed25519 AAAA…`. Am besten mit dem Fingerabdruck vergleichen, den der Hoster veröffentlicht (`ssh-keygen -lf datei`). Meldet `ssh-keyscan` nur `unsupported KEX method` (älteres OpenSSH unter Windows), stattdessen `docker run --rm alpine sh -c "apk add -q openssh-client && ssh-keyscan ssh.example-hoster.de"` verwenden |
   | `SITE_URL` (optional) | `https://www.feuerwehr-example.de/umfrage`. Danach wird geprüft, ob `admin.php` erreichbar ist |

   Der Pfad in `DEPLOY_URL` ist der Zielordner, so wie ihn ein FTP-Programm nach dem Anmelden anzeigt. Beispiel: `sftp://…/umfrage` lädt nach `/umfrage`, das bei Bedarf samt übergeordneter Ordner angelegt wird. Die Anwendung läuft in jedem Unterordner. Wichtig ist nur, dass `base_url` in `config.php` auf genau diesen Ordner zeigt, z. B. `https://www.feuerwehr-example.de/umfrage`, sonst stimmen die Links in den Einladungen nicht.

Gut zu wissen:

- **Verschlüsselung ist Pflicht.** Erlaubt sind nur `sftp://`, `ftp://` (der Workflow erzwingt TLS, also FTPS) und `ftps://`. Andere Angaben brechen ab. Kann der Hoster das nicht, stattdessen `sftp://` verwenden. Bei `sftp://` akzeptiert der Workflow nur den Server-Schlüssel aus `DEPLOY_KNOWN_HOSTS`.
- **Zielordner genau prüfen.** Gelöscht wird nichts: Dateien, die es im Repository nicht mehr gibt, bleiben auf dem Server und müssen bei Bedarf von Hand entfernt werden. Gleichnamige Dateien werden aber **überschrieben**. Zeigt `DEPLOY_URL` z. B. auf das Hauptverzeichnis der Website, wird dort eine vorhandene `index.php` ersetzt. Deshalb immer einen eigenen, leeren Ordner wie `/umfrage` verwenden.
- **SFTP-Startordner ≠ Ordner der Domain.** Bei vielen Hostern (z. B. Strato) zeigt die Domain auf einen Unterordner des Webspace. Dann gehört dieser Ordner in den Pfad, z. B. `sftp://…/<domain-ordner>/umfrage`. Welcher Ordner das ist, steht im Kundenmenü bei der Domain (Ziel- bzw. Stammverzeichnis). Liefert der Schritt „Check site“ 404, obwohl der Upload geklappt hat, liegt es fast immer daran.
- **Deployment während einer laufenden Umfrage ist möglich.** Links und Entwürfe bleiben gültig, weil `config.php` (und damit `app_secret`) unverändert bleibt.
- **Anonymität:** Wer das Secret `DEPLOY_PASSWORD` verwalten kann, hat Zugriff auf den Webspace. Dafür gilt dasselbe wie im [Anonymitätskonzept](#anonymitätskonzept): Möglichst nicht die Einheitsführung.

**Hinweis zu E-Mail-Limits:** Viele Hoster begrenzen die Zahl der Mails pro Stunde. Bei vielen Adressen sollten die Einladungen deshalb in Blöcken verschickt werden. Adressen, die schon eingeladen sind, werden übersprungen. Nicht zugestellte Einladungen sind in der Liste markiert und lassen sich mit „Erneut einladen“ nachholen.

## Ablauf einer Umfrage

1. **Vorab testen:** 2–3 Kameradinnen und Kameraden füllen die Umfrage testweise aus. Danach in `admin.php` „Umfrage vollständig löschen“ wählen und die Umfrage neu importieren.
2. **Importieren:** In `admin.php` die Datei `dahlbruch-2026.json` auswählen und auf „Importieren“ klicken. Solange die Umfrage läuft, übernimmt ein erneuter Import Textänderungen.
3. **Einladen:** Die E-Mail-Adressen einfügen, eine pro Zeile oder durch Komma getrennt, und auf „Einladungen senden“ klicken.
4. **Erneut einladen:** Hat jemand die Mail nicht bekommen oder gelöscht, in der Liste auf „Erneut einladen“ klicken.
5. **Erinnern:** Nach ca. 1 und 2 Wochen das Häkchen setzen und auf „Erinnerung an … Personen senden“ klicken. Ein versehentlicher Doppelklick verschickt nichts doppelt.
6. **Beenden:** Nach ca. 3–4 Wochen die Umfrage beenden. Danach sind keine Antworten mehr möglich, alle E-Mail-Adressen werden gelöscht und die Auswertung wird freigeschaltet.
7. **Auswerten:** Die Ergebnisse als PDF herunterladen oder als CSV (ohne Freitexte) in Excel weiterverarbeiten. Mittelwerte gelten für Stufe 1 = beste Antwort bis 5 = schlechteste Antwort.

## Neue Umfrage erstellen

Kopiere `public/surveys/dahlbruch-2026.json` und ändere `slug`, Texte und Fragen. Lade die Datei dann in `surveys/` hoch und importiere sie in `admin.php`. Die Datei hat dieses Format:

```jsonc
{
  "slug": "dahlbruch-2027",                    // a-z, 0-9, -
  "title": "…", "intro": "…", "anonymity": "…",
  "reportFilters": ["alter", "qualifikation"], // nur single-Fragen
  "mail":     { "subject": "… {title}", "text": "… {link} …" },
  "reminder": { "subject": "…", "text": "… {link} …" },
  "sections": [{
    "id": "atemschutz", "title": "Atemschutz", "description": "optional",
    "freeText": false,                         // optional: kein automatisches Freitextfeld
    "questions": [
      { "id": "agt", "type": "single", "text": "Bist du atemschutztauglich?", "options": ["Ja", "Nein"] },
      { "id": "agt_warum", "type": "multi", "text": "Warum nicht?", "options": ["Alter", "…"],
        "showIf": { "q": "agt", "in": ["Nein"] } },   // nur frühere Fragen
      { "id": "termine", "type": "scale", "scale": "agree", "text": "…" },  // scale: rate (Standard) | agree
      { "id": "x", "type": "single", "options": ["Ja", "Nein"], "negative": ["Nein"], "text": "…" },
      { "id": "y", "type": "text", "text": "…" }
    ]
  }]
}
```

Bei `scale` gilt: Stufe 1 ist die positivste Antwort, Stufen 4–5 gelten automatisch als negativ. Bei `single`/`multi` legt `negative` fest, welche Antworten den Hinweis „Was läuft nicht gut?“ auslösen. Der Import prüft die Datei und meldet Fehler. Das Gleiche tun die Tests für alle Dateien in `public/surveys/`.

## Entwicklung und Tests

Voraussetzungen: PHP ≥ 8.1 (mit `pdo_mysql`, `openssl`), Node.js ≥ 22 (nur für Tests und den Katalog-Generator), Docker (für die Test-Datenbank).

```sh
docker run -d --name fff-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=fff_test -p 3306:3306 mysql:8.4

php tests/run.php                        # Unit-Tests + kompletter Ablauf gegen die DB (startet php -S)
node --test 'tests/*.test.mjs'           # Browser-Logik und Gleichheit mit dem PHP-Server
node tools/catalog.mjs public/surveys/dahlbruch-2026.json > docs/fragenkatalog.md
```

Die Datenbank lässt sich über `DB_DSN`, `DB_USER` und `DB_PASS` anpassen, z. B. `DB_DSN='mysql:host=127.0.0.1;port=13306;dbname=fff_test;charset=utf8mb4'`. Der Datenbankname muss „test“ enthalten, weil die Tests die Tabellen löschen. Der Ende-zu-Ende-Test startet die Anwendung unter `http://127.0.0.1:8124/umfrage/`, also wie auf einem Webspace in einem Unterordner. Ist keine Datenbank erreichbar, wird der Ende-zu-Ende-Test übersprungen. In CI zählt das als Fehler.

**Lokal ausprobieren:**

```sh
FFF_CONFIG="$PWD/tests/config.php" php -S 127.0.0.1:8123 -t public
```

Danach `http://127.0.0.1:8123/admin.php` öffnen, das Passwort ist `geheim-lokal`. Die E-Mails landen in `fffeedback-test-mail.log` im Temp-Ordner.

**Mit Docker Compose:** `docker compose up` startet MySQL und den PHP-Server (Port 8123) ohne lokale Installation. Beim Start importiert `tools/seed-local.php` automatisch die Beispielumfrage und lädt `test@example.org` ein; der fertige Umfrage-Link und die Admin-Zugangsdaten stehen danach im Log der Zeile `web`. Admin: `http://127.0.0.1:8123/admin.php` (Passwort `geheim-lokal`). Mit `docker compose down -v` wird die Datenbank wieder geleert.

GitHub Actions (`.github/workflows/ci.yml`) führt bei jedem Push auf `main` und jedem Pull Request alle Tests aus: mit PHP 8.1 + MariaDB 10.6 und mit PHP 8.4 + MySQL 8.4. Außerdem prüft der Workflow, ob der Fragenkatalog aktuell ist.

Bei jedem Pull Request, der `public/` betrifft, erzeugt `.github/workflows/ui-screenshots.yml` per Playwright Screenshots der Umfrage- und Admin-Seiten (`tests/ui-screenshots.mjs`). `.github/workflows/ui-screenshot-comment.yml` bettet sie anschließend als Kommentar in den Pull Request ein. Die Bilder liegen im Branch `ui-screenshots-pr-<Nummer>`, der beim Schließen des Pull Requests gelöscht wird (`ui-screenshot-cleanup.yml`).

`.github/workflows/deploy.yml` bringt `main` nach erfolgreicher CI auf den Webspace, siehe [Automatisches Deployment](#automatisches-deployment-mit-github-actions).

## Lizenz

[MIT](LICENSE)
