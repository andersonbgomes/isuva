<?php
/*
A FILA DOS JOGOS QUE CORREM NO SERVIDOR (PS2).

Um nó de jogo tem um número fixo de lugares (capacidade_no) -- cada
jogador ocupa um enquanto joga, porque cada um tem o seu PCSX2 a correr na
placa gráfica. Quando estão todos ocupados, quem chega espera na fila, e
entra pela ordem de chegada quando um lugar se libertar.

A VIDA DE UMA SESSÃO

    fila  ->  a_preparar  ->  a_jogar  ->  terminada
                    \______________\______->  erro

    fila        à espera de um lugar livre num nó
    a_preparar  o nó tem lugar e está a copiar o jogo (a primeira vez
                demora: uma ISO de 4 GB) e a arrancar o PCSX2
    a_jogar     pronto: a página do jogador mostra a imagem
    terminada   o jogador saiu, ou a página dele deixou de dar sinal

QUEM FAZ ANDAR A FILA

Não há tarefa agendada (num alojamento partilhado nem sempre há). A fila
anda sempre que alguém pergunta pelo estado da sua sessão -- e as páginas
dos jogadores perguntam de poucos em poucos segundos. Enquanto houver
alguém à espera, há quem faça a fila andar.

O LUGAR ABANDONADO

Quem fecha o separador não avisa ninguém. A página do jogo dá sinal de
poucos em poucos segundos (vivo_ss); sem sinal durante SEM_SINAL segundos,
a sessão termina e o lugar volta a estar livre. Sem isto, bastavam dois
separadores fechados para um nó de dois lugares ficar ocupado para sempre.
O próprio agente tem a mesma regra do lado dele, para o caso de o site
cair: se o site deixar de passar o sinal, o nó liberta o lugar sozinho.
*/
class Fila {

	const SEM_SINAL     = 120;   //segundos sem sinal da página até a sessão acabar
	const REPASSAR_VIVO = 60;    //de quanto em quanto tempo o sinal é passado ao nó
	const ACTIVOS       = ['fila', 'a_preparar', 'a_jogar'];

	//------------------------------------------------------------------
	// Pedir, consultar, terminar
	//------------------------------------------------------------------

	/*
	Uma sessão para este utilizador jogar este jogo.

	Uma pessoa joga um jogo de cada vez: se já tem uma sessão activa do
	MESMO jogo (recarregou a página), é essa que continua; se é de outro
	jogo, a antiga termina -- senão cada clique num jogo diferente
	ocupava mais um lugar da placa gráfica.

	Devolve a linha da sessão, ou uma string com o motivo da recusa.
	*/
	public static function pedir($idUtilizador, $jogo) {
		if(empty(Bios::de($jogo['consola_jg'])) && (Consolas::pegar($jogo['consola_jg'])['bios'] ?? '') === 'obrigatoria'){
			return 'Este jogo ainda não pode correr: falta a BIOS da '.Consolas::nome($jogo['consola_jg'])
				.'. O administrador tem de a enviar em Gerir jogos > Emulador e BIOS.';
		}

		foreach (self::activasDe($idUtilizador) as $s) {
			if((int)$s['jogo_ss'] === (int)$jogo['id_jg']){
				self::sinal($s);
				return self::sessao($s['id_ss']);
			}
			self::terminar($s, 'Começou outro jogo.');
		}

		$agora = date('Y-m-d H:i:s');
		$n = new Sessao;
		$n->__add('dados', [
			'us_ss'     => (int)$idUtilizador,
			'jogo_ss'   => (int)$jogo['id_jg'],
			'estado_ss' => 'fila',
			'token_ss'  => bin2hex(random_bytes(24)),
			'vivo_ss'   => $agora,
			'dtc_ss'    => $agora,
		]);
		$n->inserir();
		if(!$n->result){
			_registar_erro('Fila', (string)$n->sms);
			return 'Não foi possível criar a sessão. O motivo ficou no registo de erros.';
		}

		self::distribuir();
		return self::sessao($n->novoId);
	}

