<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/php/security.php";
require_once dirname(__DIR__) . "/php/registration_lock.php";
require_once __DIR__ . "/partials/whatsapp_float.php";
app_start_session();
app_send_security_headers();
redirect_if_registration_closed();

$sucesso = (isset($_GET['sucesso']) && $_GET['sucesso'] === '1');

if ($sucesso) {
    header('Location: /boas_vindas.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Cadastro - Bolão da Copa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <link rel="stylesheet" href="/css/base.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/base.css'); ?>">
    <link rel="stylesheet" href="/css/login.css">
    <link rel="stylesheet" href="/css/cadastro.css">
    <link rel="stylesheet" href="/css/visual-identity.css?v=<?php echo (string)@filemtime(__DIR__ . '/css/visual-identity.css'); ?>">
</head>

<body data-reg-success="<?php echo $sucesso ? '1' : '0'; ?>">

    <div class="page">

        <div class="logo-wrapper">
            <img src="/img/logo.png" alt="Bolão da Copa">
        </div>

        <div class="login-card">
            <h1>Criar Conta</h1>

            <form method="POST" action="/php/cadastrar_usuario.php" class="login-form" autocomplete="on">
                <?php echo app_csrf_field(); ?>

                <div class="input-group">
                    <input type="text" name="nome" required autocomplete="given-name">
                    <label>Nome</label>
                </div>

                <div class="input-group">
                    <input type="text" name="sobrenome" required autocomplete="family-name">
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
                        pattern="\d{2}/\d{2}/\d{4}">
                    <label>Data de nascimento</label>
                    <button type="button" class="password-toggle date-picker-toggle" data-open-date-picker="data_nascimento_picker" aria-label="Abrir calendário para data de nascimento">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M7 2v3M17 2v3M4 9h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <rect x="4" y="5" width="16" height="15" rx="3" stroke="currentColor" stroke-width="2"/>
                            <path d="M8 13h3M8 17h3M13 13h3M13 17h3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                    <input type="date" id="data_nascimento_picker" class="date-picker-native" tabindex="-1" aria-hidden="true" max="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="input-group">
                    <input type="email" name="email" required autocomplete="email">
                    <label>Email</label>
                </div>

                <div class="input-group">
                    <select id="pais" name="pais" required>
                        <option value="" disabled selected hidden></option>
                        <option value="Brasil">Brasil</option>
                        <option value="" disabled>──────────────</option>
                        <option value="Afeganistão">Afeganistão</option>
                        <option value="África do Sul">África do Sul</option>
                        <option value="Albânia">Albânia</option>
                        <option value="Alemanha">Alemanha</option>
                        <option value="Andorra">Andorra</option>
                        <option value="Angola">Angola</option>
                        <option value="Antígua e Barbuda">Antígua e Barbuda</option>
                        <option value="Arábia Saudita">Arábia Saudita</option>
                        <option value="Argélia">Argélia</option>
                        <option value="Argentina">Argentina</option>
                        <option value="Armênia">Armênia</option>
                        <option value="Austrália">Austrália</option>
                        <option value="Áustria">Áustria</option>
                        <option value="Azerbaijão">Azerbaijão</option>
                        <option value="Bahamas">Bahamas</option>
                        <option value="Bangladesh">Bangladesh</option>
                        <option value="Barbados">Barbados</option>
                        <option value="Barein">Barein</option>
                        <option value="Bélgica">Bélgica</option>
                        <option value="Belize">Belize</option>
                        <option value="Benin">Benin</option>
                        <option value="Bielorrússia">Bielorrússia</option>
                        <option value="Birmânia (Myanmar)">Birmânia (Myanmar)</option>
                        <option value="Bolívia">Bolívia</option>
                        <option value="Bósnia e Herzegovina">Bósnia e Herzegovina</option>
                        <option value="Botsuana">Botsuana</option>
                        <option value="Brunei">Brunei</option>
                        <option value="Bulgária">Bulgária</option>
                        <option value="Burquina Fasso">Burquina Fasso</option>
                        <option value="Burundi">Burundi</option>
                        <option value="Butão">Butão</option>
                        <option value="Cabo Verde">Cabo Verde</option>
                        <option value="Camarões">Camarões</option>
                        <option value="Camboja">Camboja</option>
                        <option value="Canadá">Canadá</option>
                        <option value="Catar">Catar</option>
                        <option value="Cazaquistão">Cazaquistão</option>
                        <option value="Chade">Chade</option>
                        <option value="Chile">Chile</option>
                        <option value="China">China</option>
                        <option value="Chipre">Chipre</option>
                        <option value="Colômbia">Colômbia</option>
                        <option value="Comores">Comores</option>
                        <option value="Congo">Congo</option>
                        <option value="Coreia do Norte">Coreia do Norte</option>
                        <option value="Coreia do Sul">Coreia do Sul</option>
                        <option value="Costa do Marfim">Costa do Marfim</option>
                        <option value="Costa Rica">Costa Rica</option>
                        <option value="Croácia">Croácia</option>
                        <option value="Cuba">Cuba</option>
                        <option value="Dinamarca">Dinamarca</option>
                        <option value="Djibuti">Djibuti</option>
                        <option value="Dominica">Dominica</option>
                        <option value="Egito">Egito</option>
                        <option value="El Salvador">El Salvador</option>
                        <option value="Emirados Árabes Unidos">Emirados Árabes Unidos</option>
                        <option value="Equador">Equador</option>
                        <option value="Eritreia">Eritreia</option>
                        <option value="Eslováquia">Eslováquia</option>
                        <option value="Eslovênia">Eslovênia</option>
                        <option value="Espanha">Espanha</option>
                        <option value="Eswatini">Eswatini</option>
                        <option value="Estados Unidos">Estados Unidos</option>
                        <option value="Estônia">Estônia</option>
                        <option value="Etiópia">Etiópia</option>
                        <option value="Fiji">Fiji</option>
                        <option value="Filipinas">Filipinas</option>
                        <option value="Finlândia">Finlândia</option>
                        <option value="França">França</option>
                        <option value="Gabão">Gabão</option>
                        <option value="Gâmbia">Gâmbia</option>
                        <option value="Gana">Gana</option>
                        <option value="Geórgia">Geórgia</option>
                        <option value="Granada">Granada</option>
                        <option value="Grécia">Grécia</option>
                        <option value="Guatemala">Guatemala</option>
                        <option value="Guiana">Guiana</option>
                        <option value="Guiné">Guiné</option>
                        <option value="Guiné-Bissau">Guiné-Bissau</option>
                        <option value="Guiné Equatorial">Guiné Equatorial</option>
                        <option value="Haiti">Haiti</option>
                        <option value="Honduras">Honduras</option>
                        <option value="Hungria">Hungria</option>
                        <option value="Iêmen">Iêmen</option>
                        <option value="Índia">Índia</option>
                        <option value="Indonésia">Indonésia</option>
                        <option value="Irã">Irã</option>
                        <option value="Iraque">Iraque</option>
                        <option value="Irlanda">Irlanda</option>
                        <option value="Islândia">Islândia</option>
                        <option value="Israel">Israel</option>
                        <option value="Itália">Itália</option>
                        <option value="Jamaica">Jamaica</option>
                        <option value="Japão">Japão</option>
                        <option value="Jordânia">Jordânia</option>
                        <option value="Kiribati">Kiribati</option>
                        <option value="Kuwait">Kuwait</option>
                        <option value="Laos">Laos</option>
                        <option value="Lesoto">Lesoto</option>
                        <option value="Letônia">Letônia</option>
                        <option value="Líbano">Líbano</option>
                        <option value="Libéria">Libéria</option>
                        <option value="Líbia">Líbia</option>
                        <option value="Liechtenstein">Liechtenstein</option>
                        <option value="Lituânia">Lituânia</option>
                        <option value="Luxemburgo">Luxemburgo</option>
                        <option value="Macedônia do Norte">Macedônia do Norte</option>
                        <option value="Madagascar">Madagascar</option>
                        <option value="Malásia">Malásia</option>
                        <option value="Malaui">Malaui</option>
                        <option value="Maldivas">Maldivas</option>
                        <option value="Mali">Mali</option>
                        <option value="Malta">Malta</option>
                        <option value="Marrocos">Marrocos</option>
                        <option value="Ilhas Marshall">Ilhas Marshall</option>
                        <option value="Mauritânia">Mauritânia</option>
                        <option value="Maurício">Maurício</option>
                        <option value="México">México</option>
                        <option value="Micronésia">Micronésia</option>
                        <option value="Moçambique">Moçambique</option>
                        <option value="Moldávia">Moldávia</option>
                        <option value="Mônaco">Mônaco</option>
                        <option value="Mongólia">Mongólia</option>
                        <option value="Montenegro">Montenegro</option>
                        <option value="Namíbia">Namíbia</option>
                        <option value="Nauru">Nauru</option>
                        <option value="Nepal">Nepal</option>
                        <option value="Nicarágua">Nicarágua</option>
                        <option value="Níger">Níger</option>
                        <option value="Nigéria">Nigéria</option>
                        <option value="Noruega">Noruega</option>
                        <option value="Nova Zelândia">Nova Zelândia</option>
                        <option value="Omã">Omã</option>
                        <option value="Países Baixos">Países Baixos</option>
                        <option value="Palau">Palau</option>
                        <option value="Palestina">Palestina</option>
                        <option value="Panamá">Panamá</option>
                        <option value="Papua-Nova Guiné">Papua-Nova Guiné</option>
                        <option value="Paquistão">Paquistão</option>
                        <option value="Paraguai">Paraguai</option>
                        <option value="Peru">Peru</option>
                        <option value="Polônia">Polônia</option>
                        <option value="Portugal">Portugal</option>
                        <option value="Quênia">Quênia</option>
                        <option value="Quiribati">Quiribati</option>
                        <option value="Quirguistão">Quirguistão</option>
                        <option value="Reino Unido">Reino Unido</option>
                        <option value="República Centro-Africana">República Centro-Africana</option>
                        <option value="República Democrática do Congo">República Democrática do Congo</option>
                        <option value="República Dominicana">República Dominicana</option>
                        <option value="República Tcheca">República Tcheca</option>
                        <option value="Romênia">Romênia</option>
                        <option value="Ruanda">Ruanda</option>
                        <option value="Rússia">Rússia</option>
                        <option value="Samoa">Samoa</option>
                        <option value="San Marino">San Marino</option>
                        <option value="Santa Lúcia">Santa Lúcia</option>
                        <option value="São Cristóvão e Nevis">São Cristóvão e Nevis</option>
                        <option value="São Tomé e Príncipe">São Tomé e Príncipe</option>
                        <option value="São Vicente e Granadinas">São Vicente e Granadinas</option>
                        <option value="Senegal">Senegal</option>
                        <option value="Serra Leoa">Serra Leoa</option>
                        <option value="Sérvia">Sérvia</option>
                        <option value="Seychelles">Seychelles</option>
                        <option value="Singapura">Singapura</option>
                        <option value="Síria">Síria</option>
                        <option value="Somália">Somália</option>
                        <option value="Sri Lanka">Sri Lanka</option>
                        <option value="Sudão">Sudão</option>
                        <option value="Sudão do Sul">Sudão do Sul</option>
                        <option value="Suécia">Suécia</option>
                        <option value="Suíça">Suíça</option>
                        <option value="Suriname">Suriname</option>
                        <option value="Tailândia">Tailândia</option>
                        <option value="Taiwan">Taiwan</option>
                        <option value="Tajiquistão">Tajiquistão</option>
                        <option value="Tanzânia">Tanzânia</option>
                        <option value="Timor-Leste">Timor-Leste</option>
                        <option value="Togo">Togo</option>
                        <option value="Tonga">Tonga</option>
                        <option value="Trinidad e Tobago">Trinidad e Tobago</option>
                        <option value="Tunísia">Tunísia</option>
                        <option value="Turcomenistão">Turcomenistão</option>
                        <option value="Turquia">Turquia</option>
                        <option value="Tuvalu">Tuvalu</option>
                        <option value="Ucrânia">Ucrânia</option>
                        <option value="Uganda">Uganda</option>
                        <option value="Uruguai">Uruguai</option>
                        <option value="Uzbequistão">Uzbequistão</option>
                        <option value="Vanuatu">Vanuatu</option>
                        <option value="Vaticano">Vaticano</option>
                        <option value="Venezuela">Venezuela</option>
                        <option value="Vietnã">Vietnã</option>
                        <option value="Zâmbia">Zâmbia</option>
                        <option value="Zimbábue">Zimbábue</option>
                    </select>
                    <label>País</label>
                </div>

                <div class="input-group">
                    <select id="estado" name="estado" required>
                        <option value="" disabled selected hidden></option>
                        <option value="AC">AC</option>
                        <option value="AL">AL</option>
                        <option value="AP">AP</option>
                        <option value="AM">AM</option>
                        <option value="BA">BA</option>
                        <option value="CE">CE</option>
                        <option value="DF">DF</option>
                        <option value="ES">ES</option>
                        <option value="GO">GO</option>
                        <option value="MA">MA</option>
                        <option value="MT">MT</option>
                        <option value="MS">MS</option>
                        <option value="MG">MG</option>
                        <option value="PA">PA</option>
                        <option value="PB">PB</option>
                        <option value="PR">PR</option>
                        <option value="PE">PE</option>
                        <option value="PI">PI</option>
                        <option value="RJ">RJ</option>
                        <option value="RN">RN</option>
                        <option value="RS">RS</option>
                        <option value="RO">RO</option>
                        <option value="RR">RR</option>
                        <option value="SC">SC</option>
                        <option value="SP">SP</option>
                        <option value="SE">SE</option>
                        <option value="TO">TO</option>
                    </select>
                    <label id="estadoLabel">Estado (UF)</label>
                </div>
                <div class="input-group" id="estadoTextoGroup" style="display:none">
                    <input type="text" id="estado_texto" name="estado" autocomplete="address-level1" maxlength="100" placeholder=" ">
                    <label>Estado / Região / Província</label>
                </div>

                <div class="input-group" id="cidadeSelectGroup">
                    <select id="cidade" name="cidade" required disabled>
                        <option value="" selected hidden></option>
                    </select>
                    <label>Cidade</label>
                    <small class="input-hint" id="cityHint">Selecione o estado primeiro.</small>
                </div>
                <div class="input-group" id="cidadeTextoGroup" style="display:none">
                    <input type="text" id="cidade_texto" name="cidade" autocomplete="address-level2" maxlength="120" placeholder=" ">
                    <label>Cidade</label>
                </div>

                <div class="input-group">
                    <input type="text" id="telefone" name="telefone" required autocomplete="tel" inputmode="tel" maxlength="25">
                    <label>Telefone</label>
                </div>

                <div class="input-group">
                    <input type="password" id="senha" name="senha" required autocomplete="new-password">
                    <label>Senha</label>
                    <button type="button" class="password-toggle" data-toggle-password="senha" aria-label="Mostrar senha" aria-pressed="false">
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

                <div class="input-group">
                    <input type="password" id="confirmar_senha" name="confirmar_senha" required autocomplete="new-password">
                    <label>Confirmar senha</label>
                    <button type="button" class="password-toggle" data-toggle-password="confirmar_senha" aria-label="Mostrar confirmação de senha" aria-pressed="false">
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

                <button type="submit" class="btn-login">Cadastrar</button>

                <p class="cadastro-link">
                    Já tem conta?
                    <a href="/index.php">Entrar</a>
                </p>

            </form>
        </div>

    </div>

    <!-- Modal sucesso -->
    <div class="modal-overlay" id="modalSucesso" aria-hidden="true">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <div class="modal-head">
                <div class="modal-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="modal-titles">
                    <h2 id="modalTitle">Cadastro realizado com sucesso!</h2>
                    <p>Sua conta foi criada. Clique em <b>OK</b> para voltar ao login.</p>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-modal-ok" id="btnOkCadastro">OK, voltar pro login →</button>
            </div>
        </div>
    </div>

    <?php render_whatsapp_float(); ?>

    <script src="/js/cadastro.js"></script>
</body>
</html>
