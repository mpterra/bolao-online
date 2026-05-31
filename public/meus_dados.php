<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/php/security.php';
app_start_session();
app_send_security_headers();

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
    $hasPaisColumn = usuario_column_exists($pdo, 'pais');
    $selectFields = 'id, nome, email, telefone, cidade, estado, ' . usuario_birth_date_select_sql($pdo);
    if ($hasPaisColumn) {
        $selectFields .= ', pais';
    }

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
$pais = isset($usuario['pais']) ? (string)$usuario['pais'] : 'Brasil';
$isBrazil = ($pais === 'Brasil');
$estadoAtual = $isBrazil
    ? strtoupper((string)($usuario['estado'] ?? ''))
    : (string)($usuario['estado'] ?? '');
$ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
// Visibilidade server-side para evitar flash antes do JS rodar
$ufSelectStyle    = $isBrazil ? '' : ' style="display:none"';
$ufTextoStyle     = $isBrazil ? ' style="display:none"' : '';
$cidadeSelStyle   = $isBrazil ? '' : ' style="display:none"';
$cidadeTextoStyle = $isBrazil ? ' style="display:none"' : '';
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
    <link rel="stylesheet" href="/css/visual-identity.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/visual-identity.css'); ?>">
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
                <?php echo app_csrf_field(); ?>
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
                            <select id="pais" name="pais" required>
                                <option value="" disabled hidden></option>
                                <option value="Brasil"<?php echo $pais === 'Brasil' ? ' selected' : ''; ?>>Brasil</option>
                                <option value="" disabled>──────────────</option>
                                <option value="Afeganistão"<?php echo $pais === 'Afeganistão' ? ' selected' : ''; ?>>Afeganistão</option>
                                <option value="África do Sul"<?php echo $pais === 'África do Sul' ? ' selected' : ''; ?>>África do Sul</option>
                                <option value="Albânia"<?php echo $pais === 'Albânia' ? ' selected' : ''; ?>>Albânia</option>
                                <option value="Alemanha"<?php echo $pais === 'Alemanha' ? ' selected' : ''; ?>>Alemanha</option>
                                <option value="Andorra"<?php echo $pais === 'Andorra' ? ' selected' : ''; ?>>Andorra</option>
                                <option value="Angola"<?php echo $pais === 'Angola' ? ' selected' : ''; ?>>Angola</option>
                                <option value="Antígua e Barbuda"<?php echo $pais === 'Antígua e Barbuda' ? ' selected' : ''; ?>>Antígua e Barbuda</option>
                                <option value="Arábia Saudita"<?php echo $pais === 'Arábia Saudita' ? ' selected' : ''; ?>>Arábia Saudita</option>
                                <option value="Argélia"<?php echo $pais === 'Argélia' ? ' selected' : ''; ?>>Argélia</option>
                                <option value="Argentina"<?php echo $pais === 'Argentina' ? ' selected' : ''; ?>>Argentina</option>
                                <option value="Armênia"<?php echo $pais === 'Armênia' ? ' selected' : ''; ?>>Armênia</option>
                                <option value="Austrália"<?php echo $pais === 'Austrália' ? ' selected' : ''; ?>>Austrália</option>
                                <option value="Áustria"<?php echo $pais === 'Áustria' ? ' selected' : ''; ?>>Áustria</option>
                                <option value="Azerbaijão"<?php echo $pais === 'Azerbaijão' ? ' selected' : ''; ?>>Azerbaijão</option>
                                <option value="Bahamas"<?php echo $pais === 'Bahamas' ? ' selected' : ''; ?>>Bahamas</option>
                                <option value="Bangladesh"<?php echo $pais === 'Bangladesh' ? ' selected' : ''; ?>>Bangladesh</option>
                                <option value="Barbados"<?php echo $pais === 'Barbados' ? ' selected' : ''; ?>>Barbados</option>
                                <option value="Barein"<?php echo $pais === 'Barein' ? ' selected' : ''; ?>>Barein</option>
                                <option value="Bélgica"<?php echo $pais === 'Bélgica' ? ' selected' : ''; ?>>Bélgica</option>
                                <option value="Belize"<?php echo $pais === 'Belize' ? ' selected' : ''; ?>>Belize</option>
                                <option value="Benin"<?php echo $pais === 'Benin' ? ' selected' : ''; ?>>Benin</option>
                                <option value="Bielorrússia"<?php echo $pais === 'Bielorrússia' ? ' selected' : ''; ?>>Bielorrússia</option>
                                <option value="Birmânia (Myanmar)"<?php echo $pais === 'Birmânia (Myanmar)' ? ' selected' : ''; ?>>Birmânia (Myanmar)</option>
                                <option value="Bolívia"<?php echo $pais === 'Bolívia' ? ' selected' : ''; ?>>Bolívia</option>
                                <option value="Bósnia e Herzegovina"<?php echo $pais === 'Bósnia e Herzegovina' ? ' selected' : ''; ?>>Bósnia e Herzegovina</option>
                                <option value="Botsuana"<?php echo $pais === 'Botsuana' ? ' selected' : ''; ?>>Botsuana</option>
                                <option value="Brunei"<?php echo $pais === 'Brunei' ? ' selected' : ''; ?>>Brunei</option>
                                <option value="Bulgária"<?php echo $pais === 'Bulgária' ? ' selected' : ''; ?>>Bulgária</option>
                                <option value="Burquina Fasso"<?php echo $pais === 'Burquina Fasso' ? ' selected' : ''; ?>>Burquina Fasso</option>
                                <option value="Burundi"<?php echo $pais === 'Burundi' ? ' selected' : ''; ?>>Burundi</option>
                                <option value="Butão"<?php echo $pais === 'Butão' ? ' selected' : ''; ?>>Butão</option>
                                <option value="Cabo Verde"<?php echo $pais === 'Cabo Verde' ? ' selected' : ''; ?>>Cabo Verde</option>
                                <option value="Camarões"<?php echo $pais === 'Camarões' ? ' selected' : ''; ?>>Camarões</option>
                                <option value="Camboja"<?php echo $pais === 'Camboja' ? ' selected' : ''; ?>>Camboja</option>
                                <option value="Canadá"<?php echo $pais === 'Canadá' ? ' selected' : ''; ?>>Canadá</option>
                                <option value="Catar"<?php echo $pais === 'Catar' ? ' selected' : ''; ?>>Catar</option>
                                <option value="Cazaquistão"<?php echo $pais === 'Cazaquistão' ? ' selected' : ''; ?>>Cazaquistão</option>
                                <option value="Chade"<?php echo $pais === 'Chade' ? ' selected' : ''; ?>>Chade</option>
                                <option value="Chile"<?php echo $pais === 'Chile' ? ' selected' : ''; ?>>Chile</option>
                                <option value="China"<?php echo $pais === 'China' ? ' selected' : ''; ?>>China</option>
                                <option value="Chipre"<?php echo $pais === 'Chipre' ? ' selected' : ''; ?>>Chipre</option>
                                <option value="Colômbia"<?php echo $pais === 'Colômbia' ? ' selected' : ''; ?>>Colômbia</option>
                                <option value="Comores"<?php echo $pais === 'Comores' ? ' selected' : ''; ?>>Comores</option>
                                <option value="Congo"<?php echo $pais === 'Congo' ? ' selected' : ''; ?>>Congo</option>
                                <option value="Coreia do Norte"<?php echo $pais === 'Coreia do Norte' ? ' selected' : ''; ?>>Coreia do Norte</option>
                                <option value="Coreia do Sul"<?php echo $pais === 'Coreia do Sul' ? ' selected' : ''; ?>>Coreia do Sul</option>
                                <option value="Costa do Marfim"<?php echo $pais === 'Costa do Marfim' ? ' selected' : ''; ?>>Costa do Marfim</option>
                                <option value="Costa Rica"<?php echo $pais === 'Costa Rica' ? ' selected' : ''; ?>>Costa Rica</option>
                                <option value="Croácia"<?php echo $pais === 'Croácia' ? ' selected' : ''; ?>>Croácia</option>
                                <option value="Cuba"<?php echo $pais === 'Cuba' ? ' selected' : ''; ?>>Cuba</option>
                                <option value="Dinamarca"<?php echo $pais === 'Dinamarca' ? ' selected' : ''; ?>>Dinamarca</option>
                                <option value="Djibuti"<?php echo $pais === 'Djibuti' ? ' selected' : ''; ?>>Djibuti</option>
                                <option value="Dominica"<?php echo $pais === 'Dominica' ? ' selected' : ''; ?>>Dominica</option>
                                <option value="Egito"<?php echo $pais === 'Egito' ? ' selected' : ''; ?>>Egito</option>
                                <option value="El Salvador"<?php echo $pais === 'El Salvador' ? ' selected' : ''; ?>>El Salvador</option>
                                <option value="Emirados Árabes Unidos"<?php echo $pais === 'Emirados Árabes Unidos' ? ' selected' : ''; ?>>Emirados Árabes Unidos</option>
                                <option value="Equador"<?php echo $pais === 'Equador' ? ' selected' : ''; ?>>Equador</option>
                                <option value="Eritreia"<?php echo $pais === 'Eritreia' ? ' selected' : ''; ?>>Eritreia</option>
                                <option value="Eslováquia"<?php echo $pais === 'Eslováquia' ? ' selected' : ''; ?>>Eslováquia</option>
                                <option value="Eslovênia"<?php echo $pais === 'Eslovênia' ? ' selected' : ''; ?>>Eslovênia</option>
                                <option value="Espanha"<?php echo $pais === 'Espanha' ? ' selected' : ''; ?>>Espanha</option>
                                <option value="Eswatini"<?php echo $pais === 'Eswatini' ? ' selected' : ''; ?>>Eswatini</option>
                                <option value="Estados Unidos"<?php echo $pais === 'Estados Unidos' ? ' selected' : ''; ?>>Estados Unidos</option>
                                <option value="Estônia"<?php echo $pais === 'Estônia' ? ' selected' : ''; ?>>Estônia</option>
                                <option value="Etiópia"<?php echo $pais === 'Etiópia' ? ' selected' : ''; ?>>Etiópia</option>
                                <option value="Fiji"<?php echo $pais === 'Fiji' ? ' selected' : ''; ?>>Fiji</option>
                                <option value="Filipinas"<?php echo $pais === 'Filipinas' ? ' selected' : ''; ?>>Filipinas</option>
                                <option value="Finlândia"<?php echo $pais === 'Finlândia' ? ' selected' : ''; ?>>Finlândia</option>
                                <option value="França"<?php echo $pais === 'França' ? ' selected' : ''; ?>>França</option>
                                <option value="Gabão"<?php echo $pais === 'Gabão' ? ' selected' : ''; ?>>Gabão</option>
                                <option value="Gâmbia"<?php echo $pais === 'Gâmbia' ? ' selected' : ''; ?>>Gâmbia</option>
                                <option value="Gana"<?php echo $pais === 'Gana' ? ' selected' : ''; ?>>Gana</option>
                                <option value="Geórgia"<?php echo $pais === 'Geórgia' ? ' selected' : ''; ?>>Geórgia</option>
                                <option value="Granada"<?php echo $pais === 'Granada' ? ' selected' : ''; ?>>Granada</option>
                                <option value="Grécia"<?php echo $pais === 'Grécia' ? ' selected' : ''; ?>>Grécia</option>
                                <option value="Guatemala"<?php echo $pais === 'Guatemala' ? ' selected' : ''; ?>>Guatemala</option>
                                <option value="Guiana"<?php echo $pais === 'Guiana' ? ' selected' : ''; ?>>Guiana</option>
                                <option value="Guiné"<?php echo $pais === 'Guiné' ? ' selected' : ''; ?>>Guiné</option>
                                <option value="Guiné-Bissau"<?php echo $pais === 'Guiné-Bissau' ? ' selected' : ''; ?>>Guiné-Bissau</option>
                                <option value="Guiné Equatorial"<?php echo $pais === 'Guiné Equatorial' ? ' selected' : ''; ?>>Guiné Equatorial</option>
                                <option value="Haiti"<?php echo $pais === 'Haiti' ? ' selected' : ''; ?>>Haiti</option>
                                <option value="Honduras"<?php echo $pais === 'Honduras' ? ' selected' : ''; ?>>Honduras</option>
                                <option value="Hungria"<?php echo $pais === 'Hungria' ? ' selected' : ''; ?>>Hungria</option>
                                <option value="Iêmen"<?php echo $pais === 'Iêmen' ? ' selected' : ''; ?>>Iêmen</option>
                                <option value="Índia"<?php echo $pais === 'Índia' ? ' selected' : ''; ?>>Índia</option>
                                <option value="Indonésia"<?php echo $pais === 'Indonésia' ? ' selected' : ''; ?>>Indonésia</option>
                                <option value="Irã"<?php echo $pais === 'Irã' ? ' selected' : ''; ?>>Irã</option>
                                <option value="Iraque"<?php echo $pais === 'Iraque' ? ' selected' : ''; ?>>Iraque</option>
                                <option value="Irlanda"<?php echo $pais === 'Irlanda' ? ' selected' : ''; ?>>Irlanda</option>
                                <option value="Islândia"<?php echo $pais === 'Islândia' ? ' selected' : ''; ?>>Islândia</option>
                                <option value="Israel"<?php echo $pais === 'Israel' ? ' selected' : ''; ?>>Israel</option>
                                <option value="Itália"<?php echo $pais === 'Itália' ? ' selected' : ''; ?>>Itália</option>
                                <option value="Jamaica"<?php echo $pais === 'Jamaica' ? ' selected' : ''; ?>>Jamaica</option>
                                <option value="Japão"<?php echo $pais === 'Japão' ? ' selected' : ''; ?>>Japão</option>
                                <option value="Jordânia"<?php echo $pais === 'Jordânia' ? ' selected' : ''; ?>>Jordânia</option>
                                <option value="Kiribati"<?php echo $pais === 'Kiribati' ? ' selected' : ''; ?>>Kiribati</option>
                                <option value="Kuwait"<?php echo $pais === 'Kuwait' ? ' selected' : ''; ?>>Kuwait</option>
                                <option value="Laos"<?php echo $pais === 'Laos' ? ' selected' : ''; ?>>Laos</option>
                                <option value="Lesoto"<?php echo $pais === 'Lesoto' ? ' selected' : ''; ?>>Lesoto</option>
                                <option value="Letônia"<?php echo $pais === 'Letônia' ? ' selected' : ''; ?>>Letônia</option>
                                <option value="Líbano"<?php echo $pais === 'Líbano' ? ' selected' : ''; ?>>Líbano</option>
                                <option value="Libéria"<?php echo $pais === 'Libéria' ? ' selected' : ''; ?>>Libéria</option>
                                <option value="Líbia"<?php echo $pais === 'Líbia' ? ' selected' : ''; ?>>Líbia</option>
                                <option value="Liechtenstein"<?php echo $pais === 'Liechtenstein' ? ' selected' : ''; ?>>Liechtenstein</option>
                                <option value="Lituânia"<?php echo $pais === 'Lituânia' ? ' selected' : ''; ?>>Lituânia</option>
                                <option value="Luxemburgo"<?php echo $pais === 'Luxemburgo' ? ' selected' : ''; ?>>Luxemburgo</option>
                                <option value="Macedônia do Norte"<?php echo $pais === 'Macedônia do Norte' ? ' selected' : ''; ?>>Macedônia do Norte</option>
                                <option value="Madagascar"<?php echo $pais === 'Madagascar' ? ' selected' : ''; ?>>Madagascar</option>
                                <option value="Malásia"<?php echo $pais === 'Malásia' ? ' selected' : ''; ?>>Malásia</option>
                                <option value="Malaui"<?php echo $pais === 'Malaui' ? ' selected' : ''; ?>>Malaui</option>
                                <option value="Maldivas"<?php echo $pais === 'Maldivas' ? ' selected' : ''; ?>>Maldivas</option>
                                <option value="Mali"<?php echo $pais === 'Mali' ? ' selected' : ''; ?>>Mali</option>
                                <option value="Malta"<?php echo $pais === 'Malta' ? ' selected' : ''; ?>>Malta</option>
                                <option value="Marrocos"<?php echo $pais === 'Marrocos' ? ' selected' : ''; ?>>Marrocos</option>
                                <option value="Ilhas Marshall"<?php echo $pais === 'Ilhas Marshall' ? ' selected' : ''; ?>>Ilhas Marshall</option>
                                <option value="Mauritânia"<?php echo $pais === 'Mauritânia' ? ' selected' : ''; ?>>Mauritânia</option>
                                <option value="Maurício"<?php echo $pais === 'Maurício' ? ' selected' : ''; ?>>Maurício</option>
                                <option value="México"<?php echo $pais === 'México' ? ' selected' : ''; ?>>México</option>
                                <option value="Micronésia"<?php echo $pais === 'Micronésia' ? ' selected' : ''; ?>>Micronésia</option>
                                <option value="Moçambique"<?php echo $pais === 'Moçambique' ? ' selected' : ''; ?>>Moçambique</option>
                                <option value="Moldávia"<?php echo $pais === 'Moldávia' ? ' selected' : ''; ?>>Moldávia</option>
                                <option value="Mônaco"<?php echo $pais === 'Mônaco' ? ' selected' : ''; ?>>Mônaco</option>
                                <option value="Mongólia"<?php echo $pais === 'Mongólia' ? ' selected' : ''; ?>>Mongólia</option>
                                <option value="Montenegro"<?php echo $pais === 'Montenegro' ? ' selected' : ''; ?>>Montenegro</option>
                                <option value="Namíbia"<?php echo $pais === 'Namíbia' ? ' selected' : ''; ?>>Namíbia</option>
                                <option value="Nauru"<?php echo $pais === 'Nauru' ? ' selected' : ''; ?>>Nauru</option>
                                <option value="Nepal"<?php echo $pais === 'Nepal' ? ' selected' : ''; ?>>Nepal</option>
                                <option value="Nicarágua"<?php echo $pais === 'Nicarágua' ? ' selected' : ''; ?>>Nicarágua</option>
                                <option value="Níger"<?php echo $pais === 'Níger' ? ' selected' : ''; ?>>Níger</option>
                                <option value="Nigéria"<?php echo $pais === 'Nigéria' ? ' selected' : ''; ?>>Nigéria</option>
                                <option value="Noruega"<?php echo $pais === 'Noruega' ? ' selected' : ''; ?>>Noruega</option>
                                <option value="Nova Zelândia"<?php echo $pais === 'Nova Zelândia' ? ' selected' : ''; ?>>Nova Zelândia</option>
                                <option value="Omã"<?php echo $pais === 'Omã' ? ' selected' : ''; ?>>Omã</option>
                                <option value="Países Baixos"<?php echo $pais === 'Países Baixos' ? ' selected' : ''; ?>>Países Baixos</option>
                                <option value="Palau"<?php echo $pais === 'Palau' ? ' selected' : ''; ?>>Palau</option>
                                <option value="Palestina"<?php echo $pais === 'Palestina' ? ' selected' : ''; ?>>Palestina</option>
                                <option value="Panamá"<?php echo $pais === 'Panamá' ? ' selected' : ''; ?>>Panamá</option>
                                <option value="Papua-Nova Guiné"<?php echo $pais === 'Papua-Nova Guiné' ? ' selected' : ''; ?>>Papua-Nova Guiné</option>
                                <option value="Paquistão"<?php echo $pais === 'Paquistão' ? ' selected' : ''; ?>>Paquistão</option>
                                <option value="Paraguai"<?php echo $pais === 'Paraguai' ? ' selected' : ''; ?>>Paraguai</option>
                                <option value="Peru"<?php echo $pais === 'Peru' ? ' selected' : ''; ?>>Peru</option>
                                <option value="Polônia"<?php echo $pais === 'Polônia' ? ' selected' : ''; ?>>Polônia</option>
                                <option value="Portugal"<?php echo $pais === 'Portugal' ? ' selected' : ''; ?>>Portugal</option>
                                <option value="Quênia"<?php echo $pais === 'Quênia' ? ' selected' : ''; ?>>Quênia</option>
                                <option value="Quiribati"<?php echo $pais === 'Quiribati' ? ' selected' : ''; ?>>Quiribati</option>
                                <option value="Quirguistão"<?php echo $pais === 'Quirguistão' ? ' selected' : ''; ?>>Quirguistão</option>
                                <option value="Reino Unido"<?php echo $pais === 'Reino Unido' ? ' selected' : ''; ?>>Reino Unido</option>
                                <option value="República Centro-Africana"<?php echo $pais === 'República Centro-Africana' ? ' selected' : ''; ?>>República Centro-Africana</option>
                                <option value="República Democrática do Congo"<?php echo $pais === 'República Democrática do Congo' ? ' selected' : ''; ?>>República Democrática do Congo</option>
                                <option value="República Dominicana"<?php echo $pais === 'República Dominicana' ? ' selected' : ''; ?>>República Dominicana</option>
                                <option value="República Tcheca"<?php echo $pais === 'República Tcheca' ? ' selected' : ''; ?>>República Tcheca</option>
                                <option value="Romênia"<?php echo $pais === 'Romênia' ? ' selected' : ''; ?>>Romênia</option>
                                <option value="Ruanda"<?php echo $pais === 'Ruanda' ? ' selected' : ''; ?>>Ruanda</option>
                                <option value="Rússia"<?php echo $pais === 'Rússia' ? ' selected' : ''; ?>>Rússia</option>
                                <option value="Samoa"<?php echo $pais === 'Samoa' ? ' selected' : ''; ?>>Samoa</option>
                                <option value="San Marino"<?php echo $pais === 'San Marino' ? ' selected' : ''; ?>>San Marino</option>
                                <option value="Santa Lúcia"<?php echo $pais === 'Santa Lúcia' ? ' selected' : ''; ?>>Santa Lúcia</option>
                                <option value="São Cristóvão e Nevis"<?php echo $pais === 'São Cristóvão e Nevis' ? ' selected' : ''; ?>>São Cristóvão e Nevis</option>
                                <option value="São Tomé e Príncipe"<?php echo $pais === 'São Tomé e Príncipe' ? ' selected' : ''; ?>>São Tomé e Príncipe</option>
                                <option value="São Vicente e Granadinas"<?php echo $pais === 'São Vicente e Granadinas' ? ' selected' : ''; ?>>São Vicente e Granadinas</option>
                                <option value="Senegal"<?php echo $pais === 'Senegal' ? ' selected' : ''; ?>>Senegal</option>
                                <option value="Serra Leoa"<?php echo $pais === 'Serra Leoa' ? ' selected' : ''; ?>>Serra Leoa</option>
                                <option value="Sérvia"<?php echo $pais === 'Sérvia' ? ' selected' : ''; ?>>Sérvia</option>
                                <option value="Seychelles"<?php echo $pais === 'Seychelles' ? ' selected' : ''; ?>>Seychelles</option>
                                <option value="Singapura"<?php echo $pais === 'Singapura' ? ' selected' : ''; ?>>Singapura</option>
                                <option value="Síria"<?php echo $pais === 'Síria' ? ' selected' : ''; ?>>Síria</option>
                                <option value="Somália"<?php echo $pais === 'Somália' ? ' selected' : ''; ?>>Somália</option>
                                <option value="Sri Lanka"<?php echo $pais === 'Sri Lanka' ? ' selected' : ''; ?>>Sri Lanka</option>
                                <option value="Sudão"<?php echo $pais === 'Sudão' ? ' selected' : ''; ?>>Sudão</option>
                                <option value="Sudão do Sul"<?php echo $pais === 'Sudão do Sul' ? ' selected' : ''; ?>>Sudão do Sul</option>
                                <option value="Suécia"<?php echo $pais === 'Suécia' ? ' selected' : ''; ?>>Suécia</option>
                                <option value="Suíça"<?php echo $pais === 'Suíça' ? ' selected' : ''; ?>>Suíça</option>
                                <option value="Suriname"<?php echo $pais === 'Suriname' ? ' selected' : ''; ?>>Suriname</option>
                                <option value="Tailândia"<?php echo $pais === 'Tailândia' ? ' selected' : ''; ?>>Tailândia</option>
                                <option value="Taiwan"<?php echo $pais === 'Taiwan' ? ' selected' : ''; ?>>Taiwan</option>
                                <option value="Tajiquistão"<?php echo $pais === 'Tajiquistão' ? ' selected' : ''; ?>>Tajiquistão</option>
                                <option value="Tanzânia"<?php echo $pais === 'Tanzânia' ? ' selected' : ''; ?>>Tanzânia</option>
                                <option value="Timor-Leste"<?php echo $pais === 'Timor-Leste' ? ' selected' : ''; ?>>Timor-Leste</option>
                                <option value="Togo"<?php echo $pais === 'Togo' ? ' selected' : ''; ?>>Togo</option>
                                <option value="Tonga"<?php echo $pais === 'Tonga' ? ' selected' : ''; ?>>Tonga</option>
                                <option value="Trinidad e Tobago"<?php echo $pais === 'Trinidad e Tobago' ? ' selected' : ''; ?>>Trinidad e Tobago</option>
                                <option value="Tunísia"<?php echo $pais === 'Tunísia' ? ' selected' : ''; ?>>Tunísia</option>
                                <option value="Turcomenistão"<?php echo $pais === 'Turcomenistão' ? ' selected' : ''; ?>>Turcomenistão</option>
                                <option value="Turquia"<?php echo $pais === 'Turquia' ? ' selected' : ''; ?>>Turquia</option>
                                <option value="Tuvalu"<?php echo $pais === 'Tuvalu' ? ' selected' : ''; ?>>Tuvalu</option>
                                <option value="Ucrânia"<?php echo $pais === 'Ucrânia' ? ' selected' : ''; ?>>Ucrânia</option>
                                <option value="Uganda"<?php echo $pais === 'Uganda' ? ' selected' : ''; ?>>Uganda</option>
                                <option value="Uruguai"<?php echo $pais === 'Uruguai' ? ' selected' : ''; ?>>Uruguai</option>
                                <option value="Uzbequistão"<?php echo $pais === 'Uzbequistão' ? ' selected' : ''; ?>>Uzbequistão</option>
                                <option value="Vanuatu"<?php echo $pais === 'Vanuatu' ? ' selected' : ''; ?>>Vanuatu</option>
                                <option value="Vaticano"<?php echo $pais === 'Vaticano' ? ' selected' : ''; ?>>Vaticano</option>
                                <option value="Venezuela"<?php echo $pais === 'Venezuela' ? ' selected' : ''; ?>>Venezuela</option>
                                <option value="Vietnã"<?php echo $pais === 'Vietnã' ? ' selected' : ''; ?>>Vietnã</option>
                                <option value="Zâmbia"<?php echo $pais === 'Zâmbia' ? ' selected' : ''; ?>>Zâmbia</option>
                                <option value="Zimbábue"<?php echo $pais === 'Zimbábue' ? ' selected' : ''; ?>>Zimbábue</option>
                            </select>
                            <label>País</label>
                        </div>

                        <div class="input-group"<?php echo $ufSelectStyle; ?>>
                            <select id="estado" name="estado" required>
                                <option value="" disabled <?php echo $estadoAtual === '' ? 'selected' : ''; ?> hidden></option>
                                <?php foreach ($ufs as $uf): ?>
                                    <option value="<?php echo h($uf); ?>" <?php echo $estadoAtual === $uf ? 'selected' : ''; ?>><?php echo h($uf); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label id="estadoLabel">Estado (UF)</label>
                        </div>
                        <div class="input-group" id="estadoTextoGroup"<?php echo $ufTextoStyle; ?>>
                            <input type="text" id="estado_texto" name="estado" autocomplete="address-level1" maxlength="100" value="<?php echo !$isBrazil ? h($estadoAtual) : ''; ?>" placeholder=" ">
                            <label>Estado / Região / Província</label>
                        </div>

                        <div class="input-group profile-field-full" id="cidadeSelectGroup"<?php echo $cidadeSelStyle; ?>>
                            <select id="cidade" name="cidade" required data-selected-city="<?php echo h($cidade); ?>">
                                <?php if ($isBrazil && $cidade !== ''): ?>
                                    <option value="<?php echo h($cidade); ?>" selected><?php echo h($cidade); ?></option>
                                <?php else: ?>
                                    <option value="" selected hidden></option>
                                <?php endif; ?>
                            </select>
                            <label>Cidade</label>
                            <small class="input-hint" id="cityHint"><?php echo ($isBrazil && $estadoAtual !== '') ? 'Carregando cidades...' : 'Selecione o estado primeiro.'; ?></small>
                        </div>
                        <div class="input-group profile-field-full" id="cidadeTextoGroup"<?php echo $cidadeTextoStyle; ?>>
                            <input type="text" id="cidade_texto" name="cidade" autocomplete="address-level2" maxlength="120" value="<?php echo !$isBrazil ? h($cidade) : ''; ?>" placeholder=" ">
                            <label>Cidade</label>
                        </div>

                        <div class="input-group">
                            <input type="text" id="telefone" name="telefone" required autocomplete="tel" inputmode="tel" maxlength="25" value="<?php echo h($telefone); ?>">
                            <label>Telefone</label>
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

            <section class="profile-danger-zone" aria-labelledby="profileDangerTitle">
                <div>
                    <h2 class="profile-danger-title" id="profileDangerTitle">Excluir minha conta</h2>
                    <p class="profile-danger-text">
                        Exclui seu cadastro e todos os seus dados de palpites do sistema. Esta ação não pode ser desfeita.
                    </p>
                </div>
                <button type="button" class="profile-delete-open" data-open-delete-account>
                    Excluir minha conta
                </button>
            </section>
        </div>
    </section>
