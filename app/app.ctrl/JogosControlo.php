<?php
/*
O CATÁLOGO, e a tela de cada jogo.

    /jogos                       a página inicial: secções (continuar a
                                 jogar, novidades, uma por família)
    /jogos?familia=Sega          só uma família
    /jogos?consola=psx           só uma consola
    /jogos?q=crash               pesquisa pelo título
    /jogos/ver/7                 a tela do jogo 7: capa, descrição, jogar,
                                 controlos, gravações

Os filtros chegam por GET e por isso são validados aqui: a família e a
consola só valem se existirem em Consolas::LISTA, e a pesquisa vai por
parâmetro (o DB_GLOBAL faz o bindValue), nunca colada à SQL.

UMA CONSULTA SÓ PARA A PÁGINA INICIAL. Os jogos visíveis vêm todos de
uma vez e as secções repartem-se em PHP: um catálogo destes tem dezenas
ou centenas de jogos, não milhões, e uma consulta por secção era meia
dúzia de idas à base de dados para o mesmo resultado.
*/
class JogosControlo extends Acao {

	const MAX_PESQUISA = 100;
	const POR_SECCAO   = 8;

	public function index() {
		$consola  = (string)($_GET['consola'] ?? '');
		$familia  = (string)($_GET['familia'] ?? '');
		$pesquisa = trim(mb_substr((string)($_GET['q'] ?? ''), 0, self::MAX_PESQUISA));

		$familias = array_keys(Consolas::porFamilia());
		if(!Consolas::existe($consola)){ $consola = ''; }
		if(!in_array($familia, $familias, true)){ $familia = ''; }

		$this->ver->consola  = $consola;
		$this->ver->familia  = $familia;
		$this->ver->pesquisa = $pesquisa;
		$this->ver->familias = $familias;
		$this->ver->semTitulo = true;

		//com filtro: uma grelha só, com os resultados
		if($consola !== '' || $familia !== '' || $pesquisa !== ''){
			$filtro = ['stto_jg = ' => 1];
			if($consola !== ''){
				$filtro['AND consola_jg = '] = $consola;
			} elseif($familia !== ''){
				$filtro['AND consola_jg IN '] = array_keys(Consolas::porFamilia()[$familia]);
			}
			if($pesquisa !== ''){
				//o % e o _ da pessoa são texto, não curingas do LIKE
				$filtro['AND titulo_jg LIKE '] = '%'.addcslashes($pesquisa, '%_\\').'%';
			}

			$j = new Jogo;
			$j->__add('dados', $filtro);
			$j->__add('ordem', '`titulo_jg` ASC');
			$j->pegar_todos();

			$this->ver->resultados = $j->fetchall ?: [];
			$this->renderizar('catalogo');
			return;
		}

		//sem filtro: as secções
		$j = new Jogo;
		$j->__add('dados', ['stto_jg = ' => 1]);
		$j->__add('ordem', '`dtc_jg` DESC, `id_jg` DESC');
		$j->pegar_todos();
		$todos = $j->fetchall ?: [];

		$porFamilia = [];
		foreach ($todos as $jogo) {
			$c = Consolas::pegar($jogo['consola_jg']);
			if($c){ $porFamilia[$c['familia']][] = $jogo; }
		}
		foreach ($porFamilia as &$lista) {
			usort($lista, function ($a, $b) { return strcasecmp($a['titulo_jg'], $b['titulo_jg']); });
		}
		unset($lista);

		$this->ver->continuar  = $this->continuarAJogar($todos);
		$this->ver->novidades  = array_slice($todos, 0, 4);
		$this->ver->porFamilia = $porFamilia;
		$this->ver->total      = count($todos);
		$this->renderizar('catalogo');
	}

