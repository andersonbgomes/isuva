<?php
/*
OS SERVIDORES DE JOGO (NÓS) E AS SESSÕES -- só para o administrador.

    /servidores                    os nós, o estado de cada um, e quem está a jogar
    /servidores/novo               o formulário de um nó novo
    /servidores/guardar            (POST)
    /servidores/editar/3           o formulário de edição
    /servidores/actualizar/3       (POST)
    /servidores/apagar/3           (POST)
    /servidores/terminar/12        (POST) acaba a sessão 12 à força

Um nó é uma máquina com placa gráfica onde corre o agente de
no-de-jogo/. Aqui só se diz ao site onde ele está e qual é o segredo que
os dois partilham -- a instalação da máquina está no
no-de-jogo/LEIA-ME.md.

O SEGREDO É GERADO AQUI, e não escrito à mão: tem de ser longo e
aleatório, porque é a única coisa que impede um estranho de pedir sessões
ao nó (e gastar a placa gráfica) ou de descarregar os jogos do site
fingindo ser o nó.
*/
class ServidoresControlo extends Acao {

	const MAX_NOME = 100;
	const MAX_CAPACIDADE = 64;

	public function index() {
		$this->so_admin();

		$n = new No;
		$n->__add('dados', ['id_no > ' => 0]);
		$n->__add('ordem', '`nome_no` ASC');
		$n->pegar_todos();
		$nos = $n->fetchall ?: [];

		/*
		O estado de cada nó, perguntado agora ao agente. É uma ida à rede
		por nó, com tempo limite curto (Agente::TIMEOUT_LIGAR): com meia
		dúzia de nós, a página demora no máximo uns segundos mesmo com
		todos desligados.
		*/
		foreach ($nos as &$no) {
			$no['_estado'] = (int)$no['stto_no'] === 1 ? Agente::estado($no) : null;
		}
		unset($no);

		/*
		As sessões activas, com o nome de quem joga e do jogo -- uma
		consulta com JOIN, que o DB_GLOBAL não faz (lê de uma tabela só).
		*/
		$q = Con::ecta()->query(
			"SELECT s.*, u.`nome_us`, j.`titulo_jg`, n.`nome_no`
			   FROM `app_sessao` s
			   LEFT JOIN `app_utilizador` u ON u.`id_us` = s.`us_ss`
			   LEFT JOIN `app_jogo` j       ON j.`id_jg` = s.`jogo_ss`
			   LEFT JOIN `app_no` n         ON n.`id_no` = s.`no_ss`
			  WHERE s.`estado_ss` IN ('fila', 'a_preparar', 'a_jogar')
			  ORDER BY s.`id_ss` ASC"
		);

		$this->ver->nos     = $nos;
		$this->ver->sessoes = $q->fetchAll(PDO::FETCH_ASSOC);
		$this->renderizar('lista');
	}

	public function novo() {
		$this->so_admin();
		$this->ver->no = [
			'nome_no' => '', 'api_no' => 'https://', 'publico_no' => 'https://',
			'porta_no' => 8443, 'capacidade_no' => 1, 'stto_no' => 1,
			'segredo_no' => bin2hex(random_bytes(32)),
		];
		$this->ver->novo = true;
		$this->renderizar('form');
	}

