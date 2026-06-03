<?php

function send_mail(string $to, string $subject, string $body): void {
    $cfg = $GLOBALS['CONFIG']['smtp'];
    if (empty($cfg['host'])) {
        // Mode dev / fallback : pas de host = on écrit le mail tel quel
        // dans mail.log pour que le sysop puisse le consulter / le rejouer.
        mail_log($to, $subject, $body);
        log_info('mail logged (no SMTP host configured)', ['to' => $to, 'subject' => $subject]);
        return;
    }
    try {
        smtp_send($cfg, $to, $subject, $body);
        log_info('mail sent', [
            'to' => $to, 'subject' => $subject,
            'host' => $cfg['host'], 'port' => (int)($cfg['port'] ?? 587),
        ]);
    } catch (Throwable $e) {
        // Trace l'erreur ET conserve le mail dans mail.log pour le rejouer.
        // notify_admin_error() utilise smtp_send() directement pour éviter
        // de boucler ici en cas de panne SMTP (anti-spam horaire en plus).
        notify_admin_error($e, 'SMTP send failed', [
            'to' => $to, 'subject' => $subject,
            'host' => $cfg['host'], 'port' => (int)($cfg['port'] ?? 587),
            'enc'  => (string)($cfg['encryption'] ?? ''),
        ]);
        mail_log($to, '[SMTP failed: ' . $e->getMessage() . '] ' . $subject, $body);
        throw $e; // l'appelant décide de la suite (la majorité catch et logge en plus)
    }
}

function mail_log(string $to, string $subject, string $body): void {
    $dir = dirname($GLOBALS['CONFIG']['db_path']);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = "===== " . date('c') . " =====\nTo: $to\nSubject: $subject\n\n$body\n\n";
    file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
}

function smtp_send(array $cfg, string $to, string $subject, string $body): void {
    $host = $cfg['host'];
    $port = (int)($cfg['port'] ?? 587);
    $enc  = $cfg['encryption'] ?? 'tls';
    $remote = ($enc === 'ssl') ? "ssl://$host:$port" : "$host:$port";

    $ctx = stream_context_create([]);
    $sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    }
    stream_set_timeout($sock, 15);

    $read = function() use ($sock) {
        $out = '';
        while (!feof($sock)) {
            $line = fgets($sock, 1024);
            if ($line === false) break;
            $out .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $out;
    };
    $expect = function(string $resp, string $code) {
        if (strpos($resp, $code) !== 0) {
            throw new RuntimeException("SMTP unexpected response: " . trim($resp));
        }
    };
    $cmd = function(string $c) use ($sock, $read, $expect) {
        fwrite($sock, $c . "\r\n");
        return $read();
    };

    $expect($read(), '220');
    $expect($cmd("EHLO mmidate"), '250');

    if ($enc === 'tls') {
        $expect($cmd("STARTTLS"), '220');
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException("STARTTLS handshake failed");
        }
        $expect($cmd("EHLO mmidate"), '250');
    }

    if (!empty($cfg['username'])) {
        $expect($cmd("AUTH LOGIN"), '334');
        $expect($cmd(base64_encode($cfg['username'])), '334');
        $expect($cmd(base64_encode($cfg['password'])), '235');
    }

    $from = $cfg['from'];
    $fromName = $cfg['from_name'] ?? $from;
    $expect($cmd("MAIL FROM:<$from>"), '250');
    $expect($cmd("RCPT TO:<$to>"), '250');
    $expect($cmd("DATA"), '354');

    $headers  = "From: " . encode_header_word($fromName) . " <$from>\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= "Subject: " . encode_header_word($subject) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= "Date: " . date('r') . "\r\n";

    $data = $headers . "\r\n" . str_replace("\r\n.", "\r\n..", $body);
    fwrite($sock, $data . "\r\n.\r\n");
    $expect($read(), '250');
    $cmd("QUIT");
    fclose($sock);
}

function encode_header_word(string $s): string {
    if (preg_match('/[\x80-\xff]/', $s)) {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
    return $s;
}
