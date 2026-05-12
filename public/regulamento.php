<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

$isLoggedIn = !empty($_SESSION['usuario_id']);
$usuarioNome = isset($_SESSION['usuario_nome']) ? (string)$_SESSION['usuario_nome'] : 'Apostador';
$tipoUsuario = isset($_SESSION['tipo_usuario']) ? (string)$_SESSION['tipo_usuario'] : '';
$isAdmin = (mb_strtoupper($tipoUsuario, 'UTF-8') === 'ADMIN');

if ($isLoggedIn) {
	require_once __DIR__ . '/partials/app_header.php';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
	<meta charset="UTF-8">
	<title>Bolão da Copa - Regulamento</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
	<link rel="stylesheet" href="css/base.css">
	<link rel="stylesheet" href="css/regulamento.css">
	<link rel="stylesheet" href="/css/visual-identity.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/visual-identity.css'); ?>">
</head>
<body class="<?php echo $isLoggedIn ? 'rules-authenticated' : 'rules-public'; ?>">
	<div class="rules-page">
		<?php if ($isLoggedIn): ?>
			<?php
			render_app_header(
				$usuarioNome,
				$isAdmin,
				'regulamento',
				'Regras, premiação e critérios do Bolão da Copa 2026',
				'php/logout.php'
			);
			?>
		<?php endif; ?>

		<main class="rules-shell">
			<nav class="rules-section-nav card-glass" aria-label="Navegação por seções do regulamento">
				<div class="rules-section-nav__inner">
					<a class="rules-section-nav__link" href="#participacao" data-section-link="participacao">A participação</a>
					<a class="rules-section-nav__link" href="#palpites" data-section-link="palpites">Os palpites</a>
					<a class="rules-section-nav__link" href="#pontuacao" data-section-link="pontuacao">A pontuação</a>
					<a class="rules-section-nav__link" href="#premiacao" data-section-link="premiacao">A premiação</a>
					<a class="rules-section-nav__link" href="#extras" data-section-link="extras">Os prêmios extras</a>
					<a class="rules-section-nav__link" href="#liga" data-section-link="liga">Liga do Mata-Mata</a>
					<a class="rules-section-nav__link" href="#desempate" data-section-link="desempate">Critérios de desempate</a>
					<a class="rules-section-nav__link" href="#simulacao" data-section-link="simulacao">Simulação</a>
					<a class="rules-section-nav__link" href="#final" data-section-link="final">Considerações finais</a>
				</div>
			</nav>

			<section class="rules-hero card-glass">
				<div class="rules-hero-main">
					<p class="rules-eyebrow">Regulamento oficial</p>
					<div class="rules-title">
						<h1>
							<span>BOLÃO DA COPA</span>
							<span>DO MUNDO 2026</span>
						</h1>
					</div>

					<div class="rules-badges" aria-label="Destaques do regulamento">
						<span class="rules-badge">Aposta de 80 reais</span>
						<span class="rules-badge">Top 10 premiado</span>
						<span class="rules-badge">10 prêmios extras</span>
						<span class="rules-badge">Liga do Mata-Mata</span>
					</div>

					<div class="rules-copy rules-copy--intro">
						<p class="rules-burst">Chegou a hora!!</p>
						<p class="rules-standfirst">O segundo maior espetáculo do universo observável vai começar!!</p>
						<p class="rules-aside">(Sim, porque nós somos humildes e sabemos que o primeiro lugar tem dono: a Copa do Mundo de futebol!)</p>
						<p>Preparem seus corações, tragam as crianças, chamem a família, os amigos, aquele vizinho meio mala para fazer número, que vai começar o Bolão da Copa do Mundo de 2026!</p>
						<p>O que lá na Copa do Mundo de 2014 começou como um humilde sonho de divertir amigos que adoravam copas do mundo, hoje é uma máquina de diversão e prêmios!</p>
						<p>No bolão da Copa de 2022 nós tivemos 102 participantes e 5.100 reais no potinho dos prêmios. A meta para esse bolão é bater os 200 apostadores!</p>
						<p>Então vamos nessa!</p>
						<p>De cara temos novidades!</p>
						<p>A primeira é que a partir desse bolão vai ser tudo pela internet!</p>
						<p>Adeus, planilha!</p>
						<p>Acessem <a class="rules-link" href="https://www.bolaodothiago.com.br">www.bolaodothiago.com.br</a>, conheçam o bolão, façam seus cadastros e sentem o dedo nos palpites!</p>
						<p>Como a compra da hospedagem e do domínio tiveram um custo de 460 reais, essa vai ser a primeira vez que vamos usar uma parte do dinheiro das apostas para financiar a estrutura do bolão. Vai ser só esse valor de 460 reais, mais nada. Todo o resto do dinheiro das apostas vai em prêmios.</p>
						<p>Mas o site do bolão não é a única novidade!</p>
						<p>Como agora temos uma solução tecnológica de mais alto nível, acessível a qualquer hora, em qualquer lugar, de computadores ou dispositivos móveis, vai ser possível alterar palpites durante a copa toda!</p>
						<p>E não para por aí!</p>
						<p>No bolão da Copa de 2022, o potinho com os 5.100 reais foi distribuído todo entre os 10 primeiros colocados. E vamos continuar premiando os 10 primeiros do bolão. Maaas... Agora também teremos 10 prêmios extras ao final do bolão para os apostadores que forem os melhores em 10 critérios de desempenho diferentes!</p>
						<p>Os valores da premiação vãos ser divulgados no dia a abertura da copa, porque dependem de quantos participantes vamos ter. Quanto mais gente, mais dinheiro no potinho!</p>
						<p>Novidades apresentadas, vamos às regras!</p>
					</div>

					<div class="rules-summary-strip" aria-label="Resumo rápido do regulamento">
						<div class="rules-summary-item">
							<span>Inscrição</span>
							<strong>80 reais</strong>
						</div>
						<div class="rules-summary-item">
							<span>Premiação principal</span>
							<strong>G10</strong>
						</div>
						<div class="rules-summary-item">
							<span>Extras</span>
							<strong>10 prêmios</strong>
						</div>
						<div class="rules-summary-item">
							<span>Novidade</span>
							<strong>Liga do Mata-Mata</strong>
						</div>
					</div>
				</div>
			</section>

			<div class="rules-content">
			<section id="participacao" class="rules-section card-glass">
				<h2>A participação</h2>
				<div class="rules-copy">
					<p>A aposta vai custar 80 reais.</p>
					<p>O pix para pagamento da aposta vai aparecer cadastramento no site.</p>
					<p>No cadastramento também tem o QR code para entrar no grupo do bolão no WhatsApp. A zoeira vai ser toda lá!</p>
					<p>Você pode fazer uma aposta só.</p>
					<p>Você vai palpitar em todos os jogos da Copa, do primeiro até a final.</p>
					<p>Você pode convidar quem você quiser. Os únicos critérios para participar são adorar copa do mundo e ser bem-humorado!</p>
				</div>
			</section>

			<section id="palpites" class="rules-section card-glass">
				<h2>Os palpites</h2>
				<div class="rules-copy">
					<p>Feito o cadastro, você vai colocar os palpites dos placares de todos os jogos da fase de grupos.</p>
					<p>Em cada grupo, você vai dizer que seleções vão ficar em primeiro, segundo e terceiro lugares em cada grupo.</p>
					<p>Você também vai dizer que seleção vai ser campeã do mundo. Esse palpite vai puder ser mudado só até o início da copa.</p>
					<p>Durante a fase de grupos, você vai poder mudar os palpites dos jogos o tempo todo, com uma regra: isso vai poder ser feito até uma hora antes do primeiro jogo do dia.</p>
					<p>Quando começar o mata-mata, você seguir palpitando em todos os jogos de cada fase, colocando o placar do jogo e que seleção vai passar de fase em caso de empate.</p>
					<p>Segue valendo a regra de alterar os palpites até uma hora antes do primeiro jogo do dia.</p>
					<p>A partir do mata-mata, o placar que vai valer é o placar do apito final do juiz, não importa se no tempo normal ou na prorrogação. Terminou o jogo pra valer, é aquele placar que vai ser considerado.</p>
					<p>Quando as semifinais forem definidas, você vai colocar os palpites dos dois jogos e também que seleções vão ser campeã, vice, terceiro e quarto lugares da copa.</p>
				</div>
			</section>

			<section id="pontuacao" class="rules-section card-glass">
				<h2>A pontuação</h2>
				<div class="rules-copy">
					<p>Você vai fazer 1 ponto por cada acerto no bolão.</p>
				</div>

				<h3>O que vai contar ponto:</h3>
				<ul class="rules-list">
					<li>Acertar o resultado de um jogo (empate ou vitória de uma seleção)</li>
					<li>Acertar o placar de um jogo</li>
					<li>Acertar as seleções que passaram da fase de grupos para o mata-mata</li>
					<li>Acertar a colocação em que essa seleção passou no seu grupo (em primeiro, segundo ou algum melhor terceiro lugar).</li>
					<li>Atenção: se você colocou uma seleção em terceiro lugar em um grupo, ela ficou em terceiro lugar, mas não passou para o mata-mata, você não vai fazer 1 ponto. A pontuação é para cada seleção que se classifica.</li>
					<li>Acertar as seleções que forem se classificando no mata-mata.</li>
					<li>Acertar a seleção campeã (naquele palpite das semifinais. O palpite da campeã no início do bolão vai ser para outra coisa)</li>
					<li>Acertar a seleção vice-campeã</li>
					<li>Acertar a seleção que ficar em terceiro lugar</li>
					<li>Acertar a seleção que ficar em quarto lugar</li>
				</ul>
			</section>

			<section id="premiacao" class="rules-section card-glass">
				<h2>A premiação</h2>
				<div class="rules-copy">
					<p>De todo dinheiro arrecadado para o potinho dos prêmios, 460 reais vão ser usados para parar a hospedagem e o domínio do site.</p>
					<p>O resto todo é premiação!</p>
					<p>Como essa premiação vai ser distribuída?</p>
					<p>Uma parte do dinheiro vai para os prêmios extras – mais ou menos 28% do potinho – e o resto todo vai para os 10 melhores ao final do bolão, o famoso G10!</p>
				</div>

				<h3>O G10</h3>
				<div class="rules-copy">
					<p>O G10 é o panteão dos campeões do bolão! É o lugar em que todo mundo vai querer estar quando o juiz apitar o fim da copa!</p>
					<p>Mais ou menos 72% do potinho do bolão vai ser distribuído entre os 10 melhores, nessa proporção:</p>
				</div>

				<div class="rules-table-wrap">
					<table class="rules-table">
						<thead>
							<tr>
								<th>G10</th>
								<th>Premiação</th>
							</tr>
						</thead>
						<tbody>
							<tr><td>1º</td><td>20%</td></tr>
							<tr><td>2º</td><td>17%</td></tr>
							<tr><td>3º</td><td>14%</td></tr>
							<tr><td>4º</td><td>10%</td></tr>
							<tr><td>5º</td><td>9%</td></tr>
							<tr><td>6º</td><td>8%</td></tr>
							<tr><td>7º</td><td>7%</td></tr>
							<tr><td>8º</td><td>6%</td></tr>
							<tr><td>9º</td><td>5%</td></tr>
							<tr><td>10º</td><td>4%</td></tr>
						</tbody>
					</table>
				</div>

				<div class="rules-copy">
					<p>No final você vai encontrar uma simulação de premiação. E lembrem: quanto mais participantes, mais dinheiro no potinho!</p>
				</div>
			</section>

			<section id="extras" class="rules-section card-glass">
				<h2>Os prêmios extras</h2>
				<div class="rules-copy">
					<p>Os prêmios extras do bolão são 10!</p>
					<p>Ao final do bolão, vai levar prêmio extra o apostador que:</p>
				</div>

				<ol class="rules-ordered-list">
					<li>Acertar a seleção campeã lá no início do bolão</li>
					<li>Acertar mais resultados</li>
					<li>Acertar mais placares</li>
					<li>Fizer mais pontos em toda a primeira fase (contando resultados, placares e seleções classificadas)</li>
					<li>Fizer mais pontos em todo o mata-mata (contando resultados, placares, seleções classificadas e as quatro melhores)</li>
					<li>Acertar mais seleções classificadas em todas as fases (da fase de grupos até a final)</li>
					<li>Acertar o placar da final da copa</li>
					<li>Fizer mais pontos nos jogos do Brasil</li>
					<li>Fizer mais pontos com o campeão da copa</li>
					<li>For o campeão da Liga do Mata-Mata</li>
				</ol>

				<div class="rules-copy">
					<p>Os prêmios extras são pagos independentemente da colocação final do apostador no bolão! Não importa se você ganhar o bolão ou se ficar em último. Se você for o melhor em algum desses prêmios extras, vai levar ele.</p>
					<p>E os prêmios extras são cumulativos!</p>
					<p>Se você ficar entre os 10 melhores do bolão e também tiver sido o melhor em algum prêmio extra, vai levar tanto a premiação dos 10 melhores quanto o prêmio extra.</p>
					<p>E se você for o melhor em mais de um prêmio extra, vai levar cada um deles!</p>
					<p>O critério de desempate para definir quem leva cada prêmio extra é a colocação final no bolão.</p>
					<p>Os prêmios extras de 1 a 7 são auto-explicativos.</p>
					<p>O prêmio 8 (mais pontos nos jogos do Brasil) vai levar em consideração a pontuação total que o apostador fez torcendo pelo Brasil. Ou seja, se apostou em empate ou vitória do Brasil e deu isso, faz pontos. Se apostou no Brasil passando de fases, faz ponto. Mas se apostou no adversário do Brasil e o Brasil se der mal, não leva ponto.</p>
					<p>A mesma lógica vale para o prêmio 9 (mais pontos com o campeão da copa). Ao final do bolão, quem tiver feitos mais pontos em todos os jogos do campeão, leva esse prêmio.</p>
				</div>
			</section>

			<section id="liga" class="rules-section card-glass">
				<h2>A Liga do Mata-Mata</h2>
				<div class="rules-copy">
					<p>E agora mais uma novidade do bolão: a Liga do Mata-Mata!</p>
				</div>

				<h3>O que é esse trem?</h3>
				<div class="rules-copy">
					<p>A Liga do Mata-Mata é um mata-mata entre os 32 melhores colocados no bolão ao final da fase de grupos.</p>
					<p>32 seleções classificadas, 32 melhores apostadores. Entendeu, né?</p>
					<p>Quando a fase de grupos terminar, nós vamos pegar os 32 primeiros colocados do bolão e atribuir uma seleção classificada para cada um, e o apostador vai seguir com essa seleção enquanto ela estiver disputando a copa!</p>
					<p>Ao final, teremos uma seleção campeã e um apostador campeão da Liga do Mata-Mata!</p>
					<p>E quais serão os critérios para atribuir as seleções?</p>
					<p>No final da primeira fase nós vamos pegar as 32 seleções classificadas e rodar uma classificação delas a partir dos critérios de desempate estabelecidos pela FIFA no caderno de regras da Copa do Mundo.</p>
					<p>Dessa forma, vamos ter a melhor seleção da fase de grupos, a segunda melhor, a terceira, e assim até a 32ª seleção.</p>
					<p>Então nós vamos fazer uma coisa simples: o apostador que estiver em primeiro lugar no fim da fase de grupos fica com a melhor seleção, o segundo lugar fica com a segunda melhor seleção, e assim até o 32º colocado, que vai ficar com a 32ª seleção.</p>
					<p>Feito isso, cada um vai torcer pela sua seleção até o final!</p>
					<p>Não vai ter apostas entre os 32 melhores apostadores. O que vai valer é o jogo entre as seleções. O objetivo é a zoeira!</p>
					<p>Se ao final da primeira fase nós tivermos dois ou mais apostadores empatados em tudo entre os 32 primeiros, cada um vai ganhar a seleção correspondente entre as 32 classificadas. Então pode ser que a Liga do Mata-Mata tenha mais de 32 apostadores. Se isso acontecer, lá no final da Copa esses apostadores vão dividir o prêmio.</p>
				</div>
			</section>

			<section id="desempate" class="rules-section card-glass">
				<h2>Critérios de desempate</h2>
				<div class="rules-copy">
					<p>Os critérios de desempate na classificação do bolão são os seguintes, por ordem de importância:</p>
				</div>

				<ol class="rules-ordered-list">
					<li>Total de pontos</li>
					<li>Quantos resultados acertou</li>
					<li>Quantos placares acertou</li>
					<li>Total de pontos na primeira fase</li>
					<li>Total de pontos no mata-mata</li>
					<li>Total de pontos com seleções classificadas</li>
					<li>Total de pontos com o Brasil</li>
					<li>Total de pontos com o campeão</li>
					<li>Acertar o campeão no início</li>
					<li>Acertar o placar da final</li>
					<li>Acertar o campeão</li>
					<li>Acertar o vice-campeão</li>
					<li>Acertar o terceiro coloado</li>
					<li>Acertar o quarto colocado</li>
					<li>Quem nasceu antes</li>
				</ol>

				<div class="rules-copy">
					<p>Nós já tivemos 6 bolões: Copa de 2014, Copa de 2018, Copa de 2022, Copa Feminina de 2023, Eurocopa de 2024 e Mundial de Clubes de 2025. Nunca teve dois ou mais apostadores empatados em tudo, mas como o seguro morreu de velho, temos o critério 15, hahaha!</p>
				</div>
			</section>

			<section id="simulacao" class="rules-section card-glass">
				<h2>Simulação de premiação</h2>
				<div class="rules-copy">
					<p>Como a essa altura tá todo mundo curioso para saber quanto o potinho do bolão vai ter, vamos simular!</p>
					<p>A meta é 200 pessoas no bolão! Todo mundo tem que ajudar na divulgação!</p>
					<p>200 participantes vão gerar 16 mil reais de arrecadação.</p>
					<p>16 mil reais – 460 reais de custos = 15.540 reais no potinho!</p>
					<p>Com 15,540 reais no potinho, dá para pagar 440 reais para cada um dos prêmios extras (5,5 vezes a aposta de 80 reais).</p>
					<p>15.540 reais – (440 reais x 10 prêmios extras) = 11.140 reais para o G10.</p>
					<p>11.140 reais para o G10 dá o seguinte:</p>
				</div>

				<div class="rules-table-wrap">
					<table class="rules-table rules-table--compact">
						<thead>
							<tr>
								<th>G10</th>
								<th>Premiação</th>
								<th>%</th>
							</tr>
						</thead>
						<tbody>
							<tr><td>1º</td><td>2.228,00</td><td>20%</td></tr>
							<tr><td>2º</td><td>1.893,80</td><td>17%</td></tr>
							<tr><td>3º</td><td>1.559,60</td><td>14%</td></tr>
							<tr><td>4º</td><td>1.114,00</td><td>10%</td></tr>
							<tr><td>5º</td><td>1.002,60</td><td>9%</td></tr>
							<tr><td>6º</td><td>891,20</td><td>8%</td></tr>
							<tr><td>7º</td><td>779,80</td><td>7%</td></tr>
							<tr><td>8º</td><td>668,40</td><td>6%</td></tr>
							<tr><td>9º</td><td>557,00</td><td>5%</td></tr>
							<tr><td>10º</td><td>445,60</td><td>4%</td></tr>
						</tbody>
					</table>
				</div>

				<div class="rules-copy">
					<p>É um belo dinheiro!</p>
					<p>O que vai ser feito é tentar deixar o valor dos prêmios extras igual ao valor do 10º colocado.</p>
					<p>Se nós tivermos menos de 200 participantes – ou mais! –, o valor dos prêmios extras vai ser ajustado para mais ou para menos para mais para seguir essa regra. Mas o percentual do G10 não vai mudar.</p>
				</div>
			</section>

			<section id="final" class="rules-section rules-section--final card-glass">
				<h2>Considerações finais</h2>
				<div class="rules-copy rules-copy--final">
					<p>As considerações finais são:</p>
					<strong>VAMOOOOOOO!!!</strong>
				</div>
			</section>
			</div>
		</main>
	</div>
	<script src="js/regulamento.js"></script>
</body>
</html>