	/*
	O estado de uma sessão, para a página do jogador -- e o sinal de vida
	dela. É chamado de poucos em poucos segundos, e é também o que faz a
	fila andar (ver o topo).
	*/
	public static function consultar($s) {
		if(in_array($s['estado_ss'], self::ACTIVOS, true)){
			self::sinal($s);
		}

		self::distribuir();
		$s = self::sessao($s['id_ss']);

		$r = ['estado' => $s['estado_ss'], 'mensagem' => (string)$s['msg_ss']];

		if($s['estado_ss'] === 'fila'){
			$r['posicao'] = self::posicao($s);
			$r['mensagem'] = self::temNos()
				? ''
				: 'Ainda não há nenhum servidor de jogo ligado. O administrador tem de o configurar.';
		}

		if($s['estado_ss'] === 'a_preparar' || $s['estado_ss'] === 'a_jogar'){
			$no = self::no($s['no_ss']);
			if(!$no){
				self::terminar($s, 'O servidor de jogo foi removido.');
				return ['estado' => 'erro', 'mensagem' => 'O servidor de jogo foi removido.'];
			}

			if($s['estado_ss'] === 'a_preparar'){
				//array_merge e não +=: o += não substitui chaves que já existem,
				//e a 'mensagem' do nó (o motivo de um erro) perdia-se
				$r = array_merge($r, self::acompanhar($s, $no));
				$s = self::sessao($s['id_ss']);
				$r['estado'] = $s['estado_ss'];
			} else {
				self::repassarVivo($s, $no);
			}

			if($r['estado'] === 'a_jogar'){
				$r['url'] = Agente::urlEntrada($no, $s);
			}
		}

		return $r;
	}

	public static function terminar($s, $motivo = null) {
		if(!in_array($s['estado_ss'], self::ACTIVOS, true)){ return; }

		//o nó primeiro: se não responder, a sessão acaba na mesma do lado
		//do site, e o nó liberta o lugar sozinho quando deixar de ter sinal
		if(!empty($s['no_ss']) && ($no = self::no($s['no_ss']))){
			Agente::terminar($no, $s['id_ss']);
		}

		self::mudar($s['id_ss'], [
			'estado_ss' => 'terminada',
			'msg_ss'    => $motivo !== null ? mb_substr($motivo, 0, 255) : null,
			'fim_ss'    => date('Y-m-d H:i:s'),
		]);
	}

	//------------------------------------------------------------------
	// Fazer andar a fila
	//------------------------------------------------------------------

	/*
	Liberta os lugares abandonados e dá lugar a quem está na fila.

	O GET_LOCK é o que impede dois jogadores de ficarem com o MESMO lugar:
	duas páginas a perguntar pelo estado no mesmo instante corriam isto
	em paralelo, viam as duas o lugar 0 livre e mandavam as duas o nó
	usá-lo. Com o lock, uma espera pela outra. Se o lock não vier em 3
	segundos, não faz mal: alguém já está a fazer este trabalho.
	*/
	public static function distribuir() {
		$con = Con::ecta();
		$lock = $con->query("SELECT GET_LOCK('isuva_fila', 3)")->fetchColumn();
		if((int)$lock !== 1){ return; }

		try {
			self::libertarAbandonadas();

			$espera = $con->query(
				"SELECT * FROM `app_sessao` WHERE `estado_ss` = 'fila' ORDER BY `id_ss` ASC"
			)->fetchAll(PDO::FETCH_ASSOC);
			if(!$espera){ return; }

			//os lugares ocupados de cada nó activo
			$nos = [];
			foreach ($con->query("SELECT * FROM `app_no` WHERE `stto_no` = 1 ORDER BY `id_no` ASC")->fetchAll(PDO::FETCH_ASSOC) as $no) {
				$no['ocupados'] = [];
				$nos[(int)$no['id_no']] = $no;
			}
			$q = $con->query("SELECT `no_ss`, `slot_ss` FROM `app_sessao`
			                  WHERE `estado_ss` IN ('a_preparar', 'a_jogar') AND `no_ss` IS NOT NULL");
			foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
				if(isset($nos[(int)$o['no_ss']])){ $nos[(int)$o['no_ss']]['ocupados'][] = (int)$o['slot_ss']; }
			}

			foreach ($espera as $s) {
				$jogo = self::jogo($s['jogo_ss']);
				if(!$jogo || !Consolas::noServidor($jogo['consola_jg'])){
					self::mudar($s['id_ss'], ['estado_ss' => 'erro', 'msg_ss' => 'Este jogo já não está disponível.', 'fim_ss' => date('Y-m-d H:i:s')]);
					continue;
				}

				$colocada = false;
				foreach (self::porOcupacao($nos) as $idNo) {
					$no = $nos[$idNo];
					$slot = self::lugarLivre($no);
					if($slot === null){ continue; }

					$r = Agente::criarSessao($no, self::pedidoParaNo($s, $jogo, $no, $slot));
					if($r['ok']){
						self::mudar($s['id_ss'], ['estado_ss' => 'a_preparar', 'no_ss' => $idNo, 'slot_ss' => $slot, 'msg_ss' => null]);
						$nos[$idNo]['ocupados'][] = $slot;
						$colocada = true;
						break;
					}

					/*
					O nó recusou ou não respondeu: fica fora desta volta (para
					não se repetir o mesmo pedido falhado por cada jogador da
					fila) e a falha fica no registo, para o administrador ver.
					*/
					_registar_erro('Nó de jogo', 'O nó "'.$no['nome_no'].'" recusou a sessão '.$s['id_ss'].': '.$r['erro']);
					unset($nos[$idNo]);
				}

				//sem lugar para este, também não há para os que vêm atrás
				if(!$colocada){ break; }
			}
		} finally {
			$con->query("SELECT RELEASE_LOCK('isuva_fila')");
		}
	}

