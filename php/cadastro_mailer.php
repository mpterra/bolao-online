<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

function cadastro_mail_log(string $message): void {
    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/cadastro-mail.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    error_log('[cadastro_mailer] ' . trim($line));
}

function cadastro_admin_recipients(): array {
    return [
        ["email" => "thiagopterra@gmail.com", "name" => "Thiago"],
        ["email" => "mauriciopterra@gmail.com", "name" => "Mauricio"],
    ];
}

function load_mail_config_for_register(): array {
    $defaultMailConfig = [
        "host"       => "mail.bolaodothiago.com.br",
        "port"       => 465,
        "encryption" => "ssl",
        "auto_relax_tls" => true,
        "verify_peer" => true,
        "verify_peer_name" => true,
        "allow_self_signed" => false,
        "username"   => "admin@bolaodothiago.com.br",
        "password"   => "Eng%3571Hawaii",
        "from_email" => "admin@bolaodothiago.com.br",
        "from_name"  => "Bolão do Thiago",
        "timeout"    => 20,
    ];

    $mailConfigPaths = [
        __DIR__ . "/../config/mail.php",
        __DIR__ . "/../../config/mail.php",
        __DIR__ . "/../../../config/mail.php",
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

function load_phpmailer_for_register(): bool {
    $possibleBases = [
        __DIR__ . "/PHPMailer/src",
        __DIR__ . "/../PHPMailer/src",
        __DIR__ . "/../../php/PHPMailer/src",
        __DIR__ . "/../../../php/PHPMailer/src",
    ];

    foreach ($possibleBases as $base) {
        $exceptionFile = $base . "/Exception.php";
        $phpMailerFile = $base . "/PHPMailer.php";
        $smtpFile      = $base . "/SMTP.php";

        if (is_file($exceptionFile) && is_file($phpMailerFile) && is_file($smtpFile)) {
            require_once $exceptionFile;
            require_once $phpMailerFile;
            require_once $smtpFile;
            cadastro_mail_log("PHPMailer carregado de: " . $base);
            return true;
        }
    }

    cadastro_mail_log("PHPMailer nao encontrado nos caminhos esperados.");
    return false;
}

function send_email_phpmailer(
    array $cfg,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): bool {
    try {
        $host       = trim((string)($cfg["host"] ?? ""));
        $port       = (int)($cfg["port"] ?? 0);
        $encryption = strtolower(trim((string)($cfg["encryption"] ?? "ssl")));
        $username   = trim((string)($cfg["username"] ?? ""));
        $password   = (string)($cfg["password"] ?? "");
        $fromEmail  = trim((string)($cfg["from_email"] ?? $username));
        $fromName   = trim((string)($cfg["from_name"] ?? "Bolao do Thiago"));
        $timeout    = (int)($cfg["timeout"] ?? 20);

        if ($host === "" || $port <= 0 || $username === "" || $password === "" || $fromEmail === "") {
            cadastro_mail_log("Configuracao SMTP incompleta para PHPMailer.");
            return false;
        }

        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->Port       = $port;
        $mail->Timeout    = $timeout;
        $mail->SMTPDebug  = 0;

        if ($encryption === "ssl") {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === "tls") {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->CharSet  = "UTF-8";
        $mail->Encoding = "base64";

        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);
        $mail->Sender = $fromEmail;

        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();
        cadastro_mail_log("Email enviado via PHPMailer para {$toEmail}.");
        return true;
    } catch (Throwable $e) {
        cadastro_mail_log("Erro PHPMailer para {$toEmail}: " . $e->getMessage());

        if (isset($mail) && $mail instanceof PHPMailer && $mail->ErrorInfo !== "") {
            cadastro_mail_log("PHPMailer ErrorInfo para {$toEmail}: " . $mail->ErrorInfo);
        }

        return false;
    }
}

function send_admin_mail_with_retry(
    array $mailConfig,
    string $recipientEmail,
    string $recipientName,
    string $primarySubject,
    string $fallbackSubject,
    string $htmlBody,
    string $textBody
): bool {
    $sent = send_email_phpmailer($mailConfig, $recipientEmail, $recipientName, $primarySubject, $htmlBody, $textBody);
    if ($sent) {
        return true;
    }

    cadastro_mail_log("Retry alerta admin para {$recipientEmail} com assunto ASCII.");

    return send_email_phpmailer($mailConfig, $recipientEmail, $recipientName, $fallbackSubject, $htmlBody, $textBody);
}

function notify_new_user_register(array $mailConfig, int $newUserId, string $nomeCompleto, string $email, string $telefone): bool {
    $nomeSafe = htmlspecialchars($nomeCompleto, ENT_QUOTES, 'UTF-8');
    $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $telefoneSafe = htmlspecialchars($telefone, ENT_QUOTES, 'UTF-8');

    $htmlBody = ""
        . "<h2>Novo usuário cadastrado</h2>"
        . "<p><strong>Nome completo:</strong> {$nomeSafe}</p>"
        . "<p><strong>ID:</strong> {$newUserId}</p>"
        . "<p><strong>Email:</strong> {$emailSafe}</p>"
        . "<p><strong>Telefone:</strong> {$telefoneSafe}</p>";

    $textBody = ""
        . "Novo usuário cadastrado\n\n"
        . "Nome completo: {$nomeCompleto}\n"
        . "ID: {$newUserId}\n"
        . "Email: {$email}\n"
        . "Telefone: {$telefone}\n";

    $allSent = true;

    foreach (cadastro_admin_recipients() as $recipient) {
        $recipientEmail = (string)$recipient["email"];
        $recipientName = (string)$recipient["name"];

        $sent = send_admin_mail_with_retry(
            $mailConfig,
            $recipientEmail,
            $recipientName,
            "Alô Alô, um Moisés se cadastrou",
            "Alo Alo, um Moises se cadastrou",
            $htmlBody,
            $textBody
        );

        if (!$sent) {
            $allSent = false;
            cadastro_mail_log("Falha ao enviar alerta admin para {$recipientEmail}.");
        } else {
            cadastro_mail_log("Alerta admin enviado para {$recipientEmail}.");
        }
    }

    return $allSent;
}

function cadastro_format_birth_date_for_mail(string $dataNascimento): string {
    $trimmed = trim($dataNascimento);
    if ($trimmed === '') {
        return '';
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $trimmed);
    if ($dt instanceof DateTimeImmutable) {
        return $dt->format('d/m/Y');
    }

    return $trimmed;
}

function notify_user_profile_update(
    array $mailConfig,
    int $userId,
    string $nomeCompleto,
    string $email,
    string $telefone,
    string $cidade,
    string $estado,
    string $dataNascimento,
    bool $passwordChanged
): bool {
    $nomeSafe = htmlspecialchars($nomeCompleto, ENT_QUOTES, 'UTF-8');
    $emailSafe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $telefoneSafe = htmlspecialchars($telefone, ENT_QUOTES, 'UTF-8');
    $cidadeSafe = htmlspecialchars($cidade, ENT_QUOTES, 'UTF-8');
    $estadoSafe = htmlspecialchars($estado, ENT_QUOTES, 'UTF-8');
    $dataNascimentoFmt = cadastro_format_birth_date_for_mail($dataNascimento);
    $dataNascimentoSafe = htmlspecialchars($dataNascimentoFmt, ENT_QUOTES, 'UTF-8');
    $passwordChangedLabel = $passwordChanged ? 'Sim' : 'Não';

    $htmlBody = ""
        . "<h2>Usuário atualizou os dados cadastrais</h2>"
        . "<p><strong>Nome completo:</strong> {$nomeSafe}</p>"
        . "<p><strong>ID:</strong> {$userId}</p>"
        . "<p><strong>Email:</strong> {$emailSafe}</p>"
        . "<p><strong>Telefone:</strong> {$telefoneSafe}</p>"
        . "<p><strong>Cidade/UF:</strong> {$cidadeSafe}/{$estadoSafe}</p>"
        . "<p><strong>Data de nascimento:</strong> {$dataNascimentoSafe}</p>"
        . "<p><strong>Senha alterada:</strong> {$passwordChangedLabel}</p>";

    $textBody = ""
        . "Usuário atualizou os dados cadastrais\n\n"
        . "Nome completo: {$nomeCompleto}\n"
        . "ID: {$userId}\n"
        . "Email: {$email}\n"
        . "Telefone: {$telefone}\n"
        . "Cidade/UF: {$cidade}/{$estado}\n"
        . "Data de nascimento: {$dataNascimentoFmt}\n"
        . "Senha alterada: {$passwordChangedLabel}\n";

    $allSent = true;

    foreach (cadastro_admin_recipients() as $recipient) {
        $recipientEmail = (string)$recipient["email"];
        $recipientName = (string)$recipient["name"];

        $sent = send_admin_mail_with_retry(
            $mailConfig,
            $recipientEmail,
            $recipientName,
            "Usuário atualizou os dados cadastrais",
            "Usuario atualizou os dados cadastrais",
            $htmlBody,
            $textBody
        );

        if (!$sent) {
            $allSent = false;
            cadastro_mail_log("Falha ao enviar alerta de atualização para {$recipientEmail}.");
        } else {
            cadastro_mail_log("Alerta de atualização enviado para {$recipientEmail}.");
        }
    }

    return $allSent;
}

function send_welcome_email_to_new_user(array $mailConfig, string $toEmail, string $nomeCompleto): bool {
    $toName = $nomeCompleto;
    $subject = "Bem-vindo ao Bolao do Thiago! Sua resenha acaba de ganhar reforco";

    $nomeSafe = htmlspecialchars($nomeCompleto, ENT_QUOTES, 'UTF-8');

    $htmlBody = ""
        . "<h2>Fala, {$nomeSafe}! 🎉</h2>"
        . "<p>Seu cadastro foi confirmado e agora voce ja pode entrar no <strong>Bolao do Thiago</strong>.</p>"
        . "<p>Prepare o palpite, afie a intuicao e venha brigar pelo topo do ranking.</p>"
        . "<p>Regra nao escrita do bolao: quem acerta, comemora. Quem erra, diz que foi estrategico.</p>"
        . "<p>Boa sorte e divirta-se! ⚽🏆</p>";

    $textBody = ""
        . "Fala, {$nomeCompleto}!\n\n"
        . "Seu cadastro foi confirmado e agora voce ja pode entrar no Bolao do Thiago.\n"
        . "Prepare o palpite, afie a intuicao e venha brigar pelo topo do ranking.\n\n"
        . "Regra nao escrita do bolao: quem acerta, comemora. Quem erra, diz que foi estrategico.\n\n"
        . "Boa sorte e divirta-se!";

    $sent = send_email_phpmailer($mailConfig, $toEmail, $toName, $subject, $htmlBody, $textBody);

    if (!$sent) {
        cadastro_mail_log("Falha ao enviar boas-vindas para {$toEmail}.");
    } else {
        cadastro_mail_log("Boas-vindas enviada para {$toEmail}.");
    }

    return $sent;
}