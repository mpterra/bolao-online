<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/php/security.php";
app_start_session();
app_send_security_headers();

require_once dirname(__DIR__) . "/php/conexao.php";
require_once dirname(__DIR__) . "/php/cadastro_mailer.php";

function require_login(): void {
    if (empty($_SESSION["usuario_id"])) {
        header("Location: /index.php");
        exit;
    }
}

function require_admin(): void {
    $tipo = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
    if (mb_strtoupper($tipo, "UTF-8") !== "ADMIN") {
        http_response_code(403);
        echo "Acesso negado.";
        exit;
    }
}

function strh(?string $s): string {
    return htmlspecialchars($s ?? "", ENT_QUOTES, "UTF-8");
}

function comunicado_normalize_text(string $value): string {
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace("/[ \t]+\n/", "\n", $value) ?? $value;

    return trim($value);
}

function comunicado_fetch_recipients(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT nome, email, ativo
        FROM usuarios
        WHERE email IS NOT NULL AND TRIM(email) <> ''
        ORDER BY ativo DESC, nome ASC, id ASC
    ");

    $recipients = [];
    $seen = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $email = mb_strtolower(trim((string)($row["email"] ?? "")), "UTF-8");
        if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        if (isset($seen[$email])) {
            continue;
        }

        $seen[$email] = true;
        $name = trim((string)($row["nome"] ?? ""));

        $recipients[] = [
            "name" => $name !== "" ? $name : $email,
            "email" => $email,
            "active" => ((int)($row["ativo"] ?? 0) === 1),
        ];
    }

    return $recipients;
}

function comunicado_build_html_body(string $message): string {
    $messageSafe = nl2br(strh($message), false);

    return ""
        . "<h2>Comunicado do Bolão do Thiago</h2>"
        . "<div style=\"font-size:15px;line-height:1.55;color:#222;\">{$messageSafe}</div>"
        . "<hr style=\"border:0;border-top:1px solid #ddd;margin:24px 0;\">"
        . "<p style=\"font-size:12px;color:#666;\">Mensagem enviada pela administração do Bolão do Thiago.</p>";
}

function comunicado_build_text_body(string $message): string {
    return "Comunicado do Bolao do Thiago\n\n"
        . $message
        . "\n\n--\nMensagem enviada pela administracao do Bolao do Thiago.";
}

require_login();
require_admin();

$usuarioNome = isset($_SESSION["usuario_nome"]) ? (string)$_SESSION["usuario_nome"] : "Admin";
$tipoSessao = isset($_SESSION["tipo_usuario"]) ? (string)$_SESSION["tipo_usuario"] : "";
$isAdmin = (mb_strtoupper($tipoSessao, "UTF-8") === "ADMIN");

if (isset($_GET["action"]) && $_GET["action"] === "logout") {
    session_destroy();
    header("Location: /index.php");
    exit;
}

