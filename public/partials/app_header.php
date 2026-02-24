<?php
declare(strict_types=1);

if (!function_exists("strh")) {
    function strh(?string $s): string {
        return htmlspecialchars($s ?? "", ENT_QUOTES, "UTF-8");
    }
}

/**
 * Renderiza o header padrão do sistema (mesmo menu em todas as telas).
 *
 * Requer:
 *  - $usuarioNome (string)
 *  - $isAdmin (bool)
 *
 * Parâmetros:
 *  - $active: string ('apostas'|'ranking'|'admin'|'resultados_publico'|'mata_mata'|etc)
 *  - $subtitle: string (texto pequeno abaixo do título)
 *  - $logoutHref: string (link de logout da tela atual)
 */
function render_app_header(string $usuarioNome, bool $isAdmin, string $active, string $subtitle, string $logoutHref): void {
    ?>
    <header class="app-header">
        <div class="app-brand">
            <img src="/img/logo.png" alt="Bolão" onerror="this.style.display='none'">
            <div class="app-title">
                <strong>Bolão da Copa</strong>
                <span><?php echo strh($subtitle); ?></span>
            </div>
        </div>

        <nav class="app-topnav" aria-label="Menu principal">
            <a class="topnav-link<?php echo $active === "apostas" ? " is-active" : ""; ?>"
               href="/app.php">Apostas</a>

            <a class="topnav-link<?php echo $active === "ranking" ? " is-active" : ""; ?>"
               href="/ranking.php">Ranking do Bolão</a>

            <a class="topnav-link<?php echo $active === "resultados_publico" ? " is-active" : ""; ?>"
               href="/resultados.php">Resultados</a>

            <?php if ($isAdmin): ?>
                <a class="topnav-link is-admin<?php echo $active === "admin" ? " is-active" : ""; ?>"
                   href="/admin.php">Admin</a>
            <?php endif; ?>
        </nav>

        <div class="app-actions">
            <div class="user-chip" title="<?php echo strh($usuarioNome); ?>">
                <span class="dot"></span>
                <span class="user-chip-name"><?php echo strh($usuarioNome); ?></span>
            </div>

            <a class="btn-logout" href="<?php echo strh($logoutHref); ?>">Sair</a>
        </div>
    </header>
    <?php
}