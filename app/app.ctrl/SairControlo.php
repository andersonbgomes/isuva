<?php
/*
Terminar a sessão.

Separado do AuthControlo de propósito: sair é uma rota que só faz sentido
para quem está dentro, e por isso passa pela mesma guarda de sessão que
todas as outras páginas.
*/
class SairControlo extends Acao {

	public function index() {
		/*
		Apagar o conteúdo não chega: o identificador da sessão continuava
		o mesmo, e com ele quem o tivesse apanhado voltava a entrar. Com
		session_regenerate_id() antes de destruir, o identificador antigo
		deixa de valer para alguma coisa.
		*/
		$_SESSION = [];
		session_regenerate_id(true);
		session_destroy();

		header('Location:'.url_base('auth'));
		exit;
	}
}
