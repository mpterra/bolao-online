<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/cadastro_mailer.php';
require_once __DIR__ . '/usuario_schema.php';

function redirect_profile_with_flash(string $message, string $type = 'error'): never {
    $_SESSION['flash_profile'] = [
        'type' => $type,
        'msg' => $message,
        'ts' => time(),
    ];

    header('Location: /meus_dados.php');
    exit;
}

function normalize_nome_update(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (class_exists('Transliterator')) {
        $value = transliterator_transliterate('Any-Latin; Latin-ASCII; [^\u0000-\u007F] remove', $value);
    } else {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($tmp !== false && $tmp !== null) {
            $value = $tmp;
        }
    }

    $value = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return trim($value);
}

function normalize_birth_date_update(string $value): string {
    $value = trim($value);
    if ($value === '') {
        redirect_profile_with_flash('Preencha a data de nascimento.', 'warn');
    }

    $formats = [
        'd/m/Y' => '!d/m/Y',
        'Y-m-d' => '!Y-m-d',
    ];

    $birthDate = null;

    foreach ($formats as $expectedFormat => $parserFormat) {
        $candidate = DateTimeImmutable::createFromFormat($parserFormat, $value);
        if (!$candidate instanceof DateTimeImmutable) {
            continue;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            continue;
        }

        if ($candidate->format($expectedFormat) !== $value) {
            continue;
        }

        $birthDate = $candidate;
        break;
    }

    if (!$birthDate instanceof DateTimeImmutable) {
        redirect_profile_with_flash('Data de nascimento inválida. Use DD/MM/AAAA.', 'warn');
    }

    $today = new DateTimeImmutable('today');
    if ($birthDate > $today) {
        redirect_profile_with_flash('Data de nascimento inválida.', 'warn');
    }

    return $birthDate->format('Y-m-d');
}

