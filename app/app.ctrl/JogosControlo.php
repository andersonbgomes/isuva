<?php
/*
O CATÁLOGO: os jogos que o administrador pôs no site.

    /jogos                  todos
    /jogos?consola=psx      só os de uma consola
    /jogos?q=crash          pesquisa pelo título

Os filtros chegam por GET e por isso são validados aqui: a consola só
vale se for uma das chaves de Consolas::LISTA, e a pesquisa vai por
parâmetro (o DB_GLOBAL faz o bindValue), nunca colada à SQL.
*/
class JogosControlo extends Acao {

	const MAX_PESQUISA = 100;

	public function index() {
		$consola  = (string)($_GET['consola'] ?? '');
		$pesquisa = trim(mb_substr((string)($_GET['q'] ?? ''), 0, self::MAX_PESQUISA));

		$filtro = ['stto_jg = ' => 1];
		if(Consolas::existe($consola)){
			$filtro['AND consola_jg = '] = $consola;
		} else {
			$consola = '';
		}
		if($pesquisa !== ''){
			//o % e o _ da pessoa são texto, não curingas do LIKE
			$filtro['AND titulo_jg LIKE '] = '%'.addcslashes($pesquisa, '%_\\').'%';
		}

		$j = new Jogo;
		$j->__add('dados', $filtro);
		$j->__add('ordem', '`titulo_jg` ASC');
		$j->pegar_todos();

		$this->ver->jogos    = $j->fetchall ?: [];
		$this->ver->consola  = $consola;
		$this->ver->pesquisa = $pesquisa;

		$this->renderizar('catalogo');
	}
}