	public function ver() {
		$j = new Jogo;
		$j->__add('dados', ['id_jg = ' => (int)id]);
		$j->pegar();
		$jogo = $j->fetch ?: [];
		if(!$jogo || (!$this->e_admin() && (int)$jogo['stto_jg'] !== 1)){
			$this->voltar('warning', 'Não encontrado', 'Esse jogo não existe ou já não está disponível.', 'jogos');
		}

		$consola = Consolas::pegar($jogo['consola_jg']);
		if($consola === null){
			$this->voltar('danger', 'Consola desconhecida', 'Este jogo é de uma consola que o site já não suporta.', 'jogos');
		}

		//mais jogos da mesma consola, para a fila de baixo
		$m = new Jogo;
		$m->__add('dados', ['stto_jg = ' => 1, 'AND consola_jg = ' => $jogo['consola_jg'], 'AND id_jg <> ' => (int)$jogo['id_jg']]);
		$m->__add('ordem', '`titulo_jg` ASC');
		$m->pegar_todos();

		$this->ver->jogo      = $jogo;
		$this->ver->consola   = $consola;
		$this->ver->bios      = Bios::de($jogo['consola_jg']);
		$this->ver->gravacoes = $this->gravacoesDoJogo($jogo);
		$this->ver->mais      = array_slice($m->fetchall ?: [], 0, 4);
		$this->ver->conta     = $this->tem_conta();
		$this->ver->google    = Conta::googleLigado();
		$this->ver->semTitulo = true;
		$this->renderizar('ver');
	}


	//=============================================================
	// Ajudantes
	//=============================================================

	/*
	Os jogos em que esta conta mexeu por último -- pelas gravações (as
	consolas de browser) e pelas sessões no servidor (a PS2, cujo cartão
	é da consola e não do jogo). Duas consultas pequenas e o resto em PHP.
	*/
	private function continuarAJogar($todos) {
		$eu = utilizador_actual();
		if(!$eu){ return []; }   //um visitante não jogou nada que o site saiba
		$quando = [];

		$stt = Con::ecta()->prepare(
			"SELECT `jogo_gv` AS jogo, MAX(`actualizado_gv`) AS quando FROM `app_gravacao`
			  WHERE `us_gv` = ? AND `jogo_gv` > 0 GROUP BY `jogo_gv`"
		);
		$stt->execute([$eu]);
		foreach ($stt->fetchAll(PDO::FETCH_ASSOC) as $l) { $quando[(int)$l['jogo']] = $l['quando']; }

		$stt = Con::ecta()->prepare(
			"SELECT `jogo_ss` AS jogo, MAX(`dtc_ss`) AS quando FROM `app_sessao`
			  WHERE `us_ss` = ? AND `estado_ss` IN ('a_jogar', 'terminada') GROUP BY `jogo_ss`"
		);
		$stt->execute([$eu]);
		foreach ($stt->fetchAll(PDO::FETCH_ASSOC) as $l) {
			$k = (int)$l['jogo'];
			if(!isset($quando[$k]) || $l['quando'] > $quando[$k]){ $quando[$k] = $l['quando']; }
		}

		arsort($quando);
		$porId = [];
		foreach ($todos as $jogo) { $porId[(int)$jogo['id_jg']] = $jogo; }

		$r = [];
		foreach (array_keys($quando) as $id) {
			if(isset($porId[$id])){ $r[] = $porId[$id]; }
			if(count($r) === 4){ break; }
		}
		return $r;
	}

	private function gravacoesDoJogo($jogo) {
		if(!$this->tem_conta()){ return null; }   //a vista mostra o convite para criar conta

		if(Consolas::noServidor($jogo['consola_jg'])){
			$k = Gravacoes::chave('cartao', [], $jogo['consola_jg'], 0);
			$cartao = is_array($k) ? Gravacoes::pegar(utilizador_actual(), $k) : [];
			return ['cartao' => $cartao];
		}

		$g = new Gravacao;
		$g->__add('dados', ['us_gv = ' => utilizador_actual(), 'AND jogo_gv = ' => (int)$jogo['id_jg']]);
		$g->__add('ordem', '`tipo_gv` ASC, `slot_gv` ASC');
		$g->pegar_todos();
		return ['lista' => $g->fetchall ?: []];
	}
}
