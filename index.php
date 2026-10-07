<?php
/*
A PORTA DA APLICAÇÃO. Todos os pedidos entram por aqui.

Pela ordem em que as coisas têm de acontecer, e cada uma pela sua razão:

    1. o fuso horário        antes de qualquer date()
    2. o cookie de sessão    antes do session_start(), que depois já não se configura
    3. a rede de erros       antes de haver código que possa falhar
    4. o instalador          quando ainda não há ligação à base de dados
    5. os ajudantes          que a partir daqui estão disponíveis em todo o lado
    6. o autoload            que encontra as classes sem um require por ficheiro
    7. o router              que escolhe o controlador e chama a acção

Não mexer na ordem sem perceber porquê: cada passo conta com o anterior.
*/

/*
A HORA DO PAÍS, E NÃO A DO SERVIDOR.

Sem isto o PHP fica em UTC -- é o valor de fábrica, e é o que a maior
parte das hospedagens serve. Tudo o que a aplicação carimbar com date()
fica à hora de um sítio onde ninguém está.

Quem instalar noutro país muda esta linha para o fuso de lá.
*/
date_default_timezone_set('Africa/Luanda');

/*
O cookie de sessão antes de a sessão começar.

Sem isto ficava com os valores de fábrica do PHP: visível ao JavaScript e
enviado para qualquer sítio. Três travas, todas de graça:

    httponly   o cookie deixa de estar ao alcance do JavaScript -- um XSS
               que escape numa vista já não leva a sessão consigo;
    samesite   o browser não o manda em pedidos que venham de outro site,
               o que fecha a maior parte dos ataques de CSRF antes de o
               nosso csrf_valido() sequer ser preciso;
    secure     em HTTPS, o cookie nunca sai por HTTP (e só em HTTPS -- em
               desenvolvimento, sobre http, seria o mesmo que desligar a
               sessão).

Tem de ser ANTES do session_start(): depois de a sessão arrancar já não
há nada a configurar.
*/
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
]);
session_start();

define('_P_', DIRECTORY_SEPARATOR);
define('_C_', dirname(__FILE__));

require_once (_C_ . _P_ . 'lib' . _P_ . 'erros.php');

/*
AINDA NÃO HÁ INSTALAÇÃO? ENTÃO É O INSTALADOR QUE ABRE.

O app/Conecta.php (onde ficam os dados da base de dados) está no
.gitignore, e tem de estar -- a password de produção nunca entra no git.
O que isso quer dizer é que uma cópia acabada de descarregar NÃO o tem: o
require mais abaixo rebenta, e quem abria a aplicação levava com um erro
em vez de perceber que só lhe falta instalar.

As duas condições contam:

  - com o Conecta.php feito, isto nunca corre (uma instalação a sério nem
    passa por aqui);
  - sem a pasta instalar/ -- que se apaga no fim da instalação, e o
    próprio instalador diz para apagar -- também não: nesse caso o que
    falta mesmo é o Conecta.php, e quem o vê é a página de erro do
    lib/erros.php, que diz o que se passa.

O exit não é detalhe: um header('Location:') sem ele deixava a execução
continuar e ia rebentar na mesma três ficheiros à frente.
*/
if(!file_exists(_C_ . _P_ . 'app' . _P_ . 'Conecta.php')
   && file_exists(_C_ . _P_ . 'instalar' . _P_ . 'index.php')){
    header('Location: instalar/');
    exit;
}

require_once (_C_ . _P_ . 'lib' . _P_ . 'ajuda' . _P_ . 'aju.php');

/*
O AUTOLOAD.

Uma pasta por papel, e a classe é procurada em cada uma por ordem. Sem
namespaces de propósito: é um projecto pequeno, e a regra "o ficheiro
chama-se como a classe" é mais fácil de seguir do que qualquer mapa.

    app/           classes soltas da aplicação (Conecta)
    app/app.ctrl/  os controladores -- um por rota, <Nome>Controlo.php
    app/app.mdl/   os modelos -- um por tabela
    vdr/           o núcleo (App, Acao, DB_GLOBAL)
    lib/<pasta>/   bibliotecas próprias; acrescentar aqui ao criar uma
    lib/jogos/     as consolas, o armazém dos ficheiros e as descargas por link

Ao acrescentar uma pasta de biblioteca (lib/pdf/, lib/email/, ...),
acrescenta-se aqui uma linha igual às outras.
*/
spl_autoload_register(function ($classe) {
    foreach ([
        "app/{$classe}.php",
        "app/app.ctrl/{$classe}.php",
        "app/app.mdl/{$classe}.php",
        "vdr/{$classe}.php",
        "lib/jogos/{$classe}.php",
    ] as $ficheiro) {
        $caminho = _C_ . _P_ . str_replace('/', _P_, $ficheiro);
        if(file_exists($caminho)){
            include $caminho;
            return;
        }
    }
});

$rota = new App;
