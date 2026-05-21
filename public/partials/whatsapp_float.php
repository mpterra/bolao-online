<?php
declare(strict_types=1);

if (!function_exists('render_whatsapp_float')) {
	function render_whatsapp_float(?string $usuarioNome = null): void {
		static $stylesPrinted = false;

		$nome = trim((string)$usuarioNome);
		$mensagem = $nome !== ''
			? 'Oi, sou o ' . $nome . ' e to com umas duvidas no bolao.'
			: 'Oi, estou com umas duvidas no bolao.';
		$href = 'https://wa.me/5554991819820?text=' . rawurlencode($mensagem);

		if (!$stylesPrinted) {
			$stylesPrinted = true;
			?>
			<style>
				.whatsapp-float{
					position:fixed !important;
					right:max(14px, env(safe-area-inset-right, 0px)) !important;
					bottom:calc(16px + env(safe-area-inset-bottom, 0px)) !important;
					z-index:100000 !important;
					display:inline-flex !important;
					align-items:center !important;
					justify-content:center !important;
					gap:8px !important;
					min-height:46px !important;
					max-width:calc(100vw - 28px) !important;
					padding:10px 14px !important;
					border-radius:999px !important;
					text-decoration:none !important;
					color:#062027 !important;
					border:1px solid rgba(255,255,255,.22) !important;
					background:linear-gradient(90deg, rgba(37,211,102,.98), rgba(247,201,72,.94)) !important;
					box-shadow:0 16px 34px rgba(0,0,0,.30) !important;
					transition:transform .18s ease, filter .18s ease, box-shadow .18s ease !important;
				}

				.whatsapp-float:hover{
					filter:saturate(1.05) !important;
					transform:translateY(-1px) !important;
					box-shadow:0 18px 38px rgba(0,0,0,.36) !important;
				}

				.whatsapp-float__icon{
					display:block !important;
					width:28px !important;
					height:28px !important;
					object-fit:contain !important;
					flex:0 0 auto !important;
					filter:drop-shadow(0 2px 4px rgba(0,0,0,.20)) !important;
				}

				.whatsapp-float__text{
					font-size:.86rem !important;
					font-weight:900 !important;
					line-height:1.12 !important;
					white-space:nowrap !important;
				}

				@media (min-width: 1040px){
					.whatsapp-float{
						left:calc(50% + 360px) !important;
						right:auto !important;
						bottom:calc(24px + env(safe-area-inset-bottom, 0px)) !important;
						min-height:54px !important;
						padding:11px 18px 11px 12px !important;
						transform:translateX(-50%) !important;
					}

					.whatsapp-float:hover{
						transform:translateX(-50%) translateY(-1px) !important;
					}

					.whatsapp-float__text{
						font-size:.9rem !important;
					}
				}
			</style>
			<?php
		}
		?>
		<a
			class="whatsapp-float"
			href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"
			target="_blank"
			rel="noopener noreferrer"
			aria-label="Tirar duvidas com Thiago pelo WhatsApp"
		>
			<img class="whatsapp-float__icon" src="/img/whatsapp.png" alt="" aria-hidden="true">
			<span class="whatsapp-float__text">Tire suas d&uacute;vidas</span>
		</a>
		<?php
	}
}
