<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

function bet_notify_log(string $message): void {
    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/bet-update-mail.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    error_log('[bet_update_notifier] ' . trim($line));
}

function bet_mail_config(): array {
    $defaultMailConfig = [
        'host' => 'mail.bolaodothiago.com.br',
        'port' => 465,
        'encryption' => 'ssl',
        'username' => 'admin@bolaodothiago.com.br',
        'password' => 'Eng%3571Hawaii',
        'from_email' => 'admin@bolaodothiago.com.br',
        'from_name' => 'Bolao do Thiago',
        'timeout' => 20,
    ];

    $mailConfigPaths = [
        __DIR__ . '/../config/mail.php',
        __DIR__ . '/../../config/mail.php',
        __DIR__ . '/../../../config/mail.php',
    ];

    foreach ($mailConfigPaths as $mailConfigPath) {
        if (!is_file($mailConfigPath)) {
            continue;
        }

        $loadedMailConfig = require $mailConfigPath;
        if (is_array($loadedMailConfig)) {
            return array_merge($defaultMailConfig, $loadedMailConfig);
        }
    }

    return $defaultMailConfig;
}

function bet_notify_cooldown_seconds(): int {
    $default = 300;

    $env = getenv('BET_UPDATE_NOTIFY_COOLDOWN_SECONDS');
    if (is_string($env) && $env !== '') {
        $v = (int)$env;
        if ($v >= 30) {
            return $v;
        }
    }

    $cfg = bet_mail_config();
    $fromConfig = (int)($cfg['bet_update_notify_cooldown_seconds'] ?? 0);
    if ($fromConfig >= 30) {
        return $fromConfig;
    }

    return $default;
}

function bet_notify_idle_seconds(): int {
    $default = 90;

    $env = getenv('BET_UPDATE_NOTIFY_IDLE_SECONDS');
    if (is_string($env) && $env !== '') {
        $v = (int)$env;
        if ($v >= 20) {
            return $v;
        }
    }

    $cfg = bet_mail_config();
    $fromConfig = (int)($cfg['bet_update_notify_idle_seconds'] ?? 0);
    if ($fromConfig >= 20) {
        return $fromConfig;
    }

    return $default;
}

function bet_load_phpmailer(): bool {
    static $loaded = false;
    if ($loaded) {
        return true;
    }

    $possibleBases = [
        __DIR__ . '/PHPMailer/src',
        __DIR__ . '/../PHPMailer/src',
        __DIR__ . '/../../php/PHPMailer/src',
        __DIR__ . '/../../../php/PHPMailer/src',
    ];

    foreach ($possibleBases as $base) {
        $exceptionFile = $base . '/Exception.php';
        $phpMailerFile = $base . '/PHPMailer.php';
        $smtpFile = $base . '/SMTP.php';

        if (is_file($exceptionFile) && is_file($phpMailerFile) && is_file($smtpFile)) {
            require_once $exceptionFile;
            require_once $phpMailerFile;
            require_once $smtpFile;
            $loaded = true;
            return true;
        }
    }

    bet_notify_log('PHPMailer nao encontrado para notificacao de aposta.');
    return false;
}

function bet_send_email(array $cfg, string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): bool {
    try {
        $host = trim((string)($cfg['host'] ?? ''));
        $port = (int)($cfg['port'] ?? 0);
        $encryption = strtolower(trim((string)($cfg['encryption'] ?? 'ssl')));
        $username = trim((string)($cfg['username'] ?? ''));
        $password = (string)($cfg['password'] ?? '');
        $fromEmail = trim((string)($cfg['from_email'] ?? $username));
        $fromName = trim((string)($cfg['from_name'] ?? 'Bolao do Thiago'));
        $timeout = (int)($cfg['timeout'] ?? 20);

        if ($host === '' || $port <= 0 || $username === '' || $password === '' || $fromEmail === '') {
            bet_notify_log('Configuracao SMTP incompleta para notificacao de aposta.');
            return false;
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Port = $port;
        $mail->Timeout = $timeout;
        $mail->SMTPDebug = 0;

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);
        $mail->Sender = $fromEmail;
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();
        return true;
    } catch (Throwable $e) {
        bet_notify_log('Falha envio para ' . $toEmail . ': ' . $e->getMessage());

        if (isset($mail) && $mail instanceof PHPMailer && $mail->ErrorInfo !== '') {
            bet_notify_log('PHPMailer ErrorInfo para ' . $toEmail . ': ' . $mail->ErrorInfo);
        }

        return false;
    }
}