</div>

<div class="delete-account-modal" data-delete-account-modal hidden>
    <div class="delete-account-backdrop" data-close-delete-account></div>
    <section class="delete-account-card" role="dialog" aria-modal="true" aria-labelledby="deleteAccountTitle" aria-describedby="deleteAccountText">
        <button type="button" class="delete-account-close" data-close-delete-account aria-label="Fechar">&times;</button>

        <div class="delete-account-step" data-delete-step="confirm">
            <p class="delete-account-kicker">Excluir minha conta</p>
            <h2 id="deleteAccountTitle">Tem certeza que deseja excluir sua conta?</h2>
            <p id="deleteAccountText">
                Ao confirmar, tudo será apagado do sistema: seu cadastro, seus palpites e todos os dados vinculados. Esta ação não poderá ser desfeita.
            </p>
            <div class="delete-account-actions">
                <button type="button" class="delete-account-secondary" data-close-delete-account>Cancelar</button>
                <button type="button" class="delete-account-danger" data-confirm-delete-account>Sim, quero excluir</button>
            </div>
        </div>

        <form method="POST" action="/php/excluir_cadastro.php" class="delete-account-step" data-delete-step="password" hidden>
            <?php echo app_csrf_field(); ?>
            <p class="delete-account-kicker">Confirmação final</p>
            <h2>Digite sua senha</h2>
            <p>
                A exclusão só será concluída se a senha da sua conta estiver correta.
            </p>
            <div class="input-group delete-account-password">
                <input type="password" id="senha_atual_excluir_conta" name="senha_atual" required autocomplete="current-password">
                <label>Senha atual</label>
            </div>
            <p class="delete-account-inline-error" data-delete-account-error hidden>Informe sua senha para continuar.</p>
            <div class="delete-account-actions">
                <button type="button" class="delete-account-secondary" data-back-delete-account>Voltar</button>
                <button type="submit" class="delete-account-danger">Excluir definitivamente</button>
            </div>
        </form>
    </section>
</div>

<script src="/js/cadastro.js?v=<?php echo (string)@filemtime(__DIR__ . '/js/cadastro.js'); ?>"></script>
<script src="/js/meus_dados.js?v=<?php echo (string)@filemtime(__DIR__ . '/js/meus_dados.js'); ?>"></script>
</body>
</html>
