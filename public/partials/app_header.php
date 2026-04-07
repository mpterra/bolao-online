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
 *  - $subtitle: string
 *  - $logoutHref: string
 */
function render_app_header(string $usuarioNome, bool $isAdmin, string $active, string $subtitle, string $logoutHref): void {

	static $bh_assets_printed = false;

	$menuItems = [
		[
			"key"   => "apostas",
			"label" => "Grupos",
			"href"  => "/app.php",
		],
		[
			"key"   => "mata_mata",
			"label" => "Mata-Mata",
			"href"  => "/mata_mata_palpites.php",
		],
		[
			"key"   => "ranking",
			"label" => "Ranking",
			"href"  => "/ranking.php",
		],
		[
			"key"   => "resultados_publico",
			"label" => "Resultados",
			"href"  => "/resultados.php",
		],
	];

	if ($isAdmin) {
		$menuItems[] = [
			"key"   => "admin",
			"label" => "Admin",
			"href"  => "/admin.php",
			"is_admin" => true,
		];
	}

	$activeLabel = "Menu";
	foreach ($menuItems as $item) {
		if ($item["key"] === $active) {
			$activeLabel = $item["label"];
			break;
		}
	}

	if (!$bh_assets_printed) {
		$bh_assets_printed = true;
		?>
		<style>
			.bh-header,
			.bh-header *{
				box-sizing:border-box;
			}

			.bh-header{
				position:sticky;
				top:calc(10px + env(safe-area-inset-top, 0px));
				z-index:9998;
				width:100%;
				margin:0 0 12px 0;
				border:1px solid rgba(255,255,255,.10);
				border-radius:18px;
				background:
					radial-gradient(900px 260px at 10% 10%, rgba(70,220,255,.10), transparent 60%),
					radial-gradient(760px 240px at 85% 20%, rgba(16,208,138,.10), transparent 58%),
					rgba(7,26,31,.82);
				backdrop-filter:blur(14px);
				box-shadow:0 16px 38px rgba(0,0,0,.28);
				overflow:hidden;
			}

			.bh-header__bar{
				display:grid;
				grid-template-columns:minmax(0, auto) minmax(0, 1fr) auto;
				align-items:center;
				gap:14px;
				padding:10px 14px;
			}

			.bh-header__brand{
				min-width:0;
			}

			.bh-header__brand-link{
				display:flex;
				align-items:center;
				gap:10px;
				text-decoration:none;
				min-width:0;
			}

			.bh-header__logo{
				width:40px;
				height:40px;
				object-fit:contain;
				flex:0 0 auto;
				display:block;
			}

			.bh-header__title-wrap{
				display:flex;
				flex-direction:column;
				min-width:0;
			}

			.bh-header__title{
				font-size:15px;
				font-weight:900;
				line-height:1.1;
				letter-spacing:.2px;
				color:rgba(255,255,255,.96);
				white-space:nowrap;
			}

			.bh-header__subtitle{
				font-size:11px;
				line-height:1.15;
				color:rgba(255,255,255,.68);
				white-space:nowrap;
				overflow:hidden;
				text-overflow:ellipsis;
				max-width:260px;
			}

			.bh-header__nav{
				display:flex;
				align-items:center;
				justify-content:center;
				gap:2px;
				min-width:0;
				flex-wrap:nowrap;
			}

			.bh-header__nav-link{
				position:relative;
				display:inline-flex;
				align-items:center;
				justify-content:center;
				padding:8px 10px;
				border-radius:10px;
				text-decoration:none;
				color:rgba(255,255,255,.78);
				font-size:13px;
				font-weight:800;
				line-height:1;
				white-space:nowrap;
				transition:background .18s ease,color .18s ease,transform .18s ease;
			}

			.bh-header__nav-link:hover{
				color:rgba(255,255,255,.96);
				background:rgba(255,255,255,.07);
			}

			.bh-header__nav-link.is-active{
				color:rgba(255,255,255,.98);
				background:rgba(255,255,255,.10);
			}

			.bh-header__nav-link.is-active::after{
				content:"";
				position:absolute;
				left:10px;
				right:10px;
				bottom:4px;
				height:2px;
				border-radius:999px;
				background:linear-gradient(90deg,#00c27a,#f7c948);
			}

			.bh-header__nav-link.is-admin{
				color:rgba(220,210,255,.94);
			}

			.bh-header__right{
				display:flex;
				align-items:center;
				gap:10px;
				flex:0 0 auto;
			}

			.bh-header__user{
				display:inline-flex;
				align-items:center;
				gap:8px;
				min-width:0;
				padding:7px 10px;
				border-radius:999px;
				border:1px solid rgba(255,255,255,.10);
				background:rgba(255,255,255,.05);
			}

			.bh-header__user-dot{
				width:8px;
				height:8px;
				border-radius:999px;
				background:linear-gradient(135deg,#00c27a,#f7c948);
				box-shadow:0 0 0 4px rgba(16,208,138,.08);
				flex:0 0 auto;
			}

			.bh-header__user-name{
				max-width:150px;
				overflow:hidden;
				text-overflow:ellipsis;
				white-space:nowrap;
				font-size:12px;
				font-weight:800;
				color:rgba(255,255,255,.90);
			}

			.bh-header__logout{
				display:inline-flex;
				align-items:center;
				justify-content:center;
				padding:8px 10px;
				border-radius:10px;
				text-decoration:none;
				font-size:12px;
				font-weight:800;
				color:rgba(255,255,255,.86);
				border:1px solid rgba(255,255,255,.10);
				background:rgba(255,255,255,.04);
				transition:background .18s ease,color .18s ease;
			}

			.bh-header__logout:hover{
				background:rgba(255,255,255,.08);
				color:rgba(255,255,255,.98);
			}

			.bh-header__toggle{
				display:none;
				width:40px;
				height:40px;
				padding:0;
				border:1px solid rgba(255,255,255,.10);
				border-radius:12px;
				background:rgba(255,255,255,.05);
				cursor:pointer;
				align-items:center;
				justify-content:center;
				flex-direction:column;
				gap:4px;
			}

			.bh-header__toggle span{
				display:block;
				width:18px;
				height:2px;
				border-radius:999px;
				background:rgba(255,255,255,.92);
			}

			.bh-header__mobile{
				display:none;
				padding:0 12px 12px 12px;
				border-top:1px solid rgba(255,255,255,.08);
				background:rgba(0,0,0,.14);
			}

			.bh-header__mobile.is-open{
				display:block;
			}

			.bh-header__mobile-head{
				padding:10px 2px 10px 2px;
				border-bottom:1px solid rgba(255,255,255,.08);
				margin-bottom:10px;
			}

			.bh-header__mobile-current{
				font-size:14px;
				font-weight:900;
				color:rgba(255,255,255,.96);
				margin-bottom:2px;
			}

			.bh-header__mobile-subtitle{
				font-size:12px;
				color:rgba(255,255,255,.68);
				line-height:1.3;
			}

			.bh-header__mobile-nav{
				display:flex;
				flex-direction:column;
				gap:6px;
			}

			.bh-header__mobile-link{
				display:flex;
				align-items:center;
				justify-content:space-between;
				gap:10px;
				padding:11px 12px;
				border-radius:12px;
				text-decoration:none;
				color:rgba(255,255,255,.90);
				font-size:14px;
				font-weight:800;
				border:1px solid rgba(255,255,255,.08);
				background:rgba(255,255,255,.05);
			}

			.bh-header__mobile-link:hover{
				background:rgba(255,255,255,.08);
			}

			.bh-header__mobile-link.is-active{
				color:rgba(255,255,255,.98);
				background:rgba(255,255,255,.10);
				border-color:rgba(255,255,255,.12);
			}

			.bh-header__mobile-link.is-admin{
				color:rgba(220,210,255,.95);
			}

			.bh-header__mobile-badge{
				display:inline-flex;
				align-items:center;
				justify-content:center;
				padding:4px 8px;
				border-radius:999px;
				font-size:11px;
				font-weight:900;
				color:#062027;
				background:linear-gradient(90deg,#00c27a,#f7c948);
				flex:0 0 auto;
			}

			.bh-header__mobile-footer{
				display:flex;
				align-items:center;
				justify-content:space-between;
				gap:10px;
				margin-top:12px;
				padding-top:12px;
				border-top:1px solid rgba(255,255,255,.08);
			}

			.bh-header__mobile-user{
				display:inline-flex;
				align-items:center;
				gap:8px;
				min-width:0;
			}

			.bh-header__mobile-user-name{
				max-width:180px;
				overflow:hidden;
				text-overflow:ellipsis;
				white-space:nowrap;
				font-size:12px;
				font-weight:800;
				color:rgba(255,255,255,.90);
			}

			.bh-header__mobile-logout{
				display:inline-flex;
				align-items:center;
				justify-content:center;
				padding:9px 12px;
				border-radius:10px;
				text-decoration:none;
				font-size:12px;
				font-weight:900;
				color:rgba(255,255,255,.92);
				border:1px solid rgba(255,255,255,.10);
				background:rgba(255,255,255,.05);
			}

			@media (max-width: 1100px){
				.bh-header__nav-link{
					padding:8px 8px;
					font-size:12px;
				}

				.bh-header__user-name{
					max-width:110px;
				}
			}

			@media (max-width: 860px){
				.bh-header__bar{
					grid-template-columns:minmax(0, 1fr) auto;
					gap:10px;
				}

				.bh-header__nav,
				.bh-header__user,
				.bh-header__logout{
					display:none;
				}

				.bh-header__toggle{
					display:inline-flex;
				}

				.bh-header__subtitle{
					max-width:100%;
				}
			}

			@media (max-width: 560px){
				.bh-header{
					border-radius:18px;
				}

				.bh-header__bar{
					padding:10px 12px;
				}

				.bh-header__logo{
					width:36px;
					height:36px;
				}

				.bh-header__title{
					font-size:14px;
				}

				.bh-header__subtitle{
					font-size:11px;
				}

				.bh-header__mobile-footer{
					flex-direction:column;
					align-items:stretch;
				}

				.bh-header__mobile-logout{
					width:100%;
				}
			}
		</style>

		<script>
			document.addEventListener("DOMContentLoaded", function () {
				if (window.__BH_HEADER_INIT__) return;
				window.__BH_HEADER_INIT__ = true;

				var headers = document.querySelectorAll(".bh-header");

				headers.forEach(function (header) {
					var toggle = header.querySelector(".bh-header__toggle");
					var mobile = header.querySelector(".bh-header__mobile");

					if (!toggle || !mobile) return;

					function closeMenu() {
						mobile.classList.remove("is-open");
						toggle.setAttribute("aria-expanded", "false");
					}

					function toggleMenu() {
						var isOpen = mobile.classList.contains("is-open");
						if (isOpen) {
							closeMenu();
						} else {
							mobile.classList.add("is-open");
							toggle.setAttribute("aria-expanded", "true");
						}
					}

					toggle.addEventListener("click", function (ev) {
						ev.preventDefault();
						ev.stopPropagation();
						toggleMenu();
					});

					document.addEventListener("click", function (ev) {
						if (!mobile.classList.contains("is-open")) return;
						if (header.contains(ev.target)) return;
						closeMenu();
					});

					document.addEventListener("keydown", function (ev) {
						if (ev.key === "Escape" && mobile.classList.contains("is-open")) {
							closeMenu();
							toggle.focus();
						}
					});

					window.addEventListener("resize", function () {
						if (window.innerWidth > 860) {
							closeMenu();
						}
					});
				});
			});
		</script>
		<?php
	}
	?>

	<header class="bh-header">
		<div class="bh-header__bar">

			<div class="bh-header__brand">
				<a class="bh-header__brand-link" href="/app.php" aria-label="Ir para a página principal do Bolão da Copa">
					<img class="bh-header__logo" src="/img/logo.png" alt="Bolão" onerror="this.style.display='none'">
					<div class="bh-header__title-wrap">
						<div class="bh-header__title">Bolão da Copa</div>
						<div class="bh-header__subtitle"><?php echo strh($subtitle); ?></div>
					</div>
				</a>
			</div>

			<nav class="bh-header__nav" aria-label="Menu principal">
				<?php foreach ($menuItems as $item): ?>
					<?php
					$isCurrent = ($item["key"] === $active);
					$isAdminItem = !empty($item["is_admin"]);
					?>
					<a
						class="bh-header__nav-link<?php echo $isCurrent ? " is-active" : ""; ?><?php echo $isAdminItem ? " is-admin" : ""; ?>"
						href="<?php echo strh($item["href"]); ?>"
						<?php echo $isCurrent ? 'aria-current="page"' : ''; ?>
						title="<?php echo strh($item["label"]); ?>"
					>
						<?php echo strh($item["label"]); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="bh-header__right">
				<div class="bh-header__user" title="<?php echo strh($usuarioNome); ?>">
					<span class="bh-header__user-dot"></span>
					<span class="bh-header__user-name"><?php echo strh($usuarioNome); ?></span>
				</div>

				<a class="bh-header__logout" href="<?php echo strh($logoutHref); ?>">Sair</a>

				<button
					class="bh-header__toggle"
					type="button"
					aria-expanded="false"
					aria-label="Abrir menu"
				>
					<span></span>
					<span></span>
					<span></span>
				</button>
			</div>

		</div>

		<div class="bh-header__mobile">
			<div class="bh-header__mobile-head">
				<div class="bh-header__mobile-current"><?php echo strh($activeLabel); ?></div>
				<div class="bh-header__mobile-subtitle"><?php echo strh($subtitle); ?></div>
			</div>

			<nav class="bh-header__mobile-nav" aria-label="Menu principal mobile">
				<?php foreach ($menuItems as $item): ?>
					<?php
					$isCurrent = ($item["key"] === $active);
					$isAdminItem = !empty($item["is_admin"]);
					?>
					<a
						class="bh-header__mobile-link<?php echo $isCurrent ? " is-active" : ""; ?><?php echo $isAdminItem ? " is-admin" : ""; ?>"
						href="<?php echo strh($item["href"]); ?>"
						<?php echo $isCurrent ? 'aria-current="page"' : ''; ?>
					>
						<span><?php echo strh($item["label"]); ?></span>
						<?php if ($isCurrent): ?>
							<span class="bh-header__mobile-badge">atual</span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="bh-header__mobile-footer">
				<div class="bh-header__mobile-user" title="<?php echo strh($usuarioNome); ?>">
					<span class="bh-header__user-dot"></span>
					<span class="bh-header__mobile-user-name"><?php echo strh($usuarioNome); ?></span>
				</div>

				<a class="bh-header__mobile-logout" href="<?php echo strh($logoutHref); ?>">Sair</a>
			</div>
		</div>
	</header>

	<?php
}