	public function guardar() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'servidores');
		}

		$campos = $this->validar();
		if(is_string($campos)){
			$this->voltar('warning', 'Falta corrigir', $campos, 'servidores/novo');
		}

		$n = new No;
		$n->__add('dados', $campos + ['dtc_no' => date('Y-m-d H:i:s')]);
		$n->inserir();
		if(!$n->result){
			_registar_erro('Servidores', (string)$n->sms);
			$this->voltar('danger', 'Erro', 'Não foi possível gravar. O motivo ficou no registo de erros.', 'servidores/novo');
		}

		$this->voltar('success', 'Gravado', 'O servidor "'.$campos['nome_no'].'" foi acrescentado. Ponha o mesmo segredo na configuração do agente.', 'servidores');
	}

	public function editar() {
		$this->so_admin();
		$no = $this->no(id);
		if(!$no){
			$this->voltar('warning', 'Não encontrado', 'Esse servidor não existe.', 'servidores');
		}
		$this->ver->no = $no;
		$this->ver->novo = false;
		$this->renderizar('form');
	}

	public function actualizar() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'servidores');
		}
		$no = $this->no(id);
		if(!$no){
			$this->voltar('warning', 'Não encontrado', 'Esse servidor não existe.', 'servidores');
		}

		$campos = $this->validar();
		if(is_string($campos)){
			$this->voltar('warning', 'Falta corrigir', $campos, 'servidores/editar/'.(int)$no['id_no']);
		}

		$n = new No;
		$n->__add('dados', $campos);
		$n->__add('onde', ['id_no = ' => (int)$no['id_no']]);
		$n->actualizar();
		if(!$n->result){
			_registar_erro('Servidores', (string)$n->sms);
			$this->voltar('danger', 'Erro', 'Não foi possível gravar. O motivo ficou no registo de erros.', 'servidores/editar/'.(int)$no['id_no']);
		}

		$this->voltar('success', 'Gravado', 'As alterações foram gravadas.', 'servidores');
	}

	public function apagar() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'servidores');
		}
		$no = $this->no(id);
		if(!$no){
			$this->voltar('warning', 'Não encontrado', 'Esse servidor não existe.', 'servidores');
		}

		//quem lá está a jogar sai primeiro -- senão ficavam sessões a
		//apontar para um nó que já não existe, e lugares que nunca se libertam
		$s = new Sessao;
		$s->__add('dados', ['no_ss = ' => (int)$no['id_no'], 'AND estado_ss IN ' => Fila::ACTIVOS]);
		$s->pegar_todos();
		foreach ($s->fetchall ?: [] as $sessao) {
			Fila::terminar($sessao, 'O servidor de jogo foi removido.');
		}

		$n = new No;
		$n->__add('onde', ['id_no = ' => (int)$no['id_no']]);
		$n->apagar();
		if(!$n->result){
			_registar_erro('Servidores', (string)$n->sms);
			$this->voltar('danger', 'Erro', 'Não foi possível apagar. O motivo ficou no registo de erros.', 'servidores');
		}

		$this->voltar('success', 'Apagado', 'O servidor "'.$no['nome_no'].'" foi removido.', 'servidores');
	}

	public function terminar() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'servidores');
		}

		$s = Fila::sessao(id);
		if($s){
			Fila::terminar($s, 'Terminada pelo administrador.');
		}
		$this->voltar('success', 'Sessão', 'A sessão foi terminada.', 'servidores');
	}


	//=============================================================
	// Ajudantes
	//=============================================================

	private function no($id) {
		$n = new No;
		$n->__add('dados', ['id_no = ' => (int)$id]);
		$n->pegar();
		return $n->fetch ?: [];
	}

	/*
	Os campos do formulário, validados.

	Os dois endereços têm regras diferentes, e ambas importam:

	    api_no      esquema, máquina e porta, SEM caminho. A assinatura
	                dos pedidos cobre o caminho tal como o agente o vê
	                (/sessoes/12); um prefixo (/api) pelo meio deixava as
	                duas contas diferentes e o agente recusava tudo.
	    publico_no  esquema e máquina, sem porta nem caminho: a porta de
	                cada lugar é acrescentada pelo site (porta_no + lugar).
	                Tem de ser https -- a página do site é https, e o
	                browser não deixa uma página https embeber http.
	*/
	private function validar() {
		$nome       = trim((string)($_POST['nome'] ?? ''));
		$api        = rtrim(trim((string)($_POST['api'] ?? '')), '/');
		$publico    = rtrim(trim((string)($_POST['publico'] ?? '')), '/');
		$porta      = (int)($_POST['porta'] ?? 0);
		$capacidade = (int)($_POST['capacidade'] ?? 0);
		$segredo    = trim((string)($_POST['segredo'] ?? ''));

		if($nome === '' || mb_strlen($nome) > self::MAX_NOME){
			return 'O nome é obrigatório (no máximo '.self::MAX_NOME.' caracteres).';
		}
		if(!preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?$#', $api)){
			return 'O endereço da API tem de ser só http(s)://máquina:porta, sem nada depois (ex.: https://no1.exemplo.ao:7443).';
		}
		if(!preg_match('#^https://[A-Za-z0-9.\-]+$#', $publico)){
			return 'O endereço público tem de ser https://máquina, sem porta nem caminho (ex.: https://no1.exemplo.ao).';
		}
		if($capacidade < 1 || $capacidade > self::MAX_CAPACIDADE){
			return 'A capacidade tem de estar entre 1 e '.self::MAX_CAPACIDADE.' jogadores.';
		}
		if($porta < 1 || $porta + $capacidade - 1 > 65535){
			return 'A porta do primeiro lugar não é válida (as portas vão de '.$porta.' a '.($porta + $capacidade - 1).').';
		}
		if(!preg_match('/^[A-Za-z0-9]{32,128}$/', $segredo)){
			return 'O segredo tem de ter entre 32 e 128 letras e números.';
		}

		return [
			'nome_no'       => $nome,
			'api_no'        => $api,
			'publico_no'    => $publico,
			'porta_no'      => $porta,
			'capacidade_no' => $capacidade,
			'segredo_no'    => $segredo,
			'stto_no'       => !empty($_POST['activo']) ? 1 : 0,
		];
	}
}
