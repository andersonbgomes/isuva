<?php
/*
A CLASSE BASE DE TODOS OS CONTROLADORES.

Faz três coisas, e só três:

    1. exige sessão iniciada (menos onde não pode exigir);
    2. põe o utilizador da sessão ao alcance das vistas;
    3. desenha uma vista, com ou sem a moldura do tema.

Tudo o que for do domínio do projecto -- permissões, empresas,
subscrições, notificações -- NÃO vem de fábrica. Acrescenta-se aqui, a
esta classe, quando o projecto precisar: é o sítio certo, porque todas as
páginas passam pelo construtor e assim não há vinte controladores onde
alguém se possa esquecer de um.

O QUE CADA CONTROLADOR RECEBE

    $this->ver           o saco de dados que vai para a vista
    $this->ver->usuario  a linha do utilizador com sessão aberta

E o que cada controlador usa:

    $this->renderizar('inicio')        a vista, dentro da moldura do tema
    $this->renderizar_solto('entrar')  a vista sozinha (ecrã de entrada, PDF)
    $this->aviso('success', ...)       a mensagem que aparece na página seguinte
*/
abstract class Acao {

	protected $ver;

	/*
	Os controladores que NÃO exigem sessão.

	Quem vem entrar ainda não tem sessão nenhuma: pedi-la ao AuthControlo
	era uma porta fechada por dentro. Qualquer outra página pública
	(confirmar um documento por QR code, um webhook de pagamento)
	acrescenta-se a esta lista -- e a cada uma que se acrescente deve
	corresponder uma protecção própria, escrita no controlador.
	*/
	const SEM_SESSAO = ['AuthControlo'];

	public function __construct() {
		$this->ver = new stdClass();

		if(!in_array(get_class($this), self::SEM_SESSAO, true)){
			nlog();
		}

		if(isset($_SESSION['us_id'])){
			$usuario = new Usuario;
			$usuario->__add('dados', ['id_us = ' => $_SESSION['us_id']]);
			$usuario->pegar();

			/*
			`?: []` e não `?? []`: um utilizador sem linha na tabela traz
			aqui `false`, que é o que o fetch() do PDO devolve quando não
			há linha -- e não null. Sem isto, qualquer vista que fizesse
			$this->ver->usuario['nome_us'] contava com o PHP converter o
			false num array sozinho: funciona hoje, avisa amanhã e é erro
			depois.
			*/
			$this->ver->usuario = $usuario->fetch ?: [];

			/*
			A SESSÃO DE UM UTILIZADOR QUE JÁ NÃO EXISTE (ou foi desligado).

			Apagar a conta, ou pô-la inactiva, não fecha a sessão de quem
			já estava dentro -- ele continuaria a navegar até fechar o
			browser. Aqui é o único sítio por onde todas as páginas
			passam, e por isso é aqui que se repara nisso.
			*/
			if(empty($this->ver->usuario) || (int)($this->ver->usuario['stto_us'] ?? 0) !== 1){
				session_destroy();
				header('Location:'.url_base('auth'));
				exit;
			}
		}
	}


	//=============================================================
	// Mensagens
	//=============================================================

	/*
	A mensagem que aparece na PÁGINA SEGUINTE.

	Guarda-se na sessão e é o tema que a mostra e a apaga (ver
	extras/content.phtml). É assim porque quem grava alguma coisa
	redirecciona a seguir -- gravar e desenhar na mesma resposta deixa o
	F5 a regravar tudo outra vez.

	    $tipo   success | danger | warning | info
	*/
	protected function aviso($tipo, $titulo, $texto) {
		$_SESSION['tp']  = $tipo;
		$_SESSION['tt']  = $titulo;
		$_SESSION['txt'] = $texto;
	}

	//a mensagem e o redireccionamento, que andam quase sempre juntos
	protected function voltar($tipo, $titulo, $texto, $destino = null) {
		$this->aviso($tipo, $titulo, $texto);
		header('Location:'.url_base($destino ?? rota));
		exit;
	}


	//=============================================================
	// Desenhar
	//=============================================================

	/*
	A vista, dentro da moldura do tema.

	O nome que se passa é o do ficheiro .phtml, sem extensão, dentro da
	pasta do controlador:

	    IndexControlo::index() -> renderizar('inicio')
	                           -> tema/<tema>/index/inicio.phtml
	*/
	protected function renderizar($v) {
		$this->ver->pcrtina   = $v;
		$this->ver->temaAtual = tema;

		require_once (_C_ . _P_ . 'tema' . _P_ . tema . _P_ . 'index.phtml');
	}

	/*
	A vista SOZINHA, sem a moldura.

	Para páginas que não são "mais uma página da aplicação": o ecrã de
	entrada, um documento para imprimir, uma página pública. Aí é a
	própria vista que escreve o <html>, o <head> e o <body> -- não há
	moldura a fazê-lo --, e o $this->ver->semMoldura diz-lhe isso.
	*/
	protected function renderizar_solto($v) {
		$this->ver->pcrtina    = $v;
		$this->ver->temaAtual  = tema;
		$this->ver->viwtema    = tema;
		$this->ver->semMoldura = true;

		$pasta = strtolower(str_replace('Controlo', '', get_class($this)));
		$caminho = _C_ . _P_ . 'tema' . _P_ . tema . _P_ . $pasta . _P_ . $v . '.phtml';

		if(file_exists($caminho)){
			require_once $caminho;
			return;
		}

		//uma vista que não existe é um erro de quem programa, não do
		//utilizador: diz-se qual é, em vez de uma página em branco
		_pagina_erro('Vista em falta', 'Não existe o ficheiro tema/'.tema.'/'.$pasta.'/'.$v.'.phtml');
	}

	/*
	A moldura: o que o tema/<tema>/index.phtml chama.

	Monta a página por pedaços -- cabeçalho, barra de cima, menu,
	conteúdo, a vista, rodapé -- e no meio deles mete a vista pedida. É
	aqui, e não na vista, que se decide o que é igual em todas as
	páginas.

	A pasta da vista sai do nome da classe: IndexControlo -> index/.
	Convenção, e não configuração: um controlador novo só tem de criar a
	sua pasta no tema com o mesmo nome, em minúsculas.
	*/
	protected function conteudo() {
		$t = isset($this->ver->temaAtual) ? $this->ver->temaAtual : tema;
		$this->ver->viwtema = $t;

		$pasta = strtolower(str_replace('Controlo', '', get_class($this)));
		$vista = _C_ . _P_ . 'tema' . _P_ . $t . _P_ . $pasta . _P_ . $this->ver->pcrtina . '.phtml';

		if(!file_exists($vista)){
			_pagina_erro('Vista em falta', 'Não existe o ficheiro tema/'.$t.'/'.$pasta.'/'.$this->ver->pcrtina.'.phtml');
		}

		include _C_ . _P_ . 'tema' . _P_ . $t . _P_ . 'extras' . _P_ . 'cima.phtml';
		include _C_ . _P_ . 'tema' . _P_ . $t . _P_ . 'extras' . _P_ . 'topbar.phtml';
		include _C_ . _P_ . 'tema' . _P_ . $t . _P_ . 'extras' . _P_ . 'leftsidebar.phtml';
		include _C_ . _P_ . 'tema' . _P_ . $t . _P_ . 'extras' . _P_ . 'content.phtml';

		require_once $vista;

		include _C_ . _P_ . 'tema' . _P_ . $t . _P_ . 'extras' . _P_ . 'footer.phtml';
		include _C_ . _P_ . 'tema' . _P_ . $t . _P_ . 'extras' . _P_ . 'baixo.phtml';
	}
}
