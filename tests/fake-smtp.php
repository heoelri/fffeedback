<?php
// Minimal SMTP server for tests (same flow as heoelri/ebmanager): requires STARTTLS, then AUTH LOGIN.
// Usage: php tests/fake-smtp.php CERT KEY PORT LOGFILE [CONNECTIONS]
declare(strict_types=1);

[, $cert, $key, $port, $logFile] = $argv;
$context = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false]]);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) exit(1);
echo "ready\n";

function expect($client, string $pattern, string $reply): string
{
    $line = fgets($client);
    if ($line === false || !preg_match($pattern, rtrim($line, "\r\n"))) exit(1);
    fwrite($client, $reply);
    return rtrim($line, "\r\n");
}

for ($i = 0; $i < (int) ($argv[5] ?? 1); $i++) {
    $client = stream_socket_accept($server, 20);
    if (!$client) exit(1);
    fwrite($client, "220 localhost test SMTP\r\n");
    expect($client, '/^EHLO /', "250-localhost\r\n250 STARTTLS\r\n");
    expect($client, '/^STARTTLS$/', "220 Ready\r\n");
    if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) { fclose($client); continue; } // client rejected the certificate
    expect($client, '/^EHLO /', "250-localhost\r\n250 AUTH LOGIN\r\n");
    expect($client, '/^AUTH LOGIN$/', "334 VXNlcm5hbWU6\r\n");
    $user = base64_decode(expect($client, '/^[A-Za-z0-9+\/=]+$/', "334 UGFzc3dvcmQ6\r\n"));
    $pass = base64_decode(expect($client, '/^[A-Za-z0-9+\/=]+$/', "235 Authenticated\r\n"));
    file_put_contents($logFile, "Auth: $user/$pass\n", FILE_APPEND);
    // Any number of messages per session, until QUIT or disconnect. No 8BITMIME: 8-bit data is refused.
    while (($line = fgets($client)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === 'QUIT') { fwrite($client, "221 Bye\r\n"); break; }
        if ($line === 'RSET') { fwrite($client, "250 Reset\r\n"); continue; }
        if (!preg_match('/^MAIL FROM:<.+>$/', $line)) exit(1);
        fwrite($client, "250 Sender accepted\r\n");
        $rcpt = rtrim((string) fgets($client), "\r\n");
        if (!preg_match('/^RCPT TO:<[^>]+>$/', $rcpt)) exit(1);
        if (str_contains($rcpt, 'abgelehnt')) { fwrite($client, "550 No such user\r\n"); continue; }
        if (str_contains($rcpt, 'abbruch')) break; // simulate a dropped connection
        fwrite($client, "250 Recipient accepted\r\n");
        expect($client, '/^DATA$/', "354 End with a dot\r\n");
        $message = '';
        while (($data = fgets($client)) !== false && $data !== ".\r\n") $message .= $data;
        file_put_contents($logFile, "$line\n$rcpt\n$message---\n", FILE_APPEND);
        fwrite($client, preg_match('/[^\x00-\x7f]/', $message) ? "554 8-bit data not supported\r\n" : "250 Queued\r\n");
    }
    fclose($client);
}