try {
    $recipients = comunicado_fetch_recipients($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro ao carregar usuários.";
    exit;
}

$totalRecipients = count($recipients);
$activeRecipients = 0;
foreach ($recipients as $recipient) {
    if (!empty($recipient["active"])) {
        $activeRecipients++;
    }
}
$inactiveRecipients = $totalRecipients - $activeRecipients;

$subject = "";
$message = "";
$formErrors = [];
$sendResult = null;

if (isset($_SESSION["admin_comunicado_result"]) && is_array($_SESSION["admin_comunicado_result"])) {
    $sendResult = $_SESSION["admin_comunicado_result"];
    unset($_SESSION["admin_comunicado_result"]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    app_require_csrf(false);

    $subject = comunicado_normalize_text((string)($_POST["subject"] ?? ""));
    $message = comunicado_normalize_text((string)($_POST["message"] ?? ""));
    $confirmed = (string)($_POST["confirm_send"] ?? "") === "1";

    if ($subject === "") {
        $formErrors[] = "Informe o assunto do comunicado.";
    } elseif (mb_strlen($subject, "UTF-8") > 140) {
        $formErrors[] = "O assunto deve ter no máximo 140 caracteres.";
    }

    if ($message === "") {
        $formErrors[] = "Escreva a mensagem do comunicado.";
    } elseif (mb_strlen($message, "UTF-8") > 5000) {
        $formErrors[] = "A mensagem deve ter no máximo 5000 caracteres.";
    }

    if (!$confirmed) {
        $formErrors[] = "Confirme que deseja enviar para todos os usuários cadastrados.";
    }

    if ($totalRecipients === 0) {
        $formErrors[] = "Nenhum e-mail válido foi encontrado na lista de usuários.";
    }

    if (count($formErrors) === 0) {
        $sent = 0;
        $failed = [];

        if (!load_phpmailer_for_register()) {
            $formErrors[] = "PHPMailer não foi encontrado no servidor.";
        } else {
            $mailConfig = load_mail_config_for_register();
            $htmlBody = comunicado_build_html_body($message);
            $textBody = comunicado_build_text_body($message);

            foreach ($recipients as $recipient) {
                $ok = send_email_phpmailer(
                    $mailConfig,
                    (string)$recipient["email"],
                    (string)$recipient["name"],
                    $subject,
                    $htmlBody,
                    $textBody
                );

                if ($ok) {
                    $sent++;
                } else {
                    $failed[] = (string)$recipient["email"];
                }
            }

            cadastro_mail_log("Comunicado admin enviado. assunto=\"{$subject}\" enviados={$sent} falhas=" . count($failed));

            $sendResult = [
                "sent" => $sent,
                "failed" => $failed,
                "total" => $totalRecipients,
            ];

            $_SESSION["admin_comunicado_result"] = $sendResult;
            header("Location: /admin_comunicados.php?sent=1");
            exit;
        }
    }
}

require_once __DIR__ . "/partials/app_header.php";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8" />
    <title>Bolão da Copa - Comunicados</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <link rel="stylesheet" href="/css/admin.css">
    <link rel="stylesheet" href="/css/visual-identity.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/visual-identity.css'); ?>">
</head>
<body>

<div class="app-wrap">

    <?php
        render_app_header(
            $usuarioNome,
            $isAdmin,
            "admin",
            "Admin • Comunicados",
            "/admin_comunicados.php?action=logout"
        );
    ?>

    <div class="app-shell">
        <aside class="app-menu">
            <div class="menu-title">Ações</div>

            <div class="menu-actions menu-actions-tight">
                <a class="btn-receipt" href="/admin.php">
                    Exportar apostas por usuário
                </a>
                <a class="btn-receipt" href="/admin_usuarios_cadastro.php">
                    Lista de usuários
                </a>
                <a class="btn-receipt" href="/admin_comunicados.php" aria-current="page">
                    Enviar comunicado
                </a>
                <a class="btn-receipt" href="/php/export_apostas_todas_zip.php">
                    Baixar todas apostas
                </a>
                <a class="btn-atualizar-resultados" href="/admin_resultados.php">
                    Atualizar resultados
                </a>
                <a class="btn-mata-mata" href="/mata_mata.php">
                    Atualizar mata-mata
                </a>
            </div>
        </aside>

        <main class="app-content">
            <div class="content-head">
                <h1 class="content-h1">Enviar comunicado</h1>
                <p class="content-sub">Escreva uma mensagem e envie por e-mail para todos os usuários cadastrados.</p>
            </div>

            <div class="admin-card comunicado-card">
                <div class="comunicado-summary" aria-label="Resumo dos destinatários">
                    <div class="comunicado-metric">
                        <span>Destinatários</span>
                        <strong><?php echo $totalRecipients; ?></strong>
                    </div>
                    <div class="comunicado-metric">
                        <span>Ativos</span>
                        <strong><?php echo $activeRecipients; ?></strong>
                    </div>
                    <div class="comunicado-metric">
                        <span>Inativos</span>
                        <strong><?php echo $inactiveRecipients; ?></strong>
                    </div>
                </div>

                <?php if (count($formErrors) > 0): ?>
                    <div class="comunicado-alert comunicado-alert-error" role="alert">
                        <?php foreach ($formErrors as $error): ?>
                            <p><?php echo strh($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (is_array($sendResult)): ?>
                    <?php $failedCount = count($sendResult["failed"]); ?>
                    <div class="comunicado-alert <?php echo $failedCount > 0 ? "comunicado-alert-warn" : "comunicado-alert-success"; ?>" role="status">
                        <p>
                            Envio concluído: <?php echo (int)$sendResult["sent"]; ?> de <?php echo (int)$sendResult["total"]; ?> e-mails enviados.
                        </p>
                        <?php if ($failedCount > 0): ?>
                            <p>Falharam <?php echo $failedCount; ?> destinatário(s). Consulte o log de e-mail para detalhes.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form class="comunicado-form" method="post" action="/admin_comunicados.php">
                    <?php echo app_csrf_field(); ?>

                    <label class="comunicado-field">
                        <span>Assunto</span>
                        <input type="text" name="subject" maxlength="140" required value="<?php echo strh($subject); ?>">
                    </label>

                    <label class="comunicado-field">
                        <span>Mensagem</span>
                        <textarea name="message" rows="12" maxlength="5000" required><?php echo strh($message); ?></textarea>
                    </label>

                    <label class="comunicado-check">
                        <input type="checkbox" name="confirm_send" value="1" required>
                        <span>Confirmo o envio deste comunicado para todos os usuários cadastrados com e-mail válido.</span>
                    </label>

                    <button class="btn-atualizar-resultados comunicado-submit" type="submit" <?php echo $totalRecipients === 0 ? "disabled" : ""; ?>>
                        Enviar comunicado
                    </button>
                </form>
            </div>
        </main>
    </div>
</div>

<script src="/js/admin.js"></script>
</body>
</html>
