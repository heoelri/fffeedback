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

function send_mail(string $to, string $subject, string $text): bool
{
    $c = config();
    if (!empty($c['mail_log'])) { // for tests and local development
        return file_put_contents($c['mail_log'], json_encode(compact('to', 'subject', 'text')) . "\n", FILE_APPEND) !== false;
    }
    $headers = ['From' => $c['mail_from'], 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8', 'Content-Transfer-Encoding' => '8bit'];
    $envelope = preg_match('/<([^>]+)>/', $c['mail_from'], $m) ? $m[1] : $c['mail_from'];
    $params = filter_var($envelope, FILTER_VALIDATE_EMAIL) ? "-f$envelope" : '';
    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers, $params);
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