function bet_notify_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bet_update_notifications (
        usuario_id INT NOT NULL PRIMARY KEY,
        last_sent_at DATETIME NULL,
        pending_updates INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bet_update_notification_items (
        usuario_id INT NOT NULL,
        item_key VARCHAR(80) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (usuario_id, item_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function bet_notify_track_update(PDO $pdo, int $usuarioId, array $itemKeys = []): void {
    if ($usuarioId <= 0) {
        return;
    }

    try {
        bet_notify_ensure_table($pdo);

        $normalized = [];
        foreach ($itemKeys as $key) {
            $k = trim((string)$key);
            if ($k === '') continue;
            if (strlen($k) > 80) $k = substr($k, 0, 80);
            $normalized[$k] = true;
        }

        if (count($normalized) === 0) {
            $normalized['generic'] = true;
        }

        $pdo->beginTransaction();

        $stUser = $pdo->prepare('INSERT INTO bet_update_notifications (usuario_id, last_sent_at, pending_updates) VALUES (?, NULL, 0) ON DUPLICATE KEY UPDATE usuario_id = usuario_id');
        $stUser->execute([$usuarioId]);

        $stItem = $pdo->prepare('INSERT IGNORE INTO bet_update_notification_items (usuario_id, item_key) VALUES (?, ?)');
        foreach (array_keys($normalized) as $itemKey) {
            $stItem->execute([$usuarioId, $itemKey]);
        }

        $stCount = $pdo->prepare('SELECT COUNT(*) FROM bet_update_notification_items WHERE usuario_id = ?');
        $stCount->execute([$usuarioId]);
        $pending = (int)$stCount->fetchColumn();

        $stUpd = $pdo->prepare('UPDATE bet_update_notifications SET pending_updates = ? WHERE usuario_id = ?');
        $stUpd->execute([$pending, $usuarioId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        bet_notify_log('Falha ao marcar update pendente. usuario_id=' . $usuarioId . '. ' . $e->getMessage());
    }
}

function bet_notify_flush(PDO $pdo, int $usuarioId, bool $force = true): array {
    $out = [
        'ok' => false,
        'sent' => false,
        'pending' => 0,
        'reason' => 'unknown',
    ];

    if ($usuarioId <= 0) {
        $out['reason'] = 'invalid-user';
        return $out;
    }

    if (!bet_load_phpmailer()) {
        $out['reason'] = 'phpmailer-missing';
        return $out;
    }

    $cooldownSeconds = bet_notify_cooldown_seconds();

    try {
        bet_notify_ensure_table($pdo);
    } catch (Throwable $e) {
        $out['reason'] = 'table-error';
        bet_notify_log('Falha criando tabela bet_update_notifications: ' . $e->getMessage());
        return $out;
    }

    try {
        $stUser = $pdo->prepare('SELECT id, nome, email, telefone FROM usuarios WHERE id = ? LIMIT 1');
        $stUser->execute([$usuarioId]);
        $user = $stUser->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            $out['reason'] = 'user-not-found';
            return $out;
        }

        $pdo->beginTransaction();

        $stLock = $pdo->prepare('SELECT usuario_id, last_sent_at, pending_updates FROM bet_update_notifications WHERE usuario_id = ? FOR UPDATE');
        $stLock->execute([$usuarioId]);
        $row = $stLock->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $pdo->prepare('INSERT INTO bet_update_notifications (usuario_id, last_sent_at, pending_updates) VALUES (?, NULL, 0) ON DUPLICATE KEY UPDATE usuario_id = usuario_id')
                ->execute([$usuarioId]);
            $lastSentAt = null;
            $pending = 0;
        } else {
            $lastSentAt = isset($row['last_sent_at']) ? (string)$row['last_sent_at'] : null;
            $pending = isset($row['pending_updates']) ? (int)$row['pending_updates'] : 0;
        }

        $stCount = $pdo->prepare('SELECT COUNT(*) FROM bet_update_notification_items WHERE usuario_id = ?');
        $stCount->execute([$usuarioId]);
        $pending = (int)$stCount->fetchColumn();
        $pdo->prepare('UPDATE bet_update_notifications SET pending_updates = ? WHERE usuario_id = ?')
            ->execute([$pending, $usuarioId]);

        if ($pending <= 0) {
            $pdo->commit();
            $out['ok'] = true;
            $out['reason'] = 'no-pending';
            return $out;
        }

        if (!$force) {
            $shouldSend = true;
            if ($lastSentAt !== null && $lastSentAt !== '') {
                $lastTs = strtotime($lastSentAt);
                $shouldSend = ($lastTs === false) || ((time() - $lastTs) >= $cooldownSeconds);
            }

            if (!$shouldSend) {
                $pdo->commit();
                $out['ok'] = true;
                $out['pending'] = $pending;
                $out['reason'] = 'cooldown';
                return $out;
            }
        }

        $pdo->commit();

        $mailConfig = bet_mail_config();
        $subject = 'Alô Alô, um Moisés atualizou aposta';

        $nome = (string)($user['nome'] ?? '');
        $email = (string)($user['email'] ?? '');
        $telefone = (string)($user['telefone'] ?? '');
        $id = (int)($user['id'] ?? 0);

        $nomeSafe = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
        $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $telefoneSafe = htmlspecialchars($telefone, ENT_QUOTES, 'UTF-8');

        $htmlBody = ''
            . '<h2>Um usuário atualizou aposta</h2>'
            . '<p><strong>Nome completo:</strong> ' . $nomeSafe . '</p>'
            . '<p><strong>ID:</strong> ' . $id . '</p>'
            . '<p><strong>Telefone:</strong> ' . $telefoneSafe . '</p>'
            . '<p><strong>Email:</strong> ' . $emailSafe . '</p>';

        $textBody = ''
            . "Um usuário atualizou aposta\n\n"
            . 'Nome completo: ' . $nome . "\n"
            . 'ID: ' . $id . "\n"
            . 'Telefone: ' . $telefone . "\n"
            . 'Email: ' . $email . "\n"
            . 'Partidas/jogos acumulados: ' . $pending . "\n";

        $htmlBody .= '<p><strong>Partidas/jogos acumulados:</strong> ' . $pending . '</p>';

        $recipients = [
            ['email' => 'thiagopterra@gmail.com', 'name' => 'Thiago'],
            ['email' => 'mauriciopterra@gmail.com', 'name' => 'Mauricio'],
        ];

        $allSent = true;
        foreach ($recipients as $recipient) {
            $sent = bet_send_email(
                $mailConfig,
                (string)$recipient['email'],
                (string)$recipient['name'],
                $subject,
                $htmlBody,
                $textBody
            );
            if (!$sent) {
                $allSent = false;
            }
        }

        $pdo->beginTransaction();
        if ($allSent) {
            $pdo->prepare('DELETE FROM bet_update_notification_items WHERE usuario_id = ?')
                ->execute([$usuarioId]);
            $pdo->prepare('UPDATE bet_update_notifications SET last_sent_at = NOW(), pending_updates = 0 WHERE usuario_id = ?')
                ->execute([$usuarioId]);
            $out['ok'] = true;
            $out['sent'] = true;
            $out['pending'] = $pending;
            $out['reason'] = 'sent';
        } else {
            $out['ok'] = false;
            $out['sent'] = false;
            $out['pending'] = $pending;
            $out['reason'] = 'send-failed';
        }
        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        bet_notify_log('Falha geral notificador de apostas: ' . $e->getMessage());
        $out['reason'] = 'exception';
    }

    return $out;
}

function bet_notify_maybe_send(PDO $pdo, int $usuarioId, ?int $cooldownSeconds = null): void {
    if ($cooldownSeconds !== null && $cooldownSeconds >= 30) {
        // compatibilidade: mantém assinatura antiga sem quebrar chamadas antigas
    }

    bet_notify_track_update($pdo, $usuarioId);
    $idleSec = bet_notify_idle_seconds();
    $force = false;

    // Heurística simples para manter compatibilidade com comportamento anterior:
    // tenta flush não-forçado (respeita cooldown).
    bet_notify_flush($pdo, $usuarioId, $force);
}
