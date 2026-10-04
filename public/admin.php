<?php
// Admin: import surveys, invite / re-invite / remind, close, participation stats and report.
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Instead of a blank 500 page: log the error and show logged-in admins what went wrong.
// Registered before any startup work (config, session) so that those failures are caught too.
set_exception_handler(function (Throwable $e): never {
    // No stack trace: with zend.exception_ignore_args=Off it would contain arguments such as the SMTP password.
    error_log(sprintf('%s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    http_response_code(500);
    page('Fehler', '<p class="warn">' . (empty($_SESSION['admin'])
        ? 'Ein Fehler ist aufgetreten.'
        : 'Fehler: ' . esc($e->getMessage()) . ' (' . esc(basename($e->getFile())) . ':' . $e->getLine() . ')')
        . '</p><p><a href="admin.php">Zurück</a></p>');
});

security_headers();
session_name('fffadmin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => str_starts_with(config()['base_url'], 'https://')]);
session_start();

function page(string $title, string $body): never
{
    $logout = empty($_SESSION['admin']) ? '' : '<form method="post" class="logout">' . csrf() . '<button name="action" value="logout" class="secondary">Abmelden</button></form>';
    $flash = isset($_SESSION['flash']) ? '<p class="card" role="status">' . esc($_SESSION['flash']) . '</p>' : '';
    unset($_SESSION['flash']);
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . esc($title) . '</title><link rel="stylesheet" href="style.css"></head>'
        . "<body><main class=\"report\">$logout$flash$body</main></body></html>";
    exit;
}

function redirect(string $query = '', ?string $flash = null): never
{
    if ($flash !== null) $_SESSION['flash'] = $flash;
    header("Location: admin.php$query", true, 303);
    exit;
}

function csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . esc($_SESSION['csrf'] ?? '') . '">';
}

function action_form(string $slug, string $action, string $button, string $inner = '', string $class = ''): string
{
    return '<form method="post">' . csrf() . '<input type="hidden" name="s" value="' . esc($slug) . '">' . $inner
        . '<button name="action" value="' . $action . '"' . ($class ? " class=\"$class\"" : '') . '>' . esc($button) . '</button></form>';
}

function pct(int $a, int $b): int
{
    return $b ? (int) round($a / $b * 100) : 0;
}

function survey_files(): array
{
    return glob(__DIR__ . '/surveys/*.json') ?: [];
}

function clear_survey_data(int $surveyId): void
{
    db()->beginTransaction();
    try {
        q('DELETE FROM invitations WHERE survey_id = ?', [$surveyId]);
        q('DELETE FROM responses WHERE survey_id = ?', [$surveyId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

const STATS = 'SELECT s.id, s.slug, s.definition, s.closed, COUNT(i.id) AS invited,
    COALESCE(SUM(i.started), 0) AS started, COALESCE(SUM(i.submitted), 0) AS submitted, COALESCE(SUM(i.reminders), 0) AS reminders
    FROM surveys s LEFT JOIN invitations i ON i.survey_id = s.id';

// ---- Login ----

$password = (string) (config()['admin_password'] ?? '');
if (strlen($password) < 12) page('Fehler', '<p class="warn">Bitte in config.php ein admin_password mit mindestens 12 Zeichen setzen.</p>');

if (empty($_SESSION['admin'])) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Serialises all login attempts, so the delay below also limits parallel guessing (released on exit).
        $lock = @fopen(sys_get_temp_dir() . '/fffeedback-login-' . md5(__DIR__) . '.lock', 'c');
        if ($lock) flock($lock, LOCK_EX);
        if (hash_equals(hash('sha256', $password), hash('sha256', (string) ($_POST['password'] ?? '')))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            redirect();
        }
        sleep(1);
        $error = '<p class="warn">Falsches Passwort.</p>';
    }
    page('Anmeldung', "<h1>Umfragen verwalten</h1>$error<form method=\"post\"><p><label>Passwort<br>"
        . '<input type="password" name="password" autocomplete="current-password" required autofocus></label></p><button>Anmelden</button></form>');
}

migrate();

// ---- Actions ----

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        page('Fehler', '<p class="warn">Sitzung abgelaufen. Bitte <a href="admin.php">neu laden</a>.</p>');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'logout') {
        session_destroy();
        redirect();
    }

    if ($action === 'import') {
        $path = __DIR__ . '/surveys/' . basename((string) ($_POST['file'] ?? ''));
        if (!in_array($path, survey_files(), true)) redirect('', 'Datei nicht gefunden.');
        $def = json_decode((string) file_get_contents($path), true);
        $errors = is_array($def) ? validate_survey($def) : ['Kein gültiges JSON'];
        if ($errors) redirect('', 'Ungültige Umfrage: ' . implode('; ', $errors));
        $existing = q('SELECT closed FROM surveys WHERE slug = ?', [$def['slug']])->fetch();
        if ($existing && $existing['closed']) redirect('', 'Die Umfrage ist beendet und kann nicht mehr geändert werden.');
        if ($existing) q('UPDATE surveys SET definition = ? WHERE slug = ?', [json_encode($def, JSON_UNESCAPED_UNICODE), $def['slug']]);
        else q('INSERT INTO surveys (slug, definition) VALUES (?, ?)', [$def['slug'], json_encode($def, JSON_UNESCAPED_UNICODE)]);
        redirect('?s=' . urlencode($def['slug']), $existing ? 'Umfrage aktualisiert.' : 'Umfrage importiert.');
    }

    $survey = q('SELECT id, slug, definition, closed FROM surveys WHERE slug = ?', [(string) ($_POST['s'] ?? '')])->fetch();
    if (!$survey) redirect('', 'Umfrage nicht gefunden.');
    $back = '?s=' . urlencode($survey['slug']);
    if ($action === 'clear') {
        if (empty($_POST['confirm'])) redirect($back, 'Bitte das Löschen bestätigen.');
        clear_survey_data((int) $survey['id']);
        redirect($back, 'Alle Antworten und Teilnehmer wurden gelöscht.');
    }
    if ($survey['closed']) redirect($back, 'Die Umfrage ist beendet.');
    // Many hosters disable set_time_limit; since PHP 8 calling it then is a fatal error, even with @.
    if (function_exists('set_time_limit')) set_time_limit(300);

    switch ($action) {
        case 'invite':
            $valid = $invalid = [];
            foreach (preg_split('/[\s,;]+/', (string) ($_POST['emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $e) {
                $e = strtolower($e);
                if (filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 191) $valid[$e] = true;
                else $invalid[] = $e;
            }
            [$sent, $failed] = invite($survey, array_keys($valid));
            $skipped = count($valid) - $sent - $failed;
            redirect($back, "$sent Einladungen versendet."
                . ($skipped ? " $skipped bereits eingeladen (übersprungen)." : '')
                . ($failed ? " $failed konnten nicht gesendet werden – bitte „Erneut einladen“ nutzen." : '')
                . ($invalid ? ' Ungültig: ' . implode(', ', $invalid) : ''));
        case 'reinvite':
            redirect($back, reinvite($survey, (int) ($_POST['id'] ?? 0)) ? 'Einladung erneut gesendet.' : 'Einladung konnte nicht gesendet werden.');
        case 'remind':
            // One-time nonce: a double click (or an old tab) must not send all reminders twice.
            $nonce = $_SESSION['remind'] ?? '';
            unset($_SESSION['remind']);
            if (empty($_POST['confirm'])) redirect($back, 'Bitte das Erinnern bestätigen.');
            if ($nonce === '' || !hash_equals($nonce, (string) ($_POST['nonce'] ?? ''))) redirect($back, 'Keine Erinnerungen versendet (doppelt abgeschickt oder Seite veraltet).');
            redirect($back, remind($survey) . ' Erinnerungen versendet.');
        case 'close':
            if (empty($_POST['confirm'])) redirect($back, 'Bitte das Beenden bestätigen.');
            close_survey($survey);
            redirect($back, 'Umfrage beendet, E-Mail-Adressen gelöscht.');
    }
    redirect($back);
}

// ---- Pages ----

$slug = $_GET['s'] ?? null;

if ($slug === null) {
    $rows = q(STATS . ' GROUP BY s.id ORDER BY s.id DESC')->fetchAll();
    $list = implode('', array_map(fn($s) => '<li><a href="?s=' . urlencode($s['slug']) . '">' . esc(json_decode($s['definition'], true)['title']) . '</a> – '
        . "{$s['submitted']}/{$s['invited']} abgesendet" . ($s['closed'] ? ' (beendet)' : '') . '</li>', $rows));
    $files = implode('', array_map(fn($f) => '<option>' . esc(basename($f)) . '</option>', survey_files()));
    page('Umfragen', '<h1>Umfragen</h1>' . ($list ? "<ul>$list</ul>" : '<p>Noch keine Umfrage importiert.</p>')
        . '<section class="card"><h2>Umfrage importieren oder aktualisieren</h2><p class="muted">Dateien aus dem Ordner <code>surveys/</code>.</p>'
        . '<form method="post">' . csrf() . "<select name=\"file\">$files</select> <button name=\"action\" value=\"import\">Importieren</button></form></section>");
}

$s = q(STATS . ' WHERE s.slug = ? GROUP BY s.id', [$slug])->fetch();
if (!$s) redirect('', 'Umfrage nicht gefunden.');
$def = json_decode($s['definition'], true);
[$invited, $started, $submitted] = [(int) $s['invited'], (int) $s['started'], (int) $s['submitted']];

$html = '<p><a href="admin.php">← Übersicht</a></p><h1>' . esc($def['title']) . '</h1>'
    . '<section class="card"><h2>Beteiligung</h2><table>'
    . "<tr><td>Eingeladen</td><td>$invited</td></tr>"
    . "<tr><td>Begonnen</td><td>$started (" . pct($started, $invited) . ' %)</td></tr>'
    . "<tr><td>Abgesendet</td><td>$submitted (" . pct($submitted, $invited) . " %) <progress max=\"$invited\" value=\"$submitted\"></progress></td></tr>"
    . "<tr><td>Erinnerungen versendet</td><td>{$s['reminders']}</td></tr>"
    . '<tr><td>Status</td><td>' . ($s['closed'] ? 'beendet' : 'läuft') . '</td></tr></table></section>'
    . '<section class="card"><h2>Daten löschen</h2><p class="muted">Entfernt alle Antworten und Teilnehmer dieser Umfrage. Die Umfrage selbst bleibt erhalten.</p>'
    . action_form($slug, 'clear', 'Antworten und Teilnehmer löschen', '<p><label><input type="checkbox" name="confirm" value="1" required> Ja, Daten endgültig löschen</label></p>', 'secondary')
    . '</section>';

if (!$s['closed']) {
    $open = $invited - $submitted;
    $html .= '<section class="card"><h2>Einladen</h2><p class="muted">E-Mail-Adressen, eine pro Zeile (oder durch Komma getrennt). Bereits Eingeladene werden übersprungen.</p>'
        . action_form($slug, 'invite', 'Einladungen senden', '<textarea name="emails" rows="6" required aria-label="E-Mail-Adressen"></textarea>')
        . '<h2>Erinnern</h2><p class="muted">Schreibt allen, die noch nicht abgesendet haben.</p>'
        . action_form($slug, 'remind', "Erinnerung an $open Personen senden", '<input type="hidden" name="nonce" value="' . ($_SESSION['remind'] = bin2hex(random_bytes(8))) . '">'
            . '<p><label><input type="checkbox" name="confirm" value="1" required> Ja, jetzt erinnern</label></p>')
        . '<h2>Beenden</h2><p class="muted">Danach sind keine Antworten mehr möglich, die E-Mail-Adressen werden gelöscht und die Auswertung wird freigeschaltet.</p>'
        . action_form($slug, 'close', 'Umfrage beenden', '<p><label><input type="checkbox" name="confirm" value="1" required> Ja, Umfrage endgültig beenden</label></p>', 'secondary')
        . '</section>';

    $rows = '';
    foreach (q('SELECT id, email, started, submitted, invites, reminders FROM invitations WHERE survey_id = ? ORDER BY email', [$s['id']]) as $i) {
        $status = $i['submitted'] ? 'abgesendet' : ($i['started'] ? 'begonnen' : 'offen');
        $mails = $i['invites'] ? "{$i['invites']} Einladung(en), {$i['reminders']} Erinnerung(en)" : '<strong>nicht zugestellt</strong>';
        $button = $i['submitted'] ? '' : action_form($slug, 'reinvite', 'Erneut einladen', '<input type="hidden" name="id" value="' . $i['id'] . '">', 'secondary');
        $rows .= '<tr><td>' . esc($i['email']) . "</td><td>$status</td><td>$mails</td><td>$button</td></tr>";
    }
    $html .= '<h2>Einladungen</h2>' . ($rows ? "<table class=\"invites\">$rows</table>" : '<p>Noch niemand eingeladen.</p>')
        . '<h2>Auswertung</h2><p class="warn">Die Auswertung ist erst nach dem Beenden der Umfrage verfügbar. '
        . 'So kann niemand durch Vergleich vor und nach einer einzelnen Teilnahme Rückschlüsse ziehen.</p>';
    page($def['title'], $html);
}

// Report (only for closed surveys)
$filter = isset($_GET['q'], $_GET['v']) ? ['q' => (string) $_GET['q'], 'v' => (string) $_GET['v']] : null;
$responses = array_map(fn($r) => json_decode($r['data'], true), q('SELECT data FROM responses WHERE survey_id = ?', [$s['id']])->fetchAll());
$r = aggregate($def, $responses, $filter);
$base = '?s=' . urlencode($slug);
$all = questions($def);

$html .= '<section class="card"><h2>Filter</h2><p><a href="' . $base . '"' . ($filter ? '' : ' aria-current="page"') . '>Alle Antworten</a></p>';
foreach ($def['reportFilters'] ?? [] as $id) {
    $fq = current(array_filter($all, fn($x) => $x['id'] === $id));
    $links = array_map(fn($v) => '<a href="' . $base . '&amp;q=' . urlencode($id) . '&amp;v=' . urlencode($v) . '"'
        . ($filter === ['q' => $id, 'v' => $v] ? ' aria-current="page"' : '') . '>' . esc($v) . '</a>', $fq['options']);
    $html .= '<p><b>' . esc(rtrim($fq['text'], '?:')) . ':</b> ' . implode(' · ', $links) . '</p>';
}
$html .= '</section>';

if (!empty($r['suppressed'])) {
    page($def['title'], $html . '<p class="warn">Zu wenige Antworten für diese Auswahl (Gruppe oder Rest unter ' . MIN_GROUP . '). Zum Schutz der Anonymität wird nichts angezeigt.</p>');
}

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$s['slug']}.csv\"");
    $titles = array_column($def['sections'], 'title', 'id');
    $scope = $r['filtered'] ? "{$filter['q']} = {$filter['v']}" : 'Alle';
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel detects UTF-8
    $csv = fn(array $row) => fputcsv($out, $row, ';', '"', '');
    $csv(['Auswahl', 'Kategorie', 'Frage-ID', 'Frage', 'Antworten', 'Mittelwert', 'Antwort', 'Anzahl']);
    foreach ($r['questions'] as $x) {
        if (!isset($x['counts'])) continue; // free texts are only in the HTML report
        $mean = $x['mean'] === null ? '' : number_format($x['mean'], 2, ',', '');
        foreach ($x['counts'] as $c) $csv([$scope, $titles[$x['q']['section']], $x['q']['id'], $x['q']['text'], $x['answered'], $mean, $c['label'], $c['count']]);
    }
    exit;
}

if (isset($_GET['pdf'])) {
    $pdf = report_pdf($def, $r, $filter);
    header('Content-Type: application/pdf');
    header("Content-Disposition: attachment; filename=\"{$s['slug']}.pdf\"");
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$self = $base . ($r['filtered'] ? '&amp;q=' . urlencode($filter['q']) . '&amp;v=' . urlencode($filter['v']) : '');
$list = fn($items) => $items ? '<ul class="texts">' . implode('', array_map(fn($t) => '<li>' . esc($t) . '</li>', $items)) . '</ul>' : '';
$html .= "<p><b>{$r['n']} Antworten</b> in dieser Auswahl · <a href=\"$self&amp;pdf=1\">Als PDF herunterladen</a> · <a href=\"$self&amp;csv=1\">Als CSV für Excel herunterladen</a></p>";
if ($r['filtered']) $html .= '<p class="muted">Freitexte und Kommentare stehen nur unter „Alle Antworten“. Sonst ließen sie sich über mehrere Filter einer Person zuordnen.</p>';
$section = null;
foreach ($r['questions'] as $x) {
    if ($x['q']['section'] !== $section) {
        $section = $x['q']['section'];
        $html .= '<h2>' . esc(current(array_filter($def['sections'], fn($sec) => $sec['id'] === $section))['title']) . '</h2>';
    }
    $scale = SCALES[$x['q']['scale'] ?? 'rate'];
    $mean = isset($x['mean']) ? ' · Ø ' . number_format($x['mean'], 1, ',', '') . ' (1 = ' . $scale[0] . ' … 5 = ' . $scale[4] . ')' : '';
    $html .= '<h3>' . esc($x['q']['text']) . "</h3><p class=\"muted\">{$x['answered']} Antworten$mean</p>";
    if (isset($x['texts'])) {
        $html .= $list($x['texts']);
        continue;
    }
    $html .= '<table>' . implode('', array_map(fn($c) => '<tr><td>' . esc($c['label']) . "</td><td><progress max=\"{$x['answered']}\" value=\"{$c['count']}\"></progress></td>"
        . "<td>{$c['count']} (" . pct($c['count'], $x['answered']) . ' %)</td></tr>', $x['counts'])) . '</table>';
    if ($x['notes']) $html .= '<details><summary>' . count($x['notes']) . ' Kommentare</summary>' . $list($x['notes']) . '</details>';
}
page($def['title'], $html);
