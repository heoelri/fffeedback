<?php
// Test configuration. The database name must contain "test": tests drop all tables.
return [
    'db_dsn' => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=fff_test;charset=utf8mb4',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_pass' => getenv('DB_PASS') ?: 'root',
    'app_secret' => str_repeat('x', 32),
    'admin_password' => 'geheim-lokal',
    'base_url' => getenv('FFF_BASE_URL') ?: 'http://127.0.0.1:8123',
    'mail_from' => 'Test <test@example.org>',
    // The end-to-end test also runs a server without mail_log to exercise the real mail path.
    'mail_log' => getenv('FFF_NO_MAIL_LOG') ? '' : sys_get_temp_dir() . '/fffeedback-test-mail.log',
];
