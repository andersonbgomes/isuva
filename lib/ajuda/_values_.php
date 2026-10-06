<?php
/*
OS AJUDANTES GLOBAIS.

Funções soltas, disponíveis em todo o lado (controladores, modelos,
vistas). O que está aqui é o mínimo com que uma aplicação se põe de pé:
endereços, sessão, CSRF e um par de utilitários.

É aqui que o projecto vai crescer. A regra para não transformar isto num
monte: o que for do DOMÍNIO do projecto (formatar uma factura, calcular
um imposto, desenhar um crachá) vive numa biblioteca própria em lib/, com
a sua classe; aqui ficam só as funções que não pertencem a módulo nenhum.
*/


//=================================================================
// Endereços
//=================================================================

/*
O endereço absoluto de qualquer coisa desta aplicação.

TODOS os endereços da aplicação saem daqui -- menu, CSS, JavaScript,
imagens, acção dos formulários. Escrever um caminho relativo numa vista
parece funcionar na página inicial e parte-se na primeira rota com dois
níveis (/itens/editar/7 vai pedir /itens/editar/assets/...).

    url_base('')              a raiz
    url_base('clientes')      uma rota
    url_base('clientes/edt/7')
*/
function url_base($ur){
	$uri = pedido_seguro() ? 'https://' : 'http://';
	return $uri.base_desta_aplicacao().$ur;
}

/*
O host mais a pasta onde o index.php está.

A pasta conta: assim a aplicação funciona tanto em dominio.com como em
dominio.com/projecto/, sem se configurar nada.

Sem pedido nenhum (linha de comandos, tarefa agendada) não há host: aí
resta o endereço gravado nas definições.
*/
function base_desta_aplicacao(){
	$host = preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));

	if($host === ''){
		$base = preg_replace('#^https?://#i', '', (string)configura('url'));
		return rtrim($base, '/').'/';
	}

	$pasta = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
	if($pasta === '.' || $pasta === '/'){ $pasta = ''; }

	return $host.$pasta.'/';
}

/*
O pedido veio por HTTPS?

Não chega perguntar pelo $_SERVER['HTTPS']: atrás de um proxy, de um
balanceador ou da Cloudflare, o PHP vê um pedido HTTP mesmo quando o
browser falou por HTTPS. Sem isto, todos os endereços da página saíam em
http:// dentro de uma página https:// -- e o browser recusa-os.
*/
function pedido_seguro(){
	if(!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off'){ return true; }
	if(!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
	   && strtolower(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]) === 'https'){ return true; }
	if(!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on'){ return true; }
	if(!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false){ return true; }
	if(!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https'){ return true; }
	if(!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443){ return true; }
	return false;
}

/*
O endereço de um ficheiro do tema, com a data dele colada atrás.

    <link href="<?=url_versionada('tema/padrao/ext/assets/css/estilo.css')?>">

O ?v=<data> muda sempre que o ficheiro muda, e só então: o browser
guarda o CSS e o JavaScript durante dias, e sem isto uma correcção de
estilo só chegava a quem limpasse a cache à mão.
*/
function url_versionada($caminho_relativo){
	$absoluto = _C_ . _P_ . str_replace('/', _P_, ltrim($caminho_relativo, '/'));
	$versao = file_exists($absoluto) ? filemtime($absoluto) : time();
	return url_base($caminho_relativo).'?v='.$versao;
}

//"active" quando a rota do menu é a rota aberta -- para marcar o item actual
function __($v){
	return (rota == $v) ? 'active' : '';
}


//=================================================================
// Sessão
//=================================================================

/*
Sem sessão não se passa daqui.

Chamada no construtor do Acao, por todos os controladores menos os da
lista Acao::SEM_SESSAO.
*/
function nlog(){
	if(!isset($_SESSION['us_id'])){
		unset($_SESSION['us_id'], $_SESSION['us_em'], $_SESSION['us_nvl']);
		header('Location:'.url_base('auth'));
		exit;
	}
}

/*
O contrário: com sessão não se fica no ecrã de entrada.

Quem já entrou e volta a /auth é mandado para dentro, em vez de ver um
formulário de entrada que não serve para nada.
*/
function loga(){
	if(isset($_SESSION['us_id'])){
		header('Location:'.url_base(''));
		exit;
	}
}

//o utilizador com sessão aberta, ou 0
function utilizador_actual(){
	return (int)($_SESSION['us_id'] ?? 0);
}


//=================================================================
// CSRF
//=================================================================

/*
O token que prova que o formulário saiu DESTA aplicação.

Sem ele, uma página de outro site pode ter um formulário escondido que
grava, apaga ou muda alguma coisa aqui -- e o browser de quem lá passar
envia a sessão com ele, sem a pessoa dar por nada.

Usa-se em dois tempos:

    na vista        <form ...> <?=csrf_field()?> ...
    no controlador  if(!csrf_valido()){ ... }   logo no início do POST,
                    antes de tocar em qualquer dado

Todo o formulário que ALTERA alguma coisa leva os dois. Um formulário que
só pesquisa (um GET) não precisa.
*/
function csrf_token(){
	if(empty($_SESSION['csrf_token'])){
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

function csrf_field(){
	return '<input type="hidden" name="csrf_token" value="'.csrf_token().'">';
}

function csrf_valido(){
	if(!isset($_SESSION['csrf_token'])){ return false; }

	//também pelo cabeçalho, para os pedidos feitos por JavaScript (fetch)
	$enviado = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

	//hash_equals e não ==: compara em tempo constante, para a comparação
	//não dizer a quem tenta quantos caracteres acertou
	return is_string($enviado) && hash_equals($_SESSION['csrf_token'], $enviado);
}


//=================================================================
// Utilitários
//=================================================================

/*
Escapar o que vem de fora, sempre.

Nenhum valor vindo da base de dados ou do $_GET/$_POST é impresso numa
vista sem passar por aqui. É a diferença entre um nome com um < lá dentro
aparecer escrito e esse mesmo nome executar JavaScript no browser de quem
o ler.

    <td><?=esc($cliente['nome_cliente'])?></td>
*/
function esc($v){
	return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

//uma data da base de dados (Y-m-d ou Y-m-d H:i:s) como se lê em português
function data_pt($data, $comHoras = false){
	$data = trim((string)$data);
	if($data === '' || $data === '0000-00-00' || $data === '0000-00-00 00:00:00'){ return ''; }

	$t = strtotime($data);
	if($t === false){ return ''; }

	return date($comHoras ? 'd/m/Y H:i' : 'd/m/Y', $t);
}

//um número como se escreve cá: 1.234,56
function numero($n, $casas = 2){
	return number_format((float)$n, (int)$casas, ',', '.');
}

/*
Ver o que está dentro de uma variável, durante o desenvolvimento.

Fica legível e pára ali. Não deixar nenhum numa página que vá para o
servidor -- mostra a quem passar o que a aplicação tem por dentro.
*/
function dump($dados){
	echo '<pre style="background:#111;color:#0f0;padding:12px;border-radius:6px;overflow:auto">';
	print_r($dados);
	echo '</pre>';
	exit;
}
