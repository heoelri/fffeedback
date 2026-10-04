<?php
// Local testing only: imports the sample survey and invites a test user, then prints the survey link.
// Usage: FFF_CONFIG=tests/config.php php tools/seed-local.php [email]
declare(strict_types=1);
require __DIR__ . '/../public/lib.php';

migrate();

$def = json_decode((string) file_get_contents(__DIR__ . '/../public/surveys/dahlbruch-2026.json'), true);
$errors = validate_survey($def);
if ($errors) {
    fwrite(STDERR, "Ungültige Umfrage: " . implode('; ', $errors) . "\n");
    exit(1);
}

if (!q('SELECT id FROM surveys WHERE slug = ?', [$def['slug']])->fetch()) {
    q('INSERT INTO surveys (slug, definition) VALUES (?, ?)', [$def['slug'], json_encode($def, JSON_UNESCAPED_UNICODE)]);
}
$survey = q('SELECT * FROM surveys WHERE slug = ?', [$def['slug']])->fetch();

$email = $argv[1] ?? 'test@example.org';
invite($survey, [$email]);

echo "Testnutzer: $email\n";
echo "Umfrage-Link: " . rtrim(config()['base_url'], '/') . '/index.php?t=' . token_for($def['slug'], $email) . "\n";
echo "Admin: " . rtrim(config()['base_url'], '/') . "/admin.php (Passwort: " . config()['admin_password'] . ")\n";
