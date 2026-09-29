<?php
// Survey page (?t=TOKEN) and its JSON API (?t=TOKEN&a=load|draft|submit).
declare(strict_types=1);
require __DIR__ . '/lib.php';
security_headers();

$action = $_GET['a'] ?? null;
if ($action === null) { ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Umfrage</title>
  <link rel="stylesheet" href="style.css">
  <script type="module" src="app.js"></script>
</head>
<body>
  <main id="app"><p>Lädt …</p></main>
</body>
</html>
<?php exit; }

header('Content-Type: application/json; charset=UTF-8');

function reply(int $status, ?array $body = null): never
{
    http_response_code($status);
    if ($body !== null) echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$inv = q('SELECT i.id, i.survey_id, i.draft, i.submitted, s.definition, s.closed
          FROM invitations i JOIN surveys s ON s.id = i.survey_id WHERE i.token_hash = ?',
    [token_hash((string) ($_GET['t'] ?? ''))])->fetch();
if (!$inv) reply(404, ['error' => 'Dieser Link ist ungültig.']);
$survey = json_decode($inv['definition'], true);

if ($action === 'load') {
    echo '{"survey":' . $inv['definition'] . ',"closed":' . json_encode((bool) $inv['closed'])
        . ',"submitted":' . json_encode((bool) $inv['submitted'])
        . ',"draft":' . ($inv['draft'] ? state_json(unseal($inv['draft'])) : 'null') . '}';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['draft', 'submit'], true)) reply(405);
if ($inv['closed']) reply(409, ['error' => 'Die Umfrage ist beendet.']);
if ($inv['submitted']) reply(409, ['error' => 'Du hast bereits abgesendet.']);

$body = file_get_contents('php://input', false, null, 0, 200_001);
if (strlen($body) > 200_000) reply(413, ['error' => 'Zu viel Text.']);
$data = sanitize($survey, json_decode($body, true));

if ($action === 'draft') {
    q('UPDATE invitations SET draft = ?, started = 1 WHERE id = ? AND submitted = 0', [seal($data), $inv['id']]);
    reply(204);
}

db()->beginTransaction();
$done = q('UPDATE invitations SET submitted = 1, started = 1, draft = NULL WHERE id = ? AND submitted = 0', [$inv['id']]);
if (!$done->rowCount()) {
    db()->rollBack();
    reply(409, ['error' => 'Du hast bereits abgesendet.']);
}
q('INSERT INTO responses (id, survey_id, data) VALUES (?, ?, ?)', [bin2hex(random_bytes(16)), $inv['survey_id'], state_json($data)]);
db()->commit();
reply(204);
