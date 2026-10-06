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
