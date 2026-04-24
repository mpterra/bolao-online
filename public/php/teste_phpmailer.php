<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../../php/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../../php/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../php/PHPMailer/src/SMTP.php';

$mail = new PHPMailer(true);

try {
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = 'html';

    $mail->isSMTP();

    $mail->Host = 'mail.bolaodothiago.com.br';
    $mail->SMTPAuth = true;
    $mail->Username = 'admin@bolaodothiago.com.br';
    $mail->Password = 'COLOQUE_A_SENHA_REAL_AQUI';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;

    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';

    $mail->setFrom('admin@bolaodothiago.com.br', 'Thiago do Bolao');
    $mail->addReplyTo('admin@bolaodothiago.com.br', 'Thiago do Bolao');

    // Destinatário externo
    $mail->addAddress('mauriciopterra@gmail.com', 'Mauricio');

    // Cópia oculta local para confirmar se a MESMA mensagem entrou no domínio
    $mail->addBCC('admin@bolaodothiago.com.br', 'Admin Bolao');

    $mail->Sender = 'admin@bolaodothiago.com.br';

    $mail->isHTML(true);
    $mail->Subject = 'TESTE PHPMAILER COM BCC LOCAL';
    $mail->Body = '
        <p>Teste enviado com PHPMailer.</p>
        <p>Destino principal: Gmail.</p>
        <p>Copia oculta: admin@bolaodothiago.com.br.</p>
        <p>Horario: ' . date('Y-m-d H:i:s') . '</p>
    ';
    $mail->AltBody = 'Teste enviado com PHPMailer. Destino Gmail com copia oculta local. Horario: ' . date('Y-m-d H:i:s');

    $mail->send();

    echo '<hr>';
    echo '<pre>ENVIADO COM SUCESSO PELO PHPMAILER</pre>';
} catch (Exception $e) {
    echo '<hr>';
    echo '<pre>';
    echo "FALHOU\n";
    echo "Erro PHPMailer: " . $mail->ErrorInfo . "\n";
    echo '</pre>';
}