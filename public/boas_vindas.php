<?php
declare(strict_types=1);

$pixPayload = '00020126660014BR.GOV.BCB.PIX0122thiagopterra@gmail.com0218Bolão da Copa 2026520400005303986540580.005802BR5918THIAGO PEREZ TERRA6013CAXIAS DO SUL62120508Copa202663046B2A';
$pixAmount = 'R$ 80,00';
$pixRecipient = 'THIAGO PEREZ TERRA';
$pixCity = 'Caxias do Sul';
$pixKey = 'thiagopterra@gmail.com';
$whatsappLink = 'https://chat.whatsapp.com/CmzZCNsNenY8RKFxeOVwLA';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Boas-vindas - Bolão da Copa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">

    <link rel="stylesheet" href="/css/base.css">
    <link rel="stylesheet" href="/css/boas_vindas.css">
    <link rel="stylesheet" href="/css/visual-identity.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/visual-identity.css'); ?>">
</head>
<body>
    <div class="welcome-page">
        <main class="welcome-shell">
            <section class="hero-panel card-glass">
                <div class="hero-panel__top">
                    <a class="hero-link" href="/index.php">Entrar com minha conta</a>
                    <span class="badge">Cadastro concluído</span>
                </div>

                <div class="hero-layout">
                    <aside class="hero-poster">
                        <div class="hero-poster__frame">
                            <div class="hero-brand">
                                <img src="/img/logo.png" alt="Bolão da Copa 2026">
                            </div>
                        </div>
                        <div class="hero-poster__caption">
                            <strong>Bolão da Copa 2026</strong>
                            <span>Pagamento e grupo em uma única tela</span>
                        </div>
                    </aside>

                    <div class="hero-main">
                        <div class="hero-copy">
                            <p class="hero-kicker">Próximos passos</p>
                            <h1>Seu acesso ao bolão está quase pronto.</h1>
                            <p class="hero-description">
                                Conclua o PIX e entre no grupo do WhatsApp para receber avisos,
                                resultados e combinados do Bolão da Copa 2026.
                            </p>
                        </div>

                        <div class="hero-summary">
                            <div class="summary-item">
                                <strong><?php echo htmlspecialchars($pixAmount, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span>valor da inscrição</span>
                            </div>
                            <div class="summary-item">
                                <strong>2 ações rápidas</strong>
                                <span>pagar e entrar no grupo</span>
                            </div>
                            <div class="summary-item">
                                <strong>QR + link</strong>
                                <span>funciona no mobile e no desktop</span>
                            </div>
                        </div>

                        <div class="hero-steps">
                            <div class="hero-step">
                                <span class="hero-step__number">1</span>
                                <div>
                                    <strong>Faça o PIX</strong>
                                    <p>Escaneie o QR code ou copie o código completo para pagar sua inscrição.</p>
                                </div>
                            </div>
                            <div class="hero-step">
                                <span class="hero-step__number">2</span>
                                <div>
                                    <strong>Entre no grupo</strong>
                                    <p>Use o QR ou o link do convite para participar do grupo oficial do bolão.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="qr-grid">
                <article class="access-card card-glass access-card--pix">
                    <div class="access-card__header">
                        <span class="access-pill">PIX</span>
                        <h2>Pague sua entrada</h2>
                        <p>Escaneie o QR code no app do banco ou copie o PIX completo abaixo.</p>
                    </div>

                    <div class="qr-frame">
                        <img src="/img/qr-pix.svg" alt="QR code do pagamento via PIX do bolão">
                    </div>

                    <dl class="detail-list">
                        <div>
                            <dt>Valor</dt>
                            <dd><?php echo htmlspecialchars($pixAmount, ENT_QUOTES, 'UTF-8'); ?></dd>
                        </div>
                        <div>
                            <dt>Recebedor</dt>
                            <dd><?php echo htmlspecialchars($pixRecipient, ENT_QUOTES, 'UTF-8'); ?></dd>
                        </div>
                        <div>
                            <dt>Chave</dt>
                            <dd><?php echo htmlspecialchars($pixKey, ENT_QUOTES, 'UTF-8'); ?></dd>
                        </div>
                        <div>
                            <dt>Cidade</dt>
                            <dd><?php echo htmlspecialchars($pixCity, ENT_QUOTES, 'UTF-8'); ?></dd>
                        </div>
                    </dl>

                    <div class="action-row action-row--single">
                        <button type="button" class="action-btn" data-copy-target="pixPayload" data-copy-label="PIX copia e cola">
                            Copiar PIX
                        </button>
                    </div>

                    <label class="field-label" for="pixPayload">PIX copia e cola</label>
                    <textarea id="pixPayload" class="copy-field copy-field--tall" readonly><?php echo htmlspecialchars($pixPayload, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </article>

                <article class="access-card card-glass access-card--whatsapp">
                    <div class="access-card__header">
                        <span class="access-pill access-pill--whatsapp">WhatsApp</span>
                        <h2>Entre no grupo do bolão</h2>
                        <p>Use o QR code para abrir o convite ou entre direto pelo link oficial do grupo.</p>
                    </div>

                    <div class="qr-frame qr-frame--whatsapp">
                        <img src="/img/qr-whatsapp.svg" alt="QR code do grupo de WhatsApp do bolão">
                    </div>

                    <dl class="detail-list">
                        <div>
                            <dt>Convite oficial</dt>
                            <dd>Grupo do WhatsApp do Bolão da Copa 2026</dd>
                        </div>
                        <div>
                            <dt>Como entrar</dt>
                            <dd>Abra pelo QR code ou pelo link direto logo abaixo.</dd>
                        </div>
                    </dl>

                    <div class="action-row action-row--stack-mobile">
                        <a class="action-btn" href="<?php echo htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                            Entrar no grupo
                        </a>
                        <button type="button" class="action-btn action-btn--ghost" data-copy-target="whatsappLink" data-copy-label="link do grupo">
                            Copiar link
                        </button>
                    </div>

                    <label class="field-label" for="whatsappLink">Link do grupo</label>
                    <input id="whatsappLink" class="copy-field" type="text" readonly value="<?php echo htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8'); ?>">
                </article>
            </section>

            <section class="next-step card-glass">
                <div class="next-step__body">
                    <p class="next-step__kicker">Checklist final</p>
                    <h2>Fez o pagamento e já entrou no grupo?</h2>
                    <p>Perfeito. Depois disso, você já pode voltar para o login e acompanhar tudo por lá.</p>
                </div>

                <a class="action-btn action-btn--compact" href="/index.php">Ir para o login</a>
            </section>
        </main>
    </div>

    <div class="copy-toast" id="copyToast" aria-live="polite" aria-atomic="true"></div>

    <script src="/js/boas_vindas.js"></script>
</body>
</html>
