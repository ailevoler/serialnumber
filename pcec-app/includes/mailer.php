<?php
/**
 * Outgoing email. Uses the SMTP server configured in Admin → Email Settings, or PHP mail()
 * when no SMTP host is set (works on most shared hosts, e.g. Hostinger).
 */

function mail_config(): array
{
    $host = trim((string) setting('mail_host', ''));
    $from = trim((string) setting('mail_from_email', ''));
    if ($from === '') {
        $domain = preg_replace('/^www\./', '', strtolower(parse_url(abs_url(''), PHP_URL_HOST) ?: 'localhost'));
        $from = 'no-reply@' . $domain;
    }
    return [
        'host' => $host,
        'port' => (int) setting('mail_port', '587') ?: 587,
        'encryption' => in_array(setting('mail_encryption', 'tls'), ['ssl', 'tls', 'none'], true) ? setting('mail_encryption', 'tls') : 'tls',
        'username' => (string) setting('mail_username', ''),
        'password' => setting_secret('mail_password'),
        'from_email' => $from,
        'from_name' => setting('mail_from_name', 'PCEC Connect') ?: 'PCEC Connect',
    ];
}

/** Send an email. Returns true on success; the last error is kept in $GLOBALS['mail_error']. */
function send_mail(string $to, string $subject, string $html, string $text = ''): bool
{
    $GLOBALS['mail_error'] = null;
    $c = mail_config();
    $text = $text !== '' ? $text : trim(html_entity_decode(strip_tags(preg_replace('~<br\s*/?>|</p>~i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
    $boundary = 'b' . bin2hex(random_bytes(8));
    $fromHeader = mb_encode_mimeheader($c['from_name'], 'UTF-8') . ' <' . $c['from_email'] . '>';
    $headers = [
        'From: ' . $fromHeader,
        'Reply-To: ' . (setting('about_office_email') ?: $c['from_email']),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: PCEC Connect',
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "--$boundary--\r\n";
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');

    try {
        if ($c['host'] === '') {
            $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), '-f' . $c['from_email']);
            if (!$ok) throw new RuntimeException('PHP mail() is not available on this server. Set up SMTP in Admin → Email Settings.');
            return true;
        }
        smtp_send($c, $to, $encodedSubject, $headers, $body);
        return true;
    } catch (Throwable $e) {
        $GLOBALS['mail_error'] = $e->getMessage();
        error_log('Mail to ' . $to . ' failed: ' . $e->getMessage());
        return false;
    }
}

function smtp_send(array $c, string $to, string $subject, array $headers, string $body): void
{
    $remote = ($c['encryption'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'];
    $fp = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException("Cannot connect to $remote ($errstr)");
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function (?string $line, array $expect) use ($fp, $read): string {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $res = $read();
        if (!in_array((int) substr($res, 0, 3), $expect, true)) {
            throw new RuntimeException('SMTP error: ' . trim($res ?: 'no response'));
        }
        return $res;
    };
    $host = parse_url(abs_url(''), PHP_URL_HOST) ?: 'localhost';
    $cmd(null, [220]);
    $cmd("EHLO $host", [250]);
    if ($c['encryption'] === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('Could not start TLS encryption.');
        }
        $cmd("EHLO $host", [250]);
    }
    if ($c['username'] !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($c['username']), [334]);
        $cmd(base64_encode($c['password']), [235]);
    }
    $cmd('MAIL FROM:<' . $c['from_email'] . '>', [250]);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', [354]);
    $msg = implode("\r\n", array_merge(['To: ' . $to, 'Subject: ' . $subject, 'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>'], $headers)) . "\r\n\r\n" . $body;
    $msg = preg_replace('/^\./m', '..', $msg); // dot-stuffing
    $cmd($msg . "\r\n.", [250]);
    $cmd('QUIT', [221]);
    fclose($fp);
}

/** Branded HTML email body with an optional button. */
function email_layout(string $category, string $title, string $introHtml, ?string $buttonUrl = null, ?string $buttonLabel = null, string $footHtml = ''): string
{
    $btn = $buttonUrl ? '<p style="margin:28px 0"><a href="' . e($buttonUrl) . '" style="background:#1a6cf0;color:#fff;text-decoration:none;font-weight:700;padding:14px 26px;border-radius:12px;display:inline-block">' . e($buttonLabel) . '</a></p>'
        . '<p style="font-size:13px;color:#66738c">If the button does not work, copy this link into your browser:<br><a href="' . e($buttonUrl) . '" style="color:#1a6cf0;word-break:break-all">' . e($buttonUrl) . '</a></p>' : '';
    return '<!doctype html><html><body style="margin:0;background:#f2f5fb;font-family:Segoe UI,Arial,sans-serif;color:#13213d">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:18px;overflow:hidden">'
        . '<tr><td style="background:#0b2a6b;color:#fff;padding:20px 28px"><strong style="font-size:18px">PCEC Connect</strong><br><span style="font-size:12px;opacity:.85">Philippine Council of Evangelical Churches</span></td></tr>'
        . '<tr><td style="padding:28px">'
        . '<p style="margin:0 0 6px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#1a6cf0;font-weight:700">' . e($category) . '</p>'
        . '<h1 style="margin:0 0 16px;font-size:24px;color:#0b2a6b">' . e($title) . '</h1>'
        . '<div style="font-size:15px;line-height:1.6">' . $introHtml . '</div>' . $btn . $footHtml
        . '</td></tr><tr><td style="background:#f6f8fc;padding:16px 28px;font-size:12px;color:#66738c">For your PCEC Connect account. Keep your password and links private.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Create a single-use email confirmation link and send it. */
function send_confirmation_email(array $user): bool
{
    $token = bin2hex(random_bytes(32));
    q("UPDATE email_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = 'confirm' AND used_at IS NULL", [$user['id']]);
    q("INSERT INTO email_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, 'confirm', ?, NOW() + INTERVAL 48 HOUR)",
        [$user['id'], hash('sha256', $token)]);
    $link = abs_url('confirm.php?token=' . $token);
    $_SESSION['last_mail_link'] = $link; // shown on screen only on localhost when sending fails
    $html = email_layout('Confirm your address', 'Welcome to PCEC Connect, ' . $user['first_name'] . '!',
        '<p>Thank you for creating your PCEC member account. Please confirm your email address to finish signing up.</p>',
        $link, 'Confirm my email',
        '<p style="font-size:13px;color:#66738c">This link expires in 48 hours. If you did not sign up, you can ignore this email.</p>');
    return send_mail($user['email'], 'Confirm your PCEC Connect account', $html);
}

function send_password_reset_email(array $user, string $link): bool
{
    $_SESSION['last_mail_link'] = $link;
    $html = email_layout('Account recovery', 'Reset your password',
        '<p>Hello ' . e($user['first_name']) . ',</p><p>We received a request to reset the password for your PCEC Connect account.</p>',
        $link, 'Choose a new password',
        '<p style="font-size:13px;color:#66738c">This link expires in one hour. If you did not ask for this, you can ignore this email — your password will not change.</p>');
    return send_mail($user['email'], 'Reset your PCEC Connect password', $html);
}

/** True when the visitor is on the same machine (local development). */
function is_local_request(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}
