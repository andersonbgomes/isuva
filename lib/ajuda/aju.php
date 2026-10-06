<?php
/*
O que a aplicação carrega antes de tudo, e por esta ordem.

A ordem conta: o lib/define.php chama configura() na primeira linha, por
isso a ligação e o modelo das definições têm de vir antes dele.
*/
require_once(_C_ . _P_ . 'app' . _P_ . 'Conecta.php');
require_once(_C_ . _P_ . 'app' . _P_ . 'app.mdl' . _P_ . 'configura.php');
require_once(_C_ . _P_ . 'lib' . _P_ . 'define.php');
require_once(_C_ . _P_ . 'lib' . _P_ . 'ajuda' . _P_ . '_values_.php');
require_once(_C_ . _P_ . 'lib' . _P_ . 'ajuda' . _P_ . 'head.php');