	private static function libertarAbandonadas() {
		$limite = date('Y-m-d H:i:s', time() - self::SEM_SINAL);
		$stt = Con::ecta()->prepare(
			"SELECT * FROM `app_sessao` WHERE `estado_ss` IN ('fila', 'a_preparar', 'a_jogar') AND `vivo_ss` < ?"
		);
		$stt->execute([$limite]);
		foreach ($stt->fetchAll(PDO::FETCH_ASSOC) as $s) {
			self::terminar($s, 'A página do jogo foi fechada.');
		}
	}

	//os nós do menos ocupado para o mais ocupado: espalha os jogadores
	private static function porOcupacao($nos) {
		uasort($nos, function ($a, $b) {
			return count($a['ocupados']) / max(1, $a['capacidade_no']) <=> count($b['ocupados']) / max(1, $b['capacidade_no']);
		});
		return array_keys($nos);
	}

	private static function lugarLivre($no) {
		for ($i = 0; $i < (int)$no['capacidade_no']; $i++) {
			if(!in_array($i, $no['ocupados'], true)){ return $i; }
		}
		return null;
	}

	/*
	O que o nó precisa de saber para arrancar a sessão. Os ficheiros vão
	como endereços assinados: o nó descarrega-os daqui (e guarda-os na
	cache dele) -- assim o nó não precisa de ver o disco do site, e pode
	estar noutra máquina, noutro país.
	*/
	private static function pedidoParaNo($s, $jogo, $no, $slot) {
		$consola = Consolas::pegar($jogo['consola_jg']);
		$bios = Bios::de($jogo['consola_jg']);

		return [
			'sessao'     => (int)$s['id_ss'],
			'slot'       => (int)$slot,
			'token'      => $s['token_ss'],
			'utilizador' => (int)$s['us_ss'],
			'nucleo'     => $consola['nucleo'],
			'site'       => rtrim(url_base(''), '/'),
			'jogo'       => [
				'id'      => (int)$jogo['id_jg'],
				'nome'    => $jogo['nome_jg'],
				'tamanho' => (int)$jogo['tamanho_jg'],
				'url'     => Agente::urlFicheiro($no, $jogo),
			],
			'bios' => $bios ? ['nome' => $bios['nome'], 'url' => Agente::urlBios($no, $jogo['consola_jg'], $bios)] : null,
			//o cartão de memória do jogador: o nó lê-o daqui e devolve-o
			//no fim, para a gravação seguir a conta para qualquer nó
			'cartao' => ['url' => Agente::urlCartao($no, $s['us_ss'], $jogo['consola_jg'])],
		];
	}

