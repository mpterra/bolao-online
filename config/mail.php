<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONFIGURAÇÃO DE E-MAIL (SMTP - Titan)
|--------------------------------------------------------------------------
| Este arquivo NÃO é versionado no Git (listado no .gitignore).
| Copie como config/mail.php no servidor e preencha as credenciais.
|--------------------------------------------------------------------------
*/

return [
    "host"       => "mail.bolaodothiago.com.br",
    "port"       => 465,
    "encryption" => "ssl",           // tls | ssl | none
    "username"   => "admin@bolaodothiago.com.br",
    "password"   => "Eng%3571Hawaii",
    "from_email" => "admin@bolaodothiago.com.br",
    "from_name"  => "Bolão do Thiago",
    "timeout"    => 20,
];
