<?php
declare(strict_types=1);

if (!function_exists('render_whatsapp_float')) {
	function render_whatsapp_float(?string $usuarioNome = null): void {
		$nome = trim((string)$usuarioNome);
		$mensagem = $nome !== ''
			? 'Oi, sou o ' . $nome . ' e to com umas duvidas no bolao.'
			: 'Oi, estou com umas duvidas no bolao.';
		$href = 'https://wa.me/5554991819820?text=' . rawurlencode($mensagem);
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
