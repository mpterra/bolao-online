<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$connectionCandidates = [
    __DIR__ . '/../php/conexao.php',
    '/home2/mauri075/php/conexao.php',
];

$connectionLoaded = false;
foreach ($connectionCandidates as $connectionPath) {
    if (!is_file($connectionPath)) {
        continue;
    }

    require_once $connectionPath;
    $connectionLoaded = true;
    break;
}

if (!$connectionLoaded) {
    http_response_code(500);
    exit('Erro ao carregar a conexão com o banco.');
}

require_once __DIR__ . '/../php/usuario_schema.php';
require_once __DIR__ . '/partials/app_header.php';

function require_login_for_profile(): void {
    if (empty($_SESSION['usuario_id'])) {
        header('Location: /index.php');
        exit;
    }
}

function h(?string $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function session_int(string $key, int $default = 0): int {
    $value = $_SESSION[$key] ?? null;
    if ($value === null) {
        return $default;
    }
    if (is_int($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    return $default;
}

function session_str(string $key, string $default = ''): string {
    $value = $_SESSION[$key] ?? null;
    if ($value === null) {
        return $default;
    }
    return (string)$value;
}

function split_full_name(string $fullName): array {
    $clean = trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName);
    if ($clean === '') {
        return ['', ''];
    }

    $parts = preg_split('/\s+/', $clean) ?: [];
    if (count($parts) <= 1) {
        return [$clean, ''];
    }

    $firstName = (string)array_shift($parts);
    return [$firstName, implode(' ', $parts)];
}

function format_birth_date_for_input(?string $value): string {
    $clean = trim((string)($value ?? ''));
    if ($clean === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $clean);
    if ($date instanceof DateTimeImmutable) {
        return $date->format('d/m/Y');
    }

    return $clean;
}

require_login_for_profile();

$usuarioId = session_int('usuario_id', 0);
$usuarioNome = session_str('usuario_nome', 'Apostador');
$tipoUsuario = strtoupper(session_str('tipo_usuario', ''));
$isAdmin = ($tipoUsuario === 'ADMIN');

$flash = null;
if (!empty($_SESSION['flash_profile']) && is_array($_SESSION['flash_profile'])) {
    $flash = $_SESSION['flash_profile'];
    unset($_SESSION['flash_profile']);
}

$flashType = is_array($flash) ? (string)($flash['type'] ?? '') : '';
$flashMsg = is_array($flash) ? (string)($flash['msg'] ?? '') : '';
$allowedFlashTypes = ['error', 'warn', 'info', 'ok'];
if (!in_array($flashType, $allowedFlashTypes, true)) {
    $flashType = '';
}

$birthDateSchemaReady = false;

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Conexão indisponível.');
    }

    $birthDateSchemaReady = usuario_ensure_birth_date($pdo);
    $selectFields = 'id, nome, email, telefone, cidade, estado, ' . usuario_birth_date_select_sql($pdo);

    $stmt = $pdo->prepare('
        SELECT ' . $selectFields . '
        FROM usuarios
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$usuarioId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        session_unset();
        session_destroy();
        header('Location: /index.php');
        exit;
    }
} catch (Throwable $e) {
    error_log('[meus_dados] ' . $e->getMessage());
    http_response_code(500);
    exit('Erro ao carregar seus dados cadastrais.');
}

[$nome, $sobrenome] = split_full_name((string)($usuario['nome'] ?? ''));
$dataNascimento = format_birth_date_for_input((string)($usuario['data_nascimento'] ?? ''));
$email = (string)($usuario['email'] ?? '');
$telefone = (string)($usuario['telefone'] ?? '');
$cidade = (string)($usuario['cidade'] ?? '');
$estadoAtual = strtoupper((string)($usuario['estado'] ?? ''));
$ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Meus dados - Bolão do Thiago</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <link rel="stylesheet" href="/css/base.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/base.css'); ?>">
    <link rel="stylesheet" href="/css/cadastro.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/cadastro.css'); ?>">
    <link rel="stylesheet" href="/css/meus_dados.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/meus_dados.css'); ?>">
