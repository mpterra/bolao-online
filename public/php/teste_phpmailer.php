<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../php/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../../php/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../php/PHPMailer/src/SMTP.php';

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();

    $mail->Host = 'mail.bolaodothiago.com.br';
    $mail->SMTPAuth = true;
    $mail->Username = 'admin@bolaodothiago.com.br';
    $mail->Password = 'COLOQUE_A_SENHA_AQUI';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;

    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';

    $mail->setFrom('admin@bolaodothiago.com.br', 'Thiago do Bolão');
    $mail->addReplyTo('admin@bolaodothiago.com.br', 'Thiago do Bolão');

    $mail->addAddress('mauriciopterra@gmail.com', 'Mauricio');

    $mail->isHTML(true);
    $mail->Subject = 'TESTE PHPMAILER HOSTGATOR';
    $mail->Body = '<p>Teste enviado com <strong>PHPMailer</strong> usando SMTP HostGator.</p>';
    $mail->AltBody = 'Teste enviado com PHPMailer usando SMTP HostGator.';

    $mail->send();

    echo '<pre>ENVIADO COM SUCESSO PELO PHPMAILER</pre>';
} catch (Exception $e) {
    echo '<pre>';
    echo "FALHOU\n";
    echo "Erro PHPMailer: " . $mail->ErrorInfo . "\n";
    echo '</pre>';
}