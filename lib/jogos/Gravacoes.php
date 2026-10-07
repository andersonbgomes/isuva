<?php
/*
AS GRAVAÇÕES DE CADA CONTA, GUARDADAS NO SERVIDOR.

Antes disto, o progresso de um jogo ficava no browser de quem jogava
(IndexedDB): noutro computador, noutro browser, ou depois de limpar os
dados do site, desaparecia. Agora fica aqui, por conta, e segue a pessoa.

OS TRÊS TIPOS

    sram    a gravação do PRÓPRIO JOGO -- o que o jogo grava no "cartão de
            memória" ou na pilha do cartucho. Uma por jogo. Quem a manda
            é o gravacoes.js, sozinho, de minuto a minuto e ao sair.
    estado  um estado guardado pelo botão "Guardar estado" do emulador
            (uma fotografia da consola naquele instante). Até 9 por jogo:
            são os 9 lugares do próprio EmulatorJS.
    cartao  o cartão de memória da PS2. Um por CONSOLA e não por jogo,
            porque na PS2 um cartão serve para todos os jogos. Quem o
            manda é o agente do nó de jogo (NoControlo::cartao).

O CONTEÚDO É OPACO para o site: guarda os bytes que recebe e devolve-os
tal e qual. O agente da PS2, por exemplo, comprime o cartão antes de o
mandar (8 MB de quase só zeros) -- o site nem sabe.

UMA LINHA POR GRAVAÇÃO: gravar outra vez substitui (a chave única da
tabela). O ficheiro novo entra primeiro, e o velho só sai depois de a
linha já apontar para o novo -- uma falha a meio deixa, no pior caso, um
ficheiro a mais no disco, e nunca uma gravação perdida.
*/
class Gravacoes {

	//o maior ficheiro de cada tipo. Folgados: um estado de PSP chega a dezenas de MB
	const MAX = [
		'sram'   => 16 * 1024 * 1024,
		'estado' => 128 * 1024 * 1024,
		'cartao' => 64 * 1024 * 1024,
	];

	//o espaço de cada conta, somando tudo
	const QUOTA = 1024 * 1024 * 1024;

	const SLOTS_ESTADO = 9;

	/*
	Valida o que identifica uma gravação, e devolve-o normalizado:

	    ['tipo', 'consola', 'jogo' (id ou 0), 'slot']

	ou a mensagem da recusa. $jogo é a linha do jogo (ou [] para o cartão).
	*/
	public static function chave($tipo, $jogo, $consola, $slot) {
		$slot = (int)$slot;

		switch ($tipo) {
			case 'sram':
			case 'estado':
				if(empty($jogo) || Consolas::noServidor($jogo['consola_jg'])){
					return 'Esse jogo não existe, ou não corre no browser.';
				}
				if($tipo === 'sram'){ $slot = 0; }
				if($tipo === 'estado' && ($slot < 1 || $slot > self::SLOTS_ESTADO)){
					return 'O lugar do estado tem de ser de 1 a '.self::SLOTS_ESTADO.'.';
				}
				return ['tipo' => $tipo, 'consola' => $jogo['consola_jg'], 'jogo' => (int)$jogo['id_jg'], 'slot' => $slot];

			case 'cartao':
				if(!Consolas::noServidor($consola)){
					return 'Só as consolas que correm no servidor têm cartão guardado aqui.';
				}
				return ['tipo' => 'cartao', 'consola' => $consola, 'jogo' => 0, 'slot' => 0];
		}
		return 'Tipo de gravação desconhecido.';
	}

	public static function pegar($idUtilizador, $k) {
		$g = new Gravacao;
		$g->__add('dados', [
			'us_gv = '          => (int)$idUtilizador,
			'AND tipo_gv = '    => $k['tipo'],
			'AND consola_gv = ' => $k['consola'],
			'AND jogo_gv = '    => $k['jogo'],
			'AND slot_gv = '    => $k['slot'],
		]);
		$g->pegar();
		return $g->fetch ?: [];
	}

	//o espaço que a conta já usa, sem contar a gravação que vai ser substituída
	public static function usado($idUtilizador, $menos = []) {
		$stt = Con::ecta()->prepare("SELECT COALESCE(SUM(`tamanho_gv`), 0) FROM `app_gravacao` WHERE `us_gv` = ? AND `id_gv` <> ?");
		$stt->execute([(int)$idUtilizador, (int)($menos['id_gv'] ?? 0)]);
		return (int)$stt->fetchColumn();
	}

