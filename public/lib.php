<?php
// Shared code for index.php (survey) and admin.php. Only defines functions, no output.
declare(strict_types=1);

const SCALES = [
    'rate' => ['sehr gut', 'gut', 'mittel', 'schlecht', 'sehr schlecht'],
    'agree' => ['trifft voll zu', 'trifft eher zu', 'teils/teils', 'trifft eher nicht zu', 'trifft nicht zu'],
];
const NA = 'na';
const MAX_TEXT = 2000;
const MIN_GROUP = 5;

function config(): array
{
    static $config;
    return $config ??= require(getenv('FFF_CONFIG') ?: __DIR__ . '/config.php');
}

function db(): PDO
{
    static $pdo;
    $c = config();
    return $pdo ??= new PDO($c['db_dsn'], $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// responses has no timestamps, no auto-increment and no reference to invitations:
// a submitted answer cannot be linked back to a person by id, order or time.
function migrate(): void
{
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    db()->exec("CREATE TABLE IF NOT EXISTS surveys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(64) NOT NULL UNIQUE,
        definition MEDIUMTEXT NOT NULL,
        closed TINYINT(1) NOT NULL DEFAULT 0
    ) $opts");
    db()->exec("CREATE TABLE IF NOT EXISTS invitations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        survey_id INT NOT NULL,
        email VARCHAR(191) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        draft MEDIUMTEXT NULL,
        started TINYINT(1) NOT NULL DEFAULT 0,
        submitted TINYINT(1) NOT NULL DEFAULT 0,
        invites INT NOT NULL DEFAULT 0,
        reminders INT NOT NULL DEFAULT 0,
        UNIQUE KEY survey_email (survey_id, email),
        FOREIGN KEY (survey_id) REFERENCES surveys (id)
    ) $opts");
    db()->exec("CREATE TABLE IF NOT EXISTS responses (
        id CHAR(32) PRIMARY KEY,
        survey_id INT NOT NULL,
        data MEDIUMTEXT NOT NULL,
        FOREIGN KEY (survey_id) REFERENCES surveys (id)
    ) $opts");
}

// ---- Crypto ----

function secret(): string
{
    $s = config()['app_secret'] ?? '';
    if (strlen($s) < 32) throw new RuntimeException('app_secret fehlt oder ist kürzer als 32 Zeichen');
    return $s;
}

// Deterministic, so re-invites and reminders can re-send the link without storing it. Only its hash is in the DB.
function token_for(string $slug, string $email): string
{
    return rtrim(strtr(base64_encode(hash_hmac('sha256', "invite:$slug:$email", secret(), true)), '+/', '-_'), '=');
}

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

// Drafts are still linked to an invitation, so they are encrypted at rest (DB dumps/backups stay unreadable).
function seal(array $data): string
{
    $iv = random_bytes(12);
    $box = openssl_encrypt(state_json($data), 'aes-256-gcm', hash('sha256', 'draft:' . secret(), true), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $box);
}

function unseal(string $sealed): array
{
    $raw = base64_decode($sealed, true) ?: '';
    $json = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', 'draft:' . secret(), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($json === false) throw new RuntimeException('Entwurf beschädigt');
    return json_decode($json, true);
}

// PHP encodes empty arrays as [], the browser needs {}.
function state_json(array $s): string
{
    return json_encode(['answers' => (object) $s['answers'], 'notes' => (object) $s['notes']], JSON_UNESCAPED_UNICODE);
}

// ---- Survey rules (mirrored in logic.js) ----

function questions(array $survey): array
{
    $out = [];
    foreach ($survey['sections'] as $s) {
        foreach ($s['questions'] as $q) $out[] = $q + ['section' => $s['id']];
        if (($s['freeText'] ?? true) !== false) {
            $out[] = ['id' => "{$s['id']}_frei", 'type' => 'text', 'section' => $s['id'],
                'text' => "Möchtest du zum Thema „{$s['title']}“ noch etwas sagen?"];
        }
    }
    return $out;
}

function scale_options(array $q): array
{
    $out = [];
    foreach (SCALES[$q['scale'] ?? 'rate'] as $i => $label) $out[] = [$i + 1, $label];
    $out[] = [NA, 'Kann ich nicht beurteilen'];
    return $out;
}

function matches($value, array $list): bool
{
    foreach (is_array($value) ? $value : [$value] as $v) {
        if ($v !== null && in_array($v, $list, true)) return true;
    }
    return false;
}

function is_visible(array $q, array $answers): bool
{
    return empty($q['showIf']) || matches($answers[$q['showIf']['q']] ?? null, $q['showIf']['in']);
}

function is_valid(array $q, $v): bool
{
    switch ($q['type']) {
        case 'scale': return $v === NA || (is_int($v) && $v >= 1 && $v <= 5);
        case 'single': return is_string($v) && in_array($v, $q['options'], true);
        case 'multi':
            if (!is_array($v) || !$v || !array_is_list($v)) return false;
            foreach ($v as $x) if (!is_string($x) || !in_array($x, $q['options'], true)) return false;
            return count(array_unique($v)) === count($v);
        case 'text': return is_string($v) && clip($v) !== '';
    }
    return false;
}

function clip(string $s): string
{
    $s = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $s) ?? '';
    return preg_match('/^.{0,' . MAX_TEXT . '}/us', $s, $m) ? $m[0] : '';
}

// Keeps only valid answers to visible questions. Single pass works because showIf may only reference earlier questions.
function sanitize(array $survey, $input): array
{
    $in = is_array($input['answers'] ?? null) ? $input['answers'] : [];
    $notesIn = is_array($input['notes'] ?? null) ? $input['notes'] : [];
    $answers = $notes = [];
    foreach (questions($survey) as $q) {
        if (!is_visible($q, $answers)) continue;
        $id = $q['id'];
        if (array_key_exists($id, $in) && is_valid($q, $in[$id])) $answers[$id] = $q['type'] === 'text' ? clip($in[$id]) : $in[$id];
        $n = $notesIn[$id] ?? null;
        if ($q['type'] !== 'text' && is_string($n) && clip($n) !== '') $notes[$id] = clip($n);
    }
    return ['answers' => $answers, 'notes' => $notes];
}

function validate_survey(array $survey): array
{
    $errors = [];
    $seen = [];
    if (!preg_match('/^[a-z0-9-]{1,64}$/', $survey['slug'] ?? '')) $errors[] = 'slug fehlt oder ungültig (a-z, 0-9, -)';
    if (empty($survey['title'])) $errors[] = 'title fehlt';
    foreach (['mail', 'reminder'] as $m) {
        if (empty($survey[$m]['subject']) || !str_contains($survey[$m]['text'] ?? '', '{link}')) $errors[] = "$m: subject oder {link} im text fehlt";
    }
    foreach (questions($survey) as $q) {
        $id = $q['id'] ?? '?';
        if (isset($seen[$id])) $errors[] = "$id: doppelte id";
        if (!in_array($q['type'] ?? '', ['single', 'multi', 'scale', 'text'], true)) $errors[] = "$id: unbekannter Typ";
        if (in_array($q['type'] ?? '', ['single', 'multi'], true) && empty($q['options'])) $errors[] = "$id: options fehlen";
        if (($q['type'] ?? '') === 'scale' && isset($q['scale']) && !isset(SCALES[$q['scale']])) $errors[] = "$id: unbekannte Skala";
        if (!empty($q['showIf'])) {
            $parent = $seen[$q['showIf']['q'] ?? ''] ?? null;
            if (!$parent) $errors[] = "$id: showIf muss auf eine frühere Frage verweisen";
            elseif (empty($q['showIf']['in']) || array_filter($q['showIf']['in'], fn($v) => !is_valid($parent, $parent['type'] === 'multi' ? [$v] : $v))) {
                $errors[] = "$id: showIf.in enthält ungültige Werte";
            }
        }
        $seen[$id] = $q;
    }
    foreach ($survey['reportFilters'] ?? [] as $f) {
        if (($seen[$f]['type'] ?? '') !== 'single') $errors[] = "reportFilters: $f muss eine single-Frage sein";
    }
    return $errors;
}

// ---- Report ----

// One filter at a time, and both the group and "everyone else" must have >= MIN_GROUP answers,
// otherwise small groups could be recovered by comparing views (differencing).
// Filtered views contain no texts/notes: a text seen under two filters would pin its author to the intersection.
function aggregate(array $survey, array $responses, ?array $filter = null): array
{
    $active = $filter && in_array($filter['q'] ?? null, $survey['reportFilters'] ?? [], true);
    $rows = $active ? array_values(array_filter($responses, fn($r) => ($r['answers'][$filter['q']] ?? null) === $filter['v'])) : $responses;
    $rest = count($responses) - count($rows);
    if (count($rows) < MIN_GROUP || ($rest > 0 && $rest < MIN_GROUP)) return ['suppressed' => true];

    $result = [];
    foreach (questions($survey) as $q) {
        if ($active && $q['type'] === 'text') continue;
        $values = [];
        $notes = [];
        foreach ($rows as $r) {
            if (array_key_exists($q['id'], $r['answers'])) $values[] = $r['answers'][$q['id']];
            if (!$active && !empty($r['notes'][$q['id']])) $notes[] = $r['notes'][$q['id']];
        }
        // Sorted (not in DB order) so texts of one person cannot be lined up across questions.
        sort($notes);
        if ($q['type'] === 'text') {
            sort($values);
            $result[] = ['q' => $q, 'answered' => count($values), 'texts' => $values];
            continue;
        }
        $options = $q['type'] === 'scale' ? scale_options($q) : array_map(fn($o) => [$o, $o], $q['options']);
        $nums = array_filter($values, 'is_int');
        $result[] = [
            'q' => $q,
            'answered' => count($values),
            'counts' => array_map(fn($o) => ['label' => $o[1], 'count' => count(array_filter($values, fn($v) => matches($v, [$o[0]])))], $options),
            'mean' => $nums ? array_sum($nums) / count($nums) : null,
            'notes' => $notes,
        ];
    }
    return ['n' => count($rows), 'filtered' => $active, 'questions' => $result];
}

// ---- Mail & invitations ----

// Sends via authenticated SMTP when smtp_host is configured (same mechanism as heoelri/ebmanager), otherwise via mail().
function send_mail(string $to, string $subject, string $text): bool
{
    $c = config();
    if (!empty($c['mail_log'])) { // for tests and local development
        return file_put_contents($c['mail_log'], json_encode(compact('to', 'subject', 'text')) . "\n", FILE_APPEND) !== false;
    }
    $envelope = preg_match('/<([^>]+)>/', $c['mail_from'], $m) ? $m[1] : $c['mail_from'];
    // Encode a non-ASCII display name (e.g. "Einheitsführung") as RFC 2047.
    $from = preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $c['mail_from'], $m) && preg_match('/[^\x20-\x7e]/', $m[1])
        ? mime_header($m[1]) . " <$m[2]>" : $c['mail_from'];
    $subject = mime_header($subject);
    if (trim((string) ($c['smtp_host'] ?? '')) !== '') {
        return smtp_send(smtp_settings($c, $envelope), $to, $subject, $text, $from);
    }
    $headers = ['From' => $from, 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8', 'Content-Transfer-Encoding' => '8bit'];
    if (!function_exists('mail')) throw new RuntimeException('Die PHP-Funktion mail() ist beim Hoster deaktiviert. Bitte in config.php SMTP einrichten (smtp_host usw.) oder im Kundenmenü des Hosters den Mailversand für PHP aktivieren.');
    $params = filter_var($envelope, FILTER_VALIDATE_EMAIL) ? "-f$envelope" : '';
    return mail($to, $subject, $text, $headers, $params);
}

// RFC 2047 encoded-words may be at most 75 characters: split into UTF-8-safe chunks and fold the header.
function mime_header(string $text): string
{
    $words = [];
    $chunk = '';
    preg_match_all('/./us', $text, $chars);
    foreach ($chars[0] as $char) {
        if (strlen($chunk . $char) > 45) { // 45 bytes → 60 Base64 characters + 12 for "=?UTF-8?B??="
            $words[] = $chunk;
            $chunk = '';
        }
        $chunk .= $char;
    }
    $words[] = $chunk;
    return implode("\r\n ", array_map(fn($w) => '=?UTF-8?B?' . base64_encode($w) . '?=', $words));
}

function smtp_settings(#[\SensitiveParameter] array $c, string $envelope): array
{
    $s = [
        'host' => trim((string) $c['smtp_host']),
        'port' => (int) ($c['smtp_port'] ?? 587),
        'username' => (string) ($c['smtp_username'] ?? ''),
        'password' => (string) ($c['smtp_password'] ?? ''),
        'ca_file' => (string) ($c['smtp_ca_file'] ?? ''),
        'envelope' => $envelope,
    ];
    if (!preg_match('/^[A-Za-z0-9.-]+$/', $s['host']) || $s['port'] < 1 || $s['port'] > 65535
        || $s['username'] === '' || $s['password'] === '' || ($s['ca_file'] !== '' && !is_file($s['ca_file']))
        || !filter_var($envelope, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP ist nicht vollständig konfiguriert (smtp_host, smtp_port, smtp_username, smtp_password und mail_from in config.php prüfen).');
    }
    return $s;
}

function smtp_write($socket, #[\SensitiveParameter] string $data): bool
{
    while ($data !== '') {
        $written = fwrite($socket, $data);
        if ($written === false || $written === 0) return false;
        $data = substr($data, $written);
    }
    return true;
}

function smtp_reply($socket, int|array $expected): bool
{
    for ($lines = 0; $lines < 100; $lines++) {
        $line = fgets($socket, 4096);
        if ($line === false || !preg_match('/^(\d{3})([ -])/', $line, $m)) return false;
        if ($m[2] === ' ') return in_array((int) $m[1], (array) $expected, true);
    }
    return false;
}

function smtp_command($socket, #[\SensitiveParameter] string $command, int|array $expected): bool
{
    return smtp_write($socket, "$command\r\n") && smtp_reply($socket, $expected);
}

// Opens an authenticated session. Credentials are sent only after certificate-verified STARTTLS.
function smtp_connect(#[\SensitiveParameter] array $s)
{
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $s['host']];
    if ($s['ca_file'] !== '') $ssl['cafile'] = $s['ca_file'];
    $socket = @stream_socket_client("tcp://{$s['host']}:{$s['port']}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
    if ($socket === false) throw new RuntimeException("SMTP-Server {$s['host']}:{$s['port']} nicht erreichbar ($errstr).");
    stream_set_timeout($socket, 10);
    $step = 'Verbindung';
    $ok = smtp_reply($socket, 220)
        && smtp_command($socket, 'EHLO localhost', 250)
        && ($step = 'STARTTLS') && smtp_command($socket, 'STARTTLS', 220)
        && @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) === true
        && smtp_command($socket, 'EHLO localhost', 250)
        && ($step = 'Anmeldung') && smtp_command($socket, 'AUTH LOGIN', 334)
        && smtp_command($socket, base64_encode($s['username']), 334)
        && smtp_command($socket, base64_encode($s['password']), 235);
    if (!$ok) {
        fclose($socket);
        throw new RuntimeException("SMTP-Fehler bei {$s['host']} ($step). Bitte smtp_host, smtp_port, smtp_username und smtp_password prüfen.");
    }
    return $socket;
}

// One authenticated session is reused for all mails of a request (invite/remind send many).
// Returns false if the server rejects a single recipient; throws on transport or login failures so that a batch stops
// instead of waiting for a timeout per recipient.
function smtp_send(#[\SensitiveParameter] array $s, string $to, string $subject, string $text, string $from): bool
{
    static $sessions = [];
    $key = json_encode($s);
    $reused = isset($sessions[$key]);
    if (!$reused) {
        $sessions[$key] = smtp_connect($s);
        register_shutdown_function(function () use (&$sessions, $key) {
            if (!isset($sessions[$key])) return;
            $socket = $sessions[$key];
            unset($sessions[$key]); // a reconnect registers another handler for the same key
            @smtp_command($socket, 'QUIT', 221);
            @fclose($socket);
        });
    }
    $socket = $sessions[$key];
    if (!smtp_command($socket, "MAIL FROM:<{$s['envelope']}>", 250)) {
        @fclose($socket);
        unset($sessions[$key]);
        if ($reused) return smtp_send($s, $to, $subject, $text, $from); // the server may have closed an idle session
        throw new RuntimeException("SMTP-Server {$s['host']} lehnt den Absender {$s['envelope']} ab.");
    }
    if (!smtp_command($socket, "RCPT TO:<$to>", [250, 251, 252])) { // 251/252: forwarded or not verifiable, but accepted
        if (smtp_command($socket, 'RSET', 250)) return false; // only this recipient was rejected
        @fclose($socket);
        unset($sessions[$key]);
        throw new RuntimeException("Verbindung zum SMTP-Server {$s['host']} abgebrochen.");
    }
    // Quoted-printable keeps the message 7-bit, so the server needs no 8BITMIME. SMTP ends DATA on a lone dot, so
    // leading dots are escaped.
    $body = preg_replace('/^\./m', '..', quoted_printable_encode(str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $text)));
    $headers = 'Date: ' . gmdate(DATE_RFC2822) . "\r\nFrom: $from\r\nTo: $to\r\nSubject: $subject\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n";
    if (!smtp_command($socket, 'DATA', 354) || !smtp_write($socket, "$headers\r\n$body\r\n.\r\n") || !smtp_reply($socket, 250)) {
        @fclose($socket);
        unset($sessions[$key]);
        throw new RuntimeException("SMTP-Server {$s['host']} hat die Nachricht an $to nicht angenommen.");
    }
    return true;
}

function send_link(array $survey, string $template, string $email): bool
{
    $def = json_decode($survey['definition'], true);
    $link = rtrim(config()['base_url'], '/') . '/?t=' . token_for($survey['slug'], $email);
    $fill = fn($s) => strtr($s, ['{title}' => $def['title'], '{link}' => $link]);
    return send_mail($email, $fill($def[$template]['subject']), $fill($def[$template]['text']));
}

// Returns [sent, failed]. Already invited addresses are skipped (use reinvite for those).
function invite(array $survey, array $emails): array
{
    $sent = $failed = 0;
    foreach ($emails as $email) {
        $ins = q('INSERT IGNORE INTO invitations (survey_id, email, token_hash) VALUES (?, ?, ?)',
            [$survey['id'], $email, token_hash(token_for($survey['slug'], $email))]);
        if (!$ins->rowCount()) continue;
        if (send_link($survey, 'mail', $email)) {
            q('UPDATE invitations SET invites = invites + 1 WHERE survey_id = ? AND email = ?', [$survey['id'], $email]);
            $sent++;
        } else {
            $failed++;
        }
    }
    return [$sent, $failed];
}

function reinvite(array $survey, int $invitationId): bool
{
    $inv = q('SELECT email FROM invitations WHERE id = ? AND survey_id = ? AND submitted = 0', [$invitationId, $survey['id']])->fetch();
    if (!$inv || !send_link($survey, 'mail', $inv['email'])) return false;
    q('UPDATE invitations SET invites = invites + 1 WHERE id = ?', [$invitationId]);
    return true;
}

function remind(array $survey): int
{
    $sent = 0;
    foreach (q('SELECT id, email FROM invitations WHERE survey_id = ? AND submitted = 0', [$survey['id']]) as $inv) {
        if (!send_link($survey, 'reminder', $inv['email'])) continue;
        q('UPDATE invitations SET reminders = reminders + 1 WHERE id = ?', [$inv['id']]);
        $sent++;
    }
    return $sent;
}

// Ends the survey and deletes e-mail addresses and drafts (data minimisation). Counts for the statistics remain.
function close_survey(array $survey): void
{
    q('UPDATE surveys SET closed = 1 WHERE id = ?', [$survey['id']]);
    q("UPDATE invitations SET email = CONCAT('geloescht-', id), draft = NULL WHERE survey_id = ?", [$survey['id']]);
}

// ---- HTTP ----

function security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer'); // survey links contain the personal token
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function esc($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