function normalize_phone_update(string $value): string {
    $digits = preg_replace('/\D+/', '', trim($value)) ?? '';

    if ((strlen($digits) === 12 || strlen($digits) === 13) && strncmp($digits, '55', 2) === 0) {
        $digits = substr($digits, 2);
    }

    if (!preg_match('/^\d{10,11}$/', $digits)) {
        redirect_profile_with_flash('Telefone inválido. Informe DDD + número.', 'warn');
    }

    $ddd = substr($digits, 0, 2);
    $numero = substr($digits, 2);

    if (strlen($numero) === 8) {
        return sprintf('(%s) %s-%s', $ddd, substr($numero, 0, 4), substr($numero, 4));
    }

    return sprintf('(%s) %s-%s', $ddd, substr($numero, 0, 5), substr($numero, 5));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /meus_dados.php');
    exit;
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
if ($usuarioId <= 0) {
    header('Location: /index.php');
    exit;
}

$supportsBirthDate = isset($pdo) && $pdo instanceof PDO ? usuario_supports_birth_date($pdo) : false;

$nomeRaw = (string)($_POST['nome'] ?? '');
$sobrenomeRaw = (string)($_POST['sobrenome'] ?? '');
$dataNascimentoRaw = trim((string)($_POST['data_nascimento'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$telefoneRaw = trim((string)($_POST['telefone'] ?? ''));
$cidade = trim((string)($_POST['cidade'] ?? ''));
$estado = strtoupper(trim((string)($_POST['estado'] ?? '')));
$senha = (string)($_POST['senha'] ?? '');
$confirmarSenha = (string)($_POST['confirmar_senha'] ?? '');

$nome = normalize_nome_update($nomeRaw);
$sobrenome = normalize_nome_update($sobrenomeRaw);
$nomeCompleto = trim($nome . ' ' . $sobrenome);
$nomeCompleto = preg_replace('/\s+/', ' ', $nomeCompleto) ?? $nomeCompleto;
$nomeCompletoInformado = trim($nomeRaw . ' ' . $sobrenomeRaw);
$nomeCompletoInformado = preg_replace('/\s+/', ' ', $nomeCompletoInformado) ?? $nomeCompletoInformado;

if ($nome === '' || $sobrenome === '' || $email === '' || $telefoneRaw === '' || $cidade === '' || $estado === '') {
    redirect_profile_with_flash('Preencha todos os campos obrigatórios.', 'warn');
}

if ($supportsBirthDate && $dataNascimentoRaw === '') {
    redirect_profile_with_flash('Preencha todos os campos obrigatórios.', 'warn');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_profile_with_flash('Email inválido.', 'warn');
}

if (strlen($estado) !== 2) {
    redirect_profile_with_flash('UF inválida. Use 2 letras.', 'warn');
}

$passwordChanged = ($senha !== '' || $confirmarSenha !== '');
if ($passwordChanged && $senha !== $confirmarSenha) {
    redirect_profile_with_flash('As senhas não coincidem.', 'warn');
}

$dataNascimento = $supportsBirthDate ? normalize_birth_date_update($dataNascimentoRaw) : '';
$telefone = normalize_phone_update($telefoneRaw);
$email = mb_strtolower($email, 'UTF-8');
$senhaHash = $passwordChanged ? password_hash($senha, PASSWORD_DEFAULT) : null;

if ($passwordChanged && !is_string($senhaHash)) {
    redirect_profile_with_flash('Não foi possível atualizar a senha agora.', 'error');
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Conexão indisponível.');
    }

    $pdo->beginTransaction();

    $userStmt = $pdo->prepare('SELECT id FROM usuarios WHERE id = ? LIMIT 1 FOR UPDATE');
    $userStmt->execute([$usuarioId]);
    if (!$userStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();
        session_unset();
        session_destroy();
        header('Location: /index.php');
        exit;
    }

    $duplicateStmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ? LIMIT 1');
    $duplicateStmt->execute([$email, $usuarioId]);

    if ($duplicateStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();
        redirect_profile_with_flash('Já existe outra conta com esse email.', 'warn');
    }

    if ($passwordChanged) {
        if ($supportsBirthDate) {
            $updateSql = '
                UPDATE usuarios
                SET nome = ?, data_nascimento = ?, email = ?, telefone = ?, cidade = ?, estado = ?, senha_hash = ?
                WHERE id = ?
                LIMIT 1
            ';
            $updateParams = [$nomeCompleto, $dataNascimento, $email, $telefone, $cidade, $estado, $senhaHash, $usuarioId];
        } else {
            $updateSql = '
                UPDATE usuarios
                SET nome = ?, email = ?, telefone = ?, cidade = ?, estado = ?, senha_hash = ?
                WHERE id = ?
                LIMIT 1
            ';
            $updateParams = [$nomeCompleto, $email, $telefone, $cidade, $estado, $senhaHash, $usuarioId];
        }
    } else {
        if ($supportsBirthDate) {
            $updateSql = '
                UPDATE usuarios
                SET nome = ?, data_nascimento = ?, email = ?, telefone = ?, cidade = ?, estado = ?
                WHERE id = ?
                LIMIT 1
            ';
            $updateParams = [$nomeCompleto, $dataNascimento, $email, $telefone, $cidade, $estado, $usuarioId];
        } else {
            $updateSql = '
                UPDATE usuarios
                SET nome = ?, email = ?, telefone = ?, cidade = ?, estado = ?
                WHERE id = ?
                LIMIT 1
            ';
            $updateParams = [$nomeCompleto, $email, $telefone, $cidade, $estado, $usuarioId];
        }
    }

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute($updateParams);

    $pdo->commit();

    $_SESSION['usuario_nome'] = $nomeCompleto;
    $_SESSION['usuario_email'] = $email;

    $mailConfig = load_mail_config_for_register();
    if (!load_phpmailer_for_register()) {
        cadastro_mail_log('Falha ao carregar PHPMailer para envio após atualização cadastral.');
        redirect_profile_with_flash('Dados atualizados com sucesso.', 'ok');
    }

    $notified = notify_user_profile_update(
        $mailConfig,
        $usuarioId,
        $nomeCompletoInformado !== '' ? $nomeCompletoInformado : $nomeCompleto,
        $email,
        $telefone,
        $cidade,
        $estado,
        $dataNascimento,
        $passwordChanged
    );

    if (!$notified) {
        error_log('[atualizar_cadastro] Falha ao notificar atualização cadastral por email.');
    }

    redirect_profile_with_flash('Dados atualizados com sucesso.', 'ok');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[atualizar_cadastro] ' . $e->getMessage());
    redirect_profile_with_flash('Não foi possível atualizar seus dados agora.', 'error');
}