	/*
	Uma sessão a_preparar: pergunta ao nó como vai a cópia do jogo e o
	arranque, e passa-a a a_jogar (ou a erro) quando ele o disser.
	*/
	private static function acompanhar($s, $no) {
		$r = Agente::sessao($no, $s['id_ss']);

		if(!$r['ok']){
			//404: o nó não conhece a sessão (reiniciou, por exemplo)
			if($r['codigo'] === 404){
				self::mudar($s['id_ss'], ['estado_ss' => 'erro', 'msg_ss' => 'O servidor de jogo perdeu a sessão. Tente outra vez.', 'fim_ss' => date('Y-m-d H:i:s')]);
				return ['mensagem' => 'O servidor de jogo perdeu a sessão. Tente outra vez.'];
			}
			//outra falha (rede): não é razão para desistir, tenta-se na próxima pergunta
			return ['mensagem' => 'À espera de resposta do servidor de jogo...'];
		}

		$d = $r['dados'];
		switch ($d['estado'] ?? '') {
			case 'pronta':
				self::mudar($s['id_ss'], ['estado_ss' => 'a_jogar', 'msg_ss' => null, 'vivo_no_ss' => date('Y-m-d H:i:s')]);
				return [];
			case 'erro':
			case 'terminada':
				$msg = mb_substr((string)($d['mensagem'] ?? 'O servidor de jogo não conseguiu arrancar o jogo.'), 0, 255);
				self::mudar($s['id_ss'], ['estado_ss' => 'erro', 'msg_ss' => $msg, 'fim_ss' => date('Y-m-d H:i:s')]);
				return ['mensagem' => $msg];
			default:
				return [
					'progresso' => isset($d['progresso']) ? max(0, min(1, (float)$d['progresso'])) : null,
					'mensagem'  => (string)($d['mensagem'] ?? 'A preparar o jogo...'),
				];
		}
	}

	private static function repassarVivo($s, $no) {
		if(!empty($s['vivo_no_ss']) && strtotime($s['vivo_no_ss']) > time() - self::REPASSAR_VIVO){ return; }

		$r = Agente::vivo($no, $s['id_ss']);
		if($r['ok']){
			self::mudar($s['id_ss'], ['vivo_no_ss' => date('Y-m-d H:i:s')]);
		} elseif($r['codigo'] === 404){
			//o nó já acabou com ela (tempo máximo, contentor que caiu)
			self::mudar($s['id_ss'], ['estado_ss' => 'terminada', 'msg_ss' => 'A sessão terminou no servidor de jogo.', 'fim_ss' => date('Y-m-d H:i:s')]);
		}
	}

	//------------------------------------------------------------------
	// Leituras pequenas
	//------------------------------------------------------------------

	public static function sessao($id) {
		$m = new Sessao;
		$m->__add('dados', ['id_ss = ' => (int)$id]);
		$m->pegar();
		return $m->fetch ?: [];
	}

	private static function activasDe($idUtilizador) {
		$m = new Sessao;
		$m->__add('dados', ['us_ss = ' => (int)$idUtilizador, 'AND estado_ss IN ' => self::ACTIVOS]);
		$m->pegar_todos();
		return $m->fetchall ?: [];
	}

	private static function posicao($s) {
		$stt = Con::ecta()->prepare("SELECT COUNT(*) FROM `app_sessao` WHERE `estado_ss` = 'fila' AND `id_ss` <= ?");
		$stt->execute([(int)$s['id_ss']]);
		return (int)$stt->fetchColumn();
	}

	private static function temNos() {
		return (int)Con::ecta()->query("SELECT COUNT(*) FROM `app_no` WHERE `stto_no` = 1")->fetchColumn() > 0;
	}

	private static function no($id) {
		$m = new No;
		$m->__add('dados', ['id_no = ' => (int)$id]);
		$m->pegar();
		return $m->fetch ?: [];
	}

	private static function jogo($id) {
		$m = new Jogo;
		//sem filtrar o stto_jg: quem pode ver um jogo escondido (o administrador,
		//a testar) decide-se no JogarControlo, e aqui só interessa que exista
		$m->__add('dados', ['id_jg = ' => (int)$id]);
		$m->pegar();
		return $m->fetch ?: [];
	}

	private static function sinal($s) {
		self::mudar($s['id_ss'], ['vivo_ss' => date('Y-m-d H:i:s')]);
	}

	private static function mudar($id, $campos) {
		$m = new Sessao;
		$m->__add('dados', $campos);
		$m->__add('onde', ['id_ss = ' => (int)$id]);
		$m->actualizar();
		if(!$m->result){
			_registar_erro('Fila', 'Sessão '.$id.': '.$m->sms);
		}
	}
}
