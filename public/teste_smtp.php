<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Resolve smtp_mailer.php regardless of server (local XAMPP or HostGator)
$candidates = [
    __DIR__ . '/../php/smtp_mailer.php',          // local XAMPP: public/../php/
    '/home2/mauri075/php/smtp_mailer.php',         // HostGator
];
$loaded = false;
foreach ($candidates as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    die('<pre>ERRO: smtp_mailer.php não encontrado em nenhum caminho esperado.</pre>');
}

$config = [
    "host"       => "mail.bolaodothiago.com.br",
    "port"       => 465,
    "encryption" => "ssl",
    "username"   => "admin@bolaodothiago.com.br",
    "password"   => "Eng%3571Hawaii",
    "from_email" => "admin@bolaodothiago.com.br",
    "from_name"  => "Thiago do Bolão",
    "timeout"    => 20,
];

$toEmail = "mauriciopterra@gmail.com";
$toName  = "Mauricio";

$subject  = "TESTE PHP SMTP HOSTGATOR";
$textBody = "Teste simples enviado pelo PHP via SMTP HostGator.";
$htmlBody = "<p>Teste simples enviado pelo <strong>PHP via SMTP HostGator</strong>.</p>";

$ok = smtp_send_mail(
    $config,
    $toEmail,
    $toName,
    $subject,
    $htmlBody,
    $textBody
);

echo "<pre>";
if ($ok) {
    echo "ENVIADO COM SUCESSO PELO PHP\n";
} else {
    echo "FALHOU\n";
    echo smtp_get_last_error() . "\n";
}
echo "</pre>";
