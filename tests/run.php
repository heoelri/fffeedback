<?php
// Plain PHP tests, no framework: php tests/run.php
// Unit tests always run; the end-to-end test needs MySQL/MariaDB (see tests/config.php) and is required in CI.
declare(strict_types=1);
putenv('FFF_CONFIG=' . __DIR__ . '/config.php');
// The end-to-end test serves the app from a subfolder, as on a typical webspace.
putenv('FFF_BASE_URL=http://127.0.0.1:8124/umfrage');
require __DIR__ . '/../public/lib.php';

$failures = 0;
function check(string $name, callable $fn): void
{
    global $failures;
    try {
        $fn();
        echo "✔ $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "✘ $name\n  {$e->getMessage()} ({$e->getFile()}:{$e->getLine()})\n";
    }
}
function eq($actual, $expected, string $msg = ''): void
{
    if ($actual !== $expected) throw new Exception("$msg\n  erwartet: " . var_export($expected, true) . "\n  erhalten: " . var_export($actual, true));
}
function ok($cond, string $msg = ''): void
{
    if (!$cond) throw new Exception("Bedingung nicht erfüllt: $msg");
}

$survey = json_decode(file_get_contents(__DIR__ . '/../public/surveys/dahlbruch-2026.json'), true);

// ---- Unit ----

check('mitgelieferte Umfragen sind gültig', function () {
    foreach (glob(__DIR__ . '/../public/surveys/*.json') as $f) eq(validate_survey(json_decode(file_get_contents($f), true)), [], basename($f));
});

check('validate_survey findet Fehler', function () {
    $errors = implode("\n", validate_survey(['slug' => 'X!', 'title' => 'X', 'reportFilters' => ['a'], 'sections' => [['id' => 's', 'title' => 'S', 'questions' => [
        ['id' => 'a', 'type' => 'text', 'text' => 'A', 'showIf' => ['q' => 'b', 'in' => ['Ja']]],
        ['id' => 'b', 'type' => 'single', 'text' => 'B', 'options' => ['Ja']],
        ['id' => 'b', 'type' => 'scale', 'scale' => 'foo', 'text' => 'B2'],
        ['id' => 'c', 'type' => 'text', 'text' => 'C', 'showIf' => ['q' => 'b', 'in' => ['Vielleicht']]],
    ]]]]));
    foreach (['slug', 'mail:', 'a: showIf', 'doppelte id', 'unbekannte Skala', 'c: showIf.in', 'reportFilters'] as $needle) ok(str_contains($errors, $needle), $needle);
});

check('jede Kategorie bekommt ein Freitextfeld (außer freeText: false)', function () use ($survey) {
    $ids = array_column(questions($survey), 'id');
    ok(in_array('atemschutz_frei', $ids, true) && !in_array('person_frei', $ids, true));
});

check('sanitize: Abhängigkeiten, ungültige Werte, Textlänge', function () use ($survey) {
    $r = sanitize($survey, [
        'answers' => [
            'uebungen' => 'Oft', 'uebungen_warum' => ['Gesundheit'], // hidden -> dropped
            'einsaetze' => 'Weniger als 5', 'einsaetze_warum' => ['Kinder / Familie'], // visible -> kept
            'gesamt_bewertung' => 7, 'kameradschaft' => 'na', 'empfehlung' => 'Vielleicht',
            'gh_gut' => '  ' . str_repeat('ä', MAX_TEXT + 10) . '  ', 'unbekannt' => 'x', 'lehrgaenge' => [],
            'ud_mehr' => ['Atemschutz', 'Atemschutz'],
        ],
        'notes' => ['kameradschaft' => ' zu wenig Grillen ', 'gh_gut' => 'Kommentar zu Textfrage', 'uebungen_warum' => 'versteckt'],
    ]);
    $keys = array_keys($r['answers']);
    sort($keys);
    eq($keys, ['einsaetze', 'einsaetze_warum', 'gh_gut', 'kameradschaft', 'uebungen']);
    eq(preg_match_all('/./u', $r['answers']['gh_gut']), MAX_TEXT);
    eq($r['notes'], ['kameradschaft' => 'zu wenig Grillen']);
    eq(sanitize($survey, 'Müll'), ['answers' => [], 'notes' => []]);
});

