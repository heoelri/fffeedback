# FFFeedback – anonyme Umfrage für die Feuerwehr

Web-basierte, anonyme Mitgliederbefragung für die Einsatzabteilung der **Einheit Dahlbruch der Feuerwehr Hilchenbach**. Das Tool lässt sich für jede weitere Umfrage wiederverwenden.

## Projekt und Ziele

**Warum?** Wir wollen besser werden. Dafür müssen wir wissen, was gut läuft und was schlecht läuft – was wir verbessern müssen und was nicht.

Zum Jahresende werden alle Angehörigen der Einsatzabteilung per E-Mail eingeladen. Befragt wird zu diesen Themen: Gesamteindruck, Beteiligung, Einsätze, Atemschutz, Gerätehaus, Schutzausrüstung, Ausbildung, Übungsdienste, Zusammenarbeit sowie Kommunikation und Führung.

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
| Auswertung | Nach Ende der Umfrage: Verteilungen, Mittelwerte, Kommentare und Freitexte |
| Auswertung nach Altersklasse und Qualifikation | Filter im Bericht, nur für Gruppen ab 5 Antworten |
| Erinnerungen | Ein Klick schreibt allen, die noch nicht abgesendet haben |
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
6. **Freitexte sind sortiert**, nicht in Speicherreihenfolge. So lassen sich die Texte einer Person nicht über mehrere Fragen hinweg zusammensetzen.
7. **Datensparsamkeit.** Die Anwendung speichert keine IP-Adressen und setzt `Referrer-Policy: no-referrer`. Beim Beenden der Umfrage werden alle E-Mail-Adressen gelöscht.

**Verbleibendes Risiko:** Wer während der Umfrage Zugriff auf Webspace **und** Datenbank hat, könnte Entwürfe vor dem Absenden lesen. Den Webspace sollte deshalb möglichst jemand betreuen, der **nicht** zur Einheitsführung gehört. Der Webserver des Hosters protokolliert in der Regel IP-Adressen und aufgerufene URLs. Diese Logs enthalten aber keine Antworten. Freitexte können durch ihren Inhalt Rückschlüsse zulassen, darauf weist die Umfrage hin.

## Architektur

Bewusst minimal, damit das Tool auf günstigem Webspace läuft und auch Ehrenamtliche es warten können:

- **Server:** PHP ≥ 8.1 mit `pdo_mysql` und `openssl`, dazu MySQL ≥ 5.7 oder MariaDB ≥ 10.3. Es gibt keine Abhängigkeiten, kein Composer, keinen Build-Schritt, kein `.htaccess`/mod_rewrite und keinen Cronjob. Die Tabellen legt die Anwendung beim ersten Aufruf von `admin.php` selbst an.
- **E-Mail:** PHPs `mail()`, das jeder Webspace-Anbieter bereitstellt.
- **Frontend:** HTML, CSS und Vanilla-JS. Der Server prüft alle Eingaben selbst und verwirft ungültige Werte sowie Antworten auf ausgeblendete Fragen. Tests stellen sicher, dass `logic.js` (Browser) und `lib.php` (Server) die gleichen Regeln anwenden.

```
public/                   ← dieser Ordner kommt auf den Webspace
  index.php               Umfrage-Seite und JSON-API (?t=TOKEN)
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
   - `admin_password`: Passwort für die Adminoberfläche, mindestens 8 Zeichen
   - `base_url`: Adresse des Ordners, z. B. `https://www.feuerwehr-example.de/umfrage`
   - `mail_from`: Absender, am besten ein Postfach **der eigenen Domain beim selben Hoster**. Das verringert die Gefahr, dass Mails im Spam landen.
3. Den **Inhalt** von `public/` per FTP in einen Ordner auf dem Webspace hochladen, z. B. `/umfrage`.
4. **HTTPS** für die Domain aktivieren. Das bieten fast alle Hoster kostenlos über Let's Encrypt an.
5. `https://…/umfrage/admin.php` öffnen und anmelden.

**Hinweis zu E-Mail-Limits:** Viele Hoster begrenzen die Zahl der Mails pro Stunde. Bei vielen Adressen sollten die Einladungen deshalb in Blöcken verschickt werden. Adressen, die schon eingeladen sind, werden übersprungen. Nicht zugestellte Einladungen sind in der Liste markiert und lassen sich mit „Erneut einladen“ nachholen.

## Ablauf einer Umfrage

1. **Vorab testen:** 2–3 Kameradinnen und Kameraden füllen die Umfrage testweise aus. Danach die Tabellen in der Datenbank löschen (z. B. mit phpMyAdmin). Sie werden beim nächsten Aufruf von `admin.php` neu angelegt.
2. **Importieren:** In `admin.php` die Datei `dahlbruch-2026.json` auswählen und auf „Importieren“ klicken. Solange die Umfrage läuft, übernimmt ein erneuter Import Textänderungen.
3. **Einladen:** Die E-Mail-Adressen einfügen, eine pro Zeile oder durch Komma getrennt, und auf „Einladungen senden“ klicken.
4. **Erneut einladen:** Hat jemand die Mail nicht bekommen oder gelöscht, in der Liste auf „Erneut einladen“ klicken.
5. **Erinnern:** Nach ca. 1 und 2 Wochen auf „Erinnerung an … Personen senden“ klicken.
6. **Beenden:** Nach ca. 3–4 Wochen die Umfrage beenden. Danach sind keine Antworten mehr möglich, alle E-Mail-Adressen werden gelöscht und die Auswertung wird freigeschaltet.
7. **Auswerten:** Die Ergebnisse vorstellen und Maßnahmen festlegen.

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

Die Datenbank lässt sich über `DB_DSN`, `DB_USER` und `DB_PASS` anpassen, z. B. `DB_DSN='mysql:host=127.0.0.1;port=13306;dbname=fff_test;charset=utf8mb4'`. Der Datenbankname muss „test“ enthalten, weil die Tests die Tabellen löschen. Ist keine Datenbank erreichbar, wird der Ende-zu-Ende-Test übersprungen. In CI zählt das als Fehler.

**Lokal ausprobieren:**

```sh
FFF_CONFIG="$PWD/tests/config.php" php -S 127.0.0.1:8123 -t public
```

Danach `http://127.0.0.1:8123/admin.php` öffnen, das Passwort ist `geheim123`. Die E-Mails landen in `fffeedback-test-mail.log` im Temp-Ordner.

**Mit Docker Compose:** `docker compose up` startet MySQL und den PHP-Server (Port 8123) ohne lokale Installation. Beim Start importiert `tools/seed-local.php` automatisch die Beispielumfrage und lädt `test@example.org` ein; der fertige Umfrage-Link und die Admin-Zugangsdaten stehen danach im Log der Zeile `web`. Admin: `http://127.0.0.1:8123/admin.php` (Passwort `geheim123`). Mit `docker compose down -v` wird die Datenbank wieder geleert.

GitHub Actions (`.github/workflows/ci.yml`) führt bei jedem Push und Pull Request alle Tests aus: mit PHP 8.1 + MariaDB 10.6 und mit PHP 8.4 + MySQL 8.4. Außerdem prüft der Workflow, ob der Fragenkatalog aktuell ist.

## Lizenz

[MIT](LICENSE)
