<?php
/*
As constantes da aplicação.

As duas primeiras vêm da base de dados (tabela app_config) e mudam-se por
lá, sem tocar no código. As outras são do código e mudam-se aqui.
*/

//o nome do sistema e o tema activo, das definições
define('Tit',  configura('tit'));
define('tema', configura('tema') !== '' ? configura('tema') : 'padrao');

//a rota que abre quando não se pede nenhuma (o IndexControlo)
define('rota_default', 'index');

//a versão, para o rodapé. Num sítio só, para não andarem duas a divergir
define('VERSAO_APP', '1.0.0');

/*
ONDE FICAM OS FICHEIROS DOS JOGOS (ver lib/jogos/Armazem.php).

Dentro da pasta da aplicação para funcionar em qualquer alojamento sem
configurar nada, e fechada ao exterior pelo armazem/.htaccess. Num
servidor que não leia .htaccess (nginx), o mais seguro é apontar isto
para uma pasta FORA da que o servidor web publica -- por exemplo
'/home/conta/armazem' -- porque é aí que o código já espera que esteja:
tudo sai pelo JogarControlo, nada é servido directamente.
*/
define('PASTA_ARMAZEM', _C_ . _P_ . 'armazem');

/*
O maior jogo que se aceita: 2 GB.

Não é um limite do servidor, é do BROWSER. O EmulatorJS carrega o jogo
inteiro para a memória antes de arrancar, e acima de ~2 GB os browsers
começam a recusar (e os computadores fracos -- que são a razão de este
site existir -- ficam sem memória bem antes). Os jogos das consolas
suportadas cabem todos: um PS1 tem 700 MB, um PSP até 1,8 GB.
*/
define('TAMANHO_MAX_JOGO', 2 * 1024 * 1024 * 1024);

//capas e BIOS são ficheiros pequenos
define('TAMANHO_MAX_CAPA', 5 * 1024 * 1024);
define('TAMANHO_MAX_BIOS', 64 * 1024 * 1024);