</head>
<body data-reg-success="0">

<div class="app-wrap">
    <?php render_app_header($usuarioNome, $isAdmin, 'meus_dados', 'Atualize seu cadastro', '/app.php?action=logout'); ?>

    <section class="app-shell profile-shell">
        <div class="profile-card">
            <div class="content-head profile-head">
                <div class="profile-head-copy">
                    <div class="profile-eyebrow">Área do participante</div>
                    <div class="content-h1">Meus dados</div>
                    <p class="profile-lead">Atualize suas informações de cadastro. Sempre que houver alteração, os administradores do bolão recebem um aviso por email.</p>
                </div>

                <div class="profile-chip-wrap">
                    <span class="profile-chip-label">Email principal da conta</span>
                    <div class="profile-chip" title="Email atual do cadastro"><?php echo h($email); ?></div>
                </div>
            </div>

            <?php if ($flashType !== '' && $flashMsg !== ''): ?>
                <div class="profile-alert profile-alert--<?php echo h($flashType); ?>" role="status" aria-live="polite">
                    <?php echo h($flashMsg); ?>
                </div>
            <?php endif; ?>

            <?php if (!$birthDateSchemaReady): ?>
                <div class="profile-alert profile-alert--warn" role="status" aria-live="polite">
                    O campo de data de nascimento foi liberado no código. Se o salvamento falhar, aplique a migration de banco incluída no projeto.
                </div>
            <?php endif; ?>

            <form method="POST" action="/php/atualizar_cadastro.php" class="login-form profile-form" autocomplete="on" data-password-optional="1">
                <section class="profile-section" aria-labelledby="profileSectionCadastro">
                    <div class="profile-section-head">
                        <div>
                            <h2 class="profile-section-title" id="profileSectionCadastro">Dados cadastrais</h2>
                            <p class="profile-section-text">Revise seus dados principais e mantenha o cadastro atualizado para contato e identificação no bolão.</p>
                        </div>
                    </div>

                    <div class="profile-grid">
                        <div class="input-group">
                            <input type="text" name="nome" required autocomplete="given-name" value="<?php echo h($nome); ?>">
                            <label>Nome</label>
                        </div>

                        <div class="input-group">
                            <input type="text" name="sobrenome" required autocomplete="family-name" value="<?php echo h($sobrenome); ?>">
                            <label>Sobrenome</label>
                        </div>

                        <div class="input-group">
                            <input
                                type="text"
                                id="data_nascimento"
                                name="data_nascimento"
                                class="has-inline-action"
                                required
                                inputmode="numeric"
                                autocomplete="bday"
                                maxlength="10"
                                pattern="\d{2}/\d{2}/\d{4}"
                                value="<?php echo h($dataNascimento); ?>">
                            <label>Data de nascimento</label>
                            <button type="button" class="password-toggle date-picker-toggle" data-open-date-picker="data_nascimento_picker" aria-label="Abrir calendário para data de nascimento">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M7 2v3M17 2v3M4 9h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    <rect x="4" y="5" width="16" height="15" rx="3" stroke="currentColor" stroke-width="2"/>
                                    <path d="M8 13h3M8 17h3M13 13h3M13 17h3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                            </button>
                            <input type="date" id="data_nascimento_picker" class="date-picker-native" tabindex="-1" aria-hidden="true" max="<?php echo date('Y-m-d'); ?>">
                            <small class="input-hint">Use o formato DD/MM/AAAA.</small>
                        </div>

                        <div class="input-group">
                            <input type="email" name="email" required autocomplete="email" value="<?php echo h($email); ?>">
                            <label>Email</label>
                        </div>

                        <div class="input-group">
                            <input type="text" name="telefone" required autocomplete="tel" inputmode="tel" maxlength="15" value="<?php echo h($telefone); ?>">
                            <label>Telefone</label>
                        </div>

                        <div class="input-group">
                            <select name="estado" required>
                                <option value="" disabled <?php echo $estadoAtual === '' ? 'selected' : ''; ?> hidden></option>
                                <?php foreach ($ufs as $uf): ?>
                                    <option value="<?php echo h($uf); ?>" <?php echo $estadoAtual === $uf ? 'selected' : ''; ?>><?php echo h($uf); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Estado (UF)</label>
                        </div>

                        <div class="input-group profile-field-full">
                            <select id="cidade" name="cidade" required data-selected-city="<?php echo h($cidade); ?>">
                                <?php if ($cidade !== ''): ?>
                                    <option value="<?php echo h($cidade); ?>" selected><?php echo h($cidade); ?></option>
                                <?php else: ?>
                                    <option value="" selected hidden></option>
                                <?php endif; ?>
                            </select>
                            <label>Cidade</label>
                            <small class="input-hint" id="cityHint"><?php echo $estadoAtual !== '' ? 'Carregando cidades...' : 'Selecione o estado primeiro.'; ?></small>
                        </div>
                    </div>
                </section>

                <section class="profile-section profile-section--security" aria-labelledby="profileSectionSeguranca">
                    <div class="profile-section-head">
                        <div>
                            <h2 class="profile-section-title" id="profileSectionSeguranca">Segurança</h2>
                            <p class="profile-section-text">Troque sua senha apenas quando precisar. Se deixar em branco, a senha atual continua valendo.</p>
                        </div>
                    </div>

                    <div class="profile-grid profile-grid--security">
                        <div class="input-group">
                            <input type="password" id="senha" name="senha" autocomplete="new-password">
                            <label>Nova senha</label>
                            <button type="button" class="password-toggle" data-toggle-password="senha" aria-label="Mostrar nova senha" aria-pressed="false">
                                <svg class="icon-eye" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M2 12s3.8-6 10-6 10 6 10 6-3.8 6-10 6-10-6-10-6Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                                </svg>
                                <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M3 3l18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    <path d="M10.6 6.2a10.7 10.7 0 0 1 1.4-.2c6.2 0 10 6 10 6a18 18 0 0 1-3.4 4.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M6.8 8.8C4.2 10.7 2 12 2 12s3.8 6 10 6c1.4 0 2.6-.3 3.8-.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M9.9 9.9A3 3 0 0 0 14 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                            <small class="input-hint">Deixe em branco para manter a senha atual.</small>
                        </div>

                        <div class="input-group">
                            <input type="password" id="confirmar_senha" name="confirmar_senha" autocomplete="new-password">
                            <label>Confirmar nova senha</label>
                            <button type="button" class="password-toggle" data-toggle-password="confirmar_senha" aria-label="Mostrar confirmação da nova senha" aria-pressed="false">
                                <svg class="icon-eye" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M2 12s3.8-6 10-6 10 6 10 6-3.8 6-10 6-10-6-10-6Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                                </svg>
                                <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M3 3l18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    <path d="M10.6 6.2a10.7 10.7 0 0 1 1.4-.2c6.2 0 10 6 10 6a18 18 0 0 1-3.4 4.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M6.8 8.8C4.2 10.7 2 12 2 12s3.8 6 10 6c1.4 0 2.6-.3 3.8-.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M9.9 9.9A3 3 0 0 0 14 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </section>

                <div class="profile-actions">
                    <button type="submit" class="btn-login">Salvar alterações</button>
                    <div class="profile-note-card">
                        <div class="profile-note-title">Notificação automática</div>
                        <p class="profile-note">As alterações são comunicadas automaticamente aos administradores do bolão.</p>
                    </div>
                </div>
            </form>
        </div>
    </section>
</div>

<script src="/js/cadastro.js?v=<?php echo (string)@filemtime(__DIR__ . '/js/cadastro.js'); ?>"></script>
</body>
</html>