check('Entwürfe sind verschlüsselt und manipulationssicher', function () {
    $box = seal(['answers' => ['a' => 'geheim'], 'notes' => []]);
    ok(!str_contains(base64_decode($box), 'geheim'));
    eq(unseal($box), ['answers' => ['a' => 'geheim'], 'notes' => []]);
    $raw = base64_decode($box);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    try {
        unseal(base64_encode($raw));
        ok(false, 'manipulierter Entwurf akzeptiert');
    } catch (RuntimeException) {
    }
});

check('Token sind stabil und je Person/Umfrage verschieden', function () {
    eq(token_for('u', 'a@x.de'), token_for('u', 'a@x.de'));
    ok(token_for('u', 'a@x.de') !== token_for('u', 'b@x.de') && token_for('u', 'a@x.de') !== token_for('v', 'a@x.de'));
    ok(preg_match('/^[A-Za-z0-9_-]{43}$/', token_for('u', 'a@x.de')) === 1);
});

check('aggregate: Zählung, Mittelwert, Mindestgruppengröße', function () use ($survey) {
    $r = fn($alter, $bewertung, $text = null) => ['answers' => array_filter(['alter' => $alter, 'gesamt_bewertung' => $bewertung, 'allg_gut' => $text], fn($v) => $v !== null), 'notes' => []];
    $rows = [$r('18–29', 5, 'b'), $r('18–29', 4, 'a'), $r('18–29', 'na'), $r('18–29', 1), $r('18–29', 2),
        $r('30–45', 5), $r('30–45', 5), $r('30–45', 5), $r('30–45', 5), $r('30–45', 5), $r('46 und älter', 3)];
    $all = aggregate($survey, $rows);
    eq($all['n'], 11);
    $q = current(array_filter($all['questions'], fn($x) => $x['q']['id'] === 'gesamt_bewertung'));
    eq($q['answered'], 11);
    eq($q['mean'], (5 + 4 + 1 + 2 + 25 + 3) / 10);
    eq(array_column($q['counts'], 'count'), [1, 1, 1, 1, 6, 1]);
    eq(current(array_filter($all['questions'], fn($x) => $x['q']['id'] === 'allg_gut'))['texts'], ['a', 'b'], 'sortiert');
    eq(aggregate($survey, $rows, ['q' => 'alter', 'v' => '18–29'])['n'], 5);
    $filtered = aggregate($survey, [...$rows, ['answers' => ['alter' => '30–45', 'kameradschaft' => 1], 'notes' => ['kameradschaft' => 'n']]], ['q' => 'alter', 'v' => '30–45']);
    ok($filtered['filtered'] && !array_filter($filtered['questions'], fn($x) => $x['q']['type'] === 'text' || $x['notes']), 'keine Texte/Kommentare im Filter');
    ok(!$all['filtered'], 'ungefiltert');
    ok(aggregate($survey, $rows, ['q' => 'alter', 'v' => '46 und älter'])['suppressed'] ?? false, 'Gruppe < 5');
    ok(aggregate($survey, array_slice($rows, 0, 9), ['q' => 'alter', 'v' => '18–29'])['suppressed'] ?? false, 'Rest < 5');
    ok(aggregate($survey, array_slice($rows, 0, 4))['suppressed'] ?? false, 'gesamt < 5');
    eq(aggregate($survey, $rows, ['q' => 'geschlecht', 'v' => 'Weiblich'])['n'], 11, 'kein erlaubter Filter');
});

// ---- End-to-end over HTTP ----

