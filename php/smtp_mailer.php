<?php
declare(strict_types=1);

$GLOBALS['SMTP_LAST_ERROR'] = '';

function smtp_set_last_error(string $message): void
{
    $GLOBALS['SMTP_LAST_ERROR'] = $message;
}

function smtp_get_last_error(): string
{
    return (string)($GLOBALS['SMTP_LAST_ERROR'] ?? '');
}

function smtp_send_mail(array $config, string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): bool
{
    smtp_set_last_error('');

    $host = trim((string)($config['host'] ?? ''));
    $port = (int)($config['port'] ?? 0);
    $encryption = strtolower(trim((string)($config['encryption'] ?? 'tls')));
    $username = trim((string)($config['username'] ?? ''));
    $password = (string)($config['password'] ?? '');
    $fromEmail = trim((string)($config['from_email'] ?? $username));
    $fromName = trim((string)($config['from_name'] ?? ''));
    $timeout = (int)($config['timeout'] ?? 20);

    if ($host === '' || $port <= 0 || $username === '' || $password === '' || $fromEmail === '') {
        $msg = '[smtp_mailer] Configuração SMTP incompleta.';
        smtp_set_last_error($msg);
        error_log($msg);
        return false;
    }

    $remote = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client(
        $remote . ':' . $port,
        $errorNumber,
        $errorString,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        $msg = '[smtp_mailer] Falha na conexão SMTP: ' . $errorString . ' (' . $errorNumber . ')';
        smtp_set_last_error($msg);
        error_log($msg);
        return false;
    }

    stream_set_timeout($socket, $timeout);

    try {
        smtp_expect($socket, [220]);

        $hostname = gethostname();
        if (!is_string($hostname) || $hostname === '') {
            $hostname = 'localhost';
        }

        smtp_command($socket, 'EHLO ' . $hostname, [250]);

        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);

            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('Não foi possível habilitar STARTTLS.');
            }

            smtp_command($socket, 'EHLO ' . $hostname, [250]);
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $message = smtp_build_message($fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody, $textBody);
        fwrite($socket, $message . "\r\n.\r\n");
        smtp_expect($socket, [250]);
        smtp_command($socket, 'QUIT', [221]);
    } catch (Throwable $e) {
        $msg = '[smtp_mailer] Erro SMTP: ' . $e->getMessage();
        smtp_set_last_error($msg);
        error_log($msg);
        fclose($socket);
        return false;
    }

    fclose($socket);
    return true;
}

function smtp_build_message(string $fromEmail, string $fromName, string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): string
{
    $boundary = 'bnd_' . bin2hex(random_bytes(12));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = [
        'Date: ' . date('r'),
        'From: ' . smtp_format_address($fromEmail, $fromName),
        'To: ' . smtp_format_address($toEmail, $toName),
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $textBody = str_replace(["\r\n", "\r"], "\n", $textBody);
    $htmlBody = str_replace(["\r\n", "\r"], "\n", $htmlBody);

    $parts = [];
    $parts[] = '--' . $boundary;
    $parts[] = 'Content-Type: text/plain; charset=UTF-8';
    $parts[] = 'Content-Transfer-Encoding: 8bit';
    $parts[] = '';
    $parts[] = smtp_escape_body($textBody);
    $parts[] = '--' . $boundary;
    $parts[] = 'Content-Type: text/html; charset=UTF-8';
    $parts[] = 'Content-Transfer-Encoding: 8bit';
    $parts[] = '';
    $parts[] = smtp_escape_body($htmlBody);
    $parts[] = '--' . $boundary . '--';
    $parts[] = '';

    return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
}

function smtp_format_address(string $email, string $name): string
{
    if ($name === '') {
        return '<' . $email . '>';
    }

    return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
}

function smtp_escape_body(string $body): string
{
    $lines = explode("\n", $body);
    foreach ($lines as &$line) {
        if (isset($line[0]) && $line[0] === '.') {
            $line = '.' . $line;
        }
    }

    return implode("\r\n", $lines);
}

function smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $expectedCodes);
}

function smtp_expect($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (preg_match('/^(\d{3})([\s-])/', $line, $matches) !== 1) {
            continue;
        }

        $code = (int)$matches[1];
        $separator = $matches[2];

        if ($separator === '-') {
            continue;
        }

        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('Resposta SMTP inesperada: ' . trim($response));
        }

        return $response;
    }

    throw new RuntimeException('Conexão SMTP encerrada sem resposta válida.');
}