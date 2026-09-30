<?php
// Copy to config.php and adjust. Never commit config.php!
return [
    // Database from your web hosting control panel (MySQL >= 5.7 or MariaDB >= 10.3)
    'db_dsn' => 'mysql:host=localhost;dbname=feedback;charset=utf8mb4',
    'db_user' => 'feedback',
    'db_pass' => '',

    // At least 32 random characters. Must NOT change while a survey is running (links and drafts would break).
    // e.g. generate with: php -r "echo bin2hex(random_bytes(24));"
    'app_secret' => '',

    // Password for admin.php (at least 12 characters)
    'admin_password' => '',

    // Address of this folder on the web, used in the invitation links
    'base_url' => 'https://www.example.org/umfrage',

    // Sender for invitations (should be a mailbox of your own domain at the same provider)
    'mail_from' => 'Einheitsführung Dahlbruch <umfrage@example.org>',

    // Optional, recommended: send via authenticated SMTP (STARTTLS required) instead of PHP mail().
    // The mailbox should be the one in mail_from. Example for Strato:
    // 'smtp_host' => 'smtp.strato.de',
    // 'smtp_port' => 587,
    // 'smtp_username' => 'umfrage@example.org', // full e-mail address
    // 'smtp_password' => '',
    // 'smtp_ca_file' => '', // only for private CAs; the system CA store is used otherwise

    // Local testing only: write e-mails to this file instead of sending them (never inside the web folder!)
    // 'mail_log' => sys_get_temp_dir() . '/fffeedback-mails.log',
];