	/*
	Cabe? Verificado ANTES de receber o ficheiro (com o tamanho anunciado)
	e outra vez ao gravar (com o tamanho real).
	*/
	public static function cabe($idUtilizador, $k, $tamanho) {
		if($tamanho <= 0 || $tamanho > self::MAX[$k['tipo']]){
			return 'A gravação tem de ter menos de '.Armazem::legivel(self::MAX[$k['tipo']]).'.';
		}
		$actual = self::pegar($idUtilizador, $k);
		if(self::usado($idUtilizador, $actual) + $tamanho > self::QUOTA){
			return 'Já não há espaço para gravações nesta conta ('.Armazem::legivel(self::QUOTA)
				.'). Apague algumas em "As minhas gravações".';
		}
		return true;
	}

	/*
	Regista um ficheiro que JÁ ESTÁ em armazem/gravacoes/ ($disco) como a
	gravação $k desta conta, substituindo a que lá estiver.

	Devolve true ou a mensagem da recusa -- e, na recusa, o ficheiro novo
	é apagado (quem chama não tem de se lembrar).
	*/
	public static function registar($idUtilizador, $k, $disco, $tamanho) {
		$cabe = self::cabe($idUtilizador, $k, $tamanho);
		if($cabe !== true){
			Armazem::apagar('gravacoes', $disco);
			return $cabe;
		}

		$anterior = self::pegar($idUtilizador, $k);

		/*
		INSERT ... ON DUPLICATE KEY UPDATE, escrito à mão (o DB_GLOBAL não o
		faz): grava a gravação exista ela ou não, numa instrução só -- duas
		gravações quase ao mesmo tempo (o minuto do gravacoes.js e o sair da
		página) nunca deixam duas linhas.
		*/
		$stt = Con::ecta()->prepare(
			"INSERT INTO `app_gravacao`
			   (`us_gv`, `tipo_gv`, `consola_gv`, `jogo_gv`, `slot_gv`, `ficheiro_gv`, `tamanho_gv`, `actualizado_gv`)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
			 ON DUPLICATE KEY UPDATE
			   `ficheiro_gv` = VALUES(`ficheiro_gv`),
			   `tamanho_gv` = VALUES(`tamanho_gv`),
			   `actualizado_gv` = VALUES(`actualizado_gv`)"
		);
		try {
			$stt->execute([(int)$idUtilizador, $k['tipo'], $k['consola'], $k['jogo'], $k['slot'], $disco, (int)$tamanho, date('Y-m-d H:i:s')]);
		} catch (Throwable $e) {
			Armazem::apagar('gravacoes', $disco);
			_registar_erro('Gravações', $e->getMessage());
			return 'Não foi possível guardar a gravação. O motivo ficou no registo de erros.';
		}

		//o ficheiro antigo só sai agora, que a linha já aponta para o novo
		if($anterior && $anterior['ficheiro_gv'] !== $disco){
			Armazem::apagar('gravacoes', $anterior['ficheiro_gv']);
		}
		return true;
	}

	public static function apagar($g) {
		$m = new Gravacao;
		$m->__add('onde', ['id_gv = ' => (int)$g['id_gv']]);
		$m->apagar();
		if($m->result){
			Armazem::apagar('gravacoes', $g['ficheiro_gv']);
		}
		return (bool)$m->result;
	}

	/*
	As gravações de uma conta, com o título do jogo -- uma consulta com
	JOIN, que o DB_GLOBAL não faz.
	*/
	public static function daConta($idUtilizador) {
		$stt = Con::ecta()->prepare(
			"SELECT g.*, j.`titulo_jg`
			   FROM `app_gravacao` g
			   LEFT JOIN `app_jogo` j ON j.`id_jg` = g.`jogo_gv`
			  WHERE g.`us_gv` = ?
			  ORDER BY g.`actualizado_gv` DESC"
		);
		$stt->execute([(int)$idUtilizador]);
		return $stt->fetchAll(PDO::FETCH_ASSOC);
	}

	//que lugares de estado este jogo já tem guardados, para o emulador saber
	public static function estadosDoJogo($idUtilizador, $idJogo) {
		$stt = Con::ecta()->prepare(
			"SELECT `slot_gv`, `actualizado_gv` FROM `app_gravacao`
			  WHERE `us_gv` = ? AND `tipo_gv` = 'estado' AND `jogo_gv` = ?"
		);
		$stt->execute([(int)$idUtilizador, (int)$idJogo]);
		$r = [];
		foreach ($stt->fetchAll(PDO::FETCH_ASSOC) as $l) {
			$r[(int)$l['slot_gv']] = $l['actualizado_gv'];
		}
		return $r;
	}
}