try {
    db();
} catch (PDOException $e) {
    echo "– Ende-zu-Ende-Test übersprungen: keine Datenbank ({$e->getMessage()})\n";
    if (getenv('CI')) $failures++;
    exit($failures ? 1 : 0);
}

check('Ablauf: importieren, einladen, zwischenspeichern, absenden, erneut einladen, erinnern, beenden, auswerten', function () {
    ok(str_contains(config()['db_dsn'], 'test'), 'Datenbankname muss "test" enthalten');
    foreach (['responses', 'invitations', 'surveys'] as $t) db()->exec("DROP TABLE IF EXISTS $t");
    $mailLog = config()['mail_log'];
    @unlink($mailLog);
    $mails = fn() => is_file($mailLog) ? array_map(fn($l) => json_decode($l, true), file($mailLog, FILE_IGNORE_NEW_LINES)) : [];
    $last = fn() => array_slice($mails(), -1)[0];

    $docroot = sys_get_temp_dir() . '/fffeedback-docroot';
    @mkdir($docroot);
    if (!file_exists("$docroot/umfrage")) symlink(realpath(__DIR__ . '/../public'), "$docroot/umfrage");
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'disable_functions=set_time_limit', '-S', '127.0.0.1:8124', '-t', $docroot], [1 => ['file', sys_get_temp_dir() . '/fffeedback-test-server.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/fffeedback-test-server.log', 'a']], $pipes);
    try {
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 8124); $i++) usleep(100_000);

        $cookie = '';
        $http = function (string $method, string $path, $body = null) use (&$cookie): array {
            $headers = $cookie ? ["Cookie: $cookie"] : [];
            if (is_array($body)) {
                $body = http_build_query($body);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } elseif ($body !== null) {
                $headers[] = 'Content-Type: application/json';
            }
            $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'ignore_errors' => true, 'follow_location' => 0]]);
            $res = file_get_contents('http://127.0.0.1:8124/umfrage/' . $path, false, $ctx);
            if (preg_match('/(Warning|Notice|Deprecated|Fatal error)(<\/b>)?: /', (string) $res, $m)) throw new RuntimeException("PHP-$m[1] in $path: " . strip_tags($res));
            foreach ($http_response_header as $h) if (preg_match('/^Set-Cookie: (fffadmin=[^;]+)/i', $h, $m)) $cookie = $m[1];
            return [(int) explode(' ', $http_response_header[0])[1], $res, implode("\n", $http_response_header)];
        };
        $csrf = '';
        $admin = function (string $path = '') use ($http, &$csrf): string {
            [, $html] = $http('GET', "admin.php$path");
            if (preg_match('/name="csrf" value="([^"]+)"/', $html, $m)) $csrf = $m[1];
            return $html;
        };
        $post = function (array $data) use ($http, &$csrf): string {
            [$status, , $headers] = $http('POST', 'admin.php', $data + ['csrf' => $csrf]);
            eq($status, 303, "POST {$data['action']}");
            preg_match('/^Location: (.*)$/mi', $headers, $m);
            return $m[1];
        };
        $api = fn(string $token, string $action, $body = null) => $http($body === null ? 'GET' : 'POST', '?t=' . urlencode($token) . "&a=$action", $body === null ? null : json_encode($body));

        // Login
        ok(str_contains($admin(), 'type="password"'), 'Login-Formular');
        eq($http('POST', 'admin.php', ['password' => 'falsch'])[0], 200);
        ok(!str_contains($admin(), 'Umfragen</h1>'), 'falsches Passwort');
        $http('POST', 'admin.php', ['password' => 'geheim-lokal']);
        ok(str_contains($admin(), 'dahlbruch-2026.json'), 'eingeloggt, Datei zum Import angeboten');
        eq($http('POST', 'admin.php', ['action' => 'import', 'file' => 'dahlbruch-2026.json', 'csrf' => 'falsch'])[0], 403, 'CSRF');

        // Import & invite
        eq($post(['action' => 'import', 'file' => '../../tests/config.php']), 'admin.php', 'Pfad außerhalb surveys/');
        $slug = 'dahlbruch-2026';
        eq($post(['action' => 'import', 'file' => 'dahlbruch-2026.json']), "admin.php?s=$slug");
        $emails = array_map(fn($i) => "p$i@example.org", range(0, 6));
        $post(['action' => 'invite', 's' => $slug, 'emails' => implode("\n", $emails) . "\nkaputt, P0@Example.org"]);
        ok(preg_match('/7 Einladungen versendet\..*Ungültig: kaputt/u', $admin("?s=$slug")), 'Einladungen versendet');
        eq(count($mails()), 7);
        $post(['action' => 'invite', 's' => $slug, 'emails' => 'p0@example.org']);
        ok(str_contains($admin("?s=$slug"), '1 bereits eingeladen'), 'keine doppelten Einladungen');
        eq(count($mails()), 7);
        $tokens = [];
        foreach ($mails() as $m) {
            preg_match('/\?t=([\w-]+)/', $m['text'], $t);
            $tokens[$m['to']] = $t[1];
        }
        $tokens = array_values(array_map(fn($e) => $tokens[$e], $emails));
        ok(str_contains($mails()[0]['text'], 'http://127.0.0.1:8124/umfrage/?t='), 'Link in der Mail');

        // Survey page & API
        [$status, $html, $headers] = $http('GET', '?t=' . $tokens[0]);
        ok($status === 200 && str_contains($html, 'app.js') && str_contains($headers, 'no-referrer'), 'Umfrageseite');
        eq($api('ungueltig', 'load')[0], 404);
        $data = json_decode($api($tokens[0], 'load')[1], true);
        eq([$data['survey']['slug'], $data['draft']], [$slug, null]);

        eq($api($tokens[0], 'draft', ['answers' => ['gh_gut' => 'Entwurfstext', 'gesamt_bewertung' => 9]])[0], 204);
        eq(json_decode($api($tokens[0], 'load')[1], true)['draft'], ['answers' => ['gh_gut' => 'Entwurfstext'], 'notes' => []]);
        $api($tokens[1], 'draft', ['answers' => []]);
        ok(str_contains($api($tokens[1], 'load')[1], '"draft":{"answers":{},"notes":{}}'), 'leere Objekte bleiben {}');
        $raw = q('SELECT draft, started FROM invitations WHERE email = ?', ['p0@example.org'])->fetch();
        ok($raw['started'] && !str_contains(base64_decode($raw['draft']), 'Entwurfstext'), 'Entwurf verschlüsselt');
        q('UPDATE invitations SET draft = ? WHERE email = ?', [base64_encode(random_bytes(40)), 'p6@example.org']);
        [$status, $body] = $api($tokens[6], 'load');
        eq([$status, json_decode($body, true)['draft']], [200, null], 'unlesbarer Entwurf wird verworfen');
        q('UPDATE invitations SET draft = NULL WHERE email = ?', ['p6@example.org']);

        for ($i = 0; $i < 6; $i++) {
            eq($api($tokens[$i], 'submit', ['answers' => ['alter' => $i < 3 ? '18–29' : '30–45', 'gesamt_bewertung' => 4, 'allg_gut' => "Text $i"]])[0], 204);
        }
        eq($api($tokens[0], 'submit', ['answers' => []])[0], 409, 'nur einmal absenden');
        eq($api($tokens[0], 'draft', ['answers' => []])[0], 409, 'nach Absenden keine Änderung');
        eq(json_decode($api($tokens[0], 'load')[1], true)['submitted'], true);

        $responses = q('SELECT * FROM responses')->fetchAll();
        eq(count($responses), 6);
        eq(array_keys($responses[0]), ['id', 'survey_id', 'data'], 'keine Verknüpfung zur Einladung');
        eq((int) q('SELECT COUNT(draft) FROM invitations')->fetchColumn(), 0, 'Entwürfe gelöscht');

        // While running: no report
        $html = $admin("?s=$slug");
        ok(str_contains($html, 'erst nach dem Beenden') && !str_contains($html, 'Text 5'), 'Auswertung gesperrt');
        ok(str_contains($html, 'abgesendet') && str_contains($html, 'Erneut einladen'), 'Einladungsliste');

        // Re-invite
        $p6 = (int) q('SELECT id FROM invitations WHERE email = ?', ['p6@example.org'])->fetchColumn();
        $p0 = (int) q('SELECT id FROM invitations WHERE email = ?', ['p0@example.org'])->fetchColumn();
        $post(['action' => 'reinvite', 's' => $slug, 'id' => $p6]);
        ok(str_contains($admin("?s=$slug"), 'Einladung erneut gesendet'));
        eq($last()['to'], 'p6@example.org');
        ok(str_contains($last()['text'], $tokens[6]), 'gleicher Link');
        $post(['action' => 'reinvite', 's' => $slug, 'id' => $p0]);
        ok(str_contains($admin("?s=$slug"), 'konnte nicht gesendet werden'), 'kein erneutes Einladen nach Absenden');
        eq(count($mails()), 8);

        // Remind only the one who has not submitted; needs confirmation, a double submit sends nothing
        preg_match('/name="nonce" value="([^"]+)"/', $admin("?s=$slug"), $m);
        $post(['action' => 'remind', 's' => $slug, 'nonce' => $m[1]]);
        ok(str_contains($admin("?s=$slug"), 'Bitte das Erinnern bestätigen'));
        preg_match('/name="nonce" value="([^"]+)"/', $admin("?s=$slug"), $m);
        $post(['action' => 'remind', 's' => $slug, 'nonce' => $m[1], 'confirm' => '1']);
        $post(['action' => 'remind', 's' => $slug, 'nonce' => $m[1], 'confirm' => '1']);
        ok(str_contains($admin("?s=$slug"), 'Keine Erinnerungen versendet'), 'doppelt abgeschickt');
        eq(count($mails()), 9, 'nur eine Erinnerung');
        ok(str_starts_with($last()['subject'], 'Erinnerung'), 'Erinnerungstext');

        // Close
        $post(['action' => 'close', 's' => $slug]);
        ok(str_contains($admin("?s=$slug"), 'Bitte das Beenden bestätigen'));
        $post(['action' => 'close', 's' => $slug, 'confirm' => '1']);
        $html = $admin("?s=$slug");
        ok(str_contains($html, '<b>6 Antworten</b>') && str_contains($html, 'Text 5') && str_contains($html, 'beendet'), 'Auswertung nach Ende');
        ok(str_contains($html, 'Ø 4,0 (1 = sehr gut … 5 = sehr schlecht)'), 'Mittelwert mit Skalenrichtung');
        [, $csv, $headers] = $http('GET', "admin.php?s=$slug&csv=1");
        ok(str_contains($headers, 'text/csv') && str_contains($csv, 'Alle;Gesamteindruck;gesamt_bewertung;') && !str_contains($csv, 'Text 5'), 'CSV-Export ohne Freitexte');
        ok(!str_contains($html, 'example.org'), 'keine E-Mail-Adressen mehr');
        ok(str_contains($admin('?' . http_build_query(['s' => $slug, 'q' => 'alter', 'v' => '18–29'])), 'Zu wenige Antworten'), 'Mindestgruppengröße');
        eq((int) q("SELECT COUNT(*) FROM invitations WHERE email LIKE '%@%'")->fetchColumn(), 0);
        eq(json_decode($api($tokens[6], 'load')[1], true)['closed'], true);
        eq($api($tokens[6], 'submit', ['answers' => []])[0], 409);
        $post(['action' => 'invite', 's' => $slug, 'emails' => 'neu@example.org']);
        eq(count($mails()), 9, 'keine Einladungen nach Ende');
    } finally {
        proc_terminate($server);
        @unlink($mailLog);
    }
});

exit($failures ? 1 : 0);
