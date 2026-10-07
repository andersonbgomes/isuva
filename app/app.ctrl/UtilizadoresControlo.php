<?php
/*
AS CONTAS DOS JOGADORES -- só para o administrador.

    /utilizadores               a lista
    /utilizadores/novo          o formulário de uma conta nova
    /utilizadores/guardar       (POST)
    /utilizadores/editar/3      o formulário de edição
    /utilizadores/actualizar/3  (POST)
    /utilizadores/google        as credenciais do "Entrar com o Google"
    /utilizadores/guardargoogle (POST)

As pessoas também criam as suas próprias contas (RegistoControlo) e
entram com o Google (AuthControlo): esta página é para o resto --
desactivar, dar acesso de administrador, trocar uma palavra-passe.

Não há "apagar": desactivar (stto_us = 0) fecha a porta e guarda o
histórico e as gravações. Uma conta desactivada sai na página seguinte
(ver Acao::__construct), e volta a ter tudo se for activada.

AS DUAS PORTAS QUE NÃO SE PODEM FECHAR POR ENGANO: quem está a editar não
se pode desactivar a si próprio, nem tirar-se de administrador. Sem isto
bastava um clique para o site ficar sem ninguém que o administre -- e
voltar a ter um só mexendo na base de dados à mão.
*/
class UtilizadoresControlo extends Acao {

	const MIN_SENHA = 8;
	const MAX_NOME  = 150;

	public function index() {
		$this->so_admin();
		$u = new Usuario;
		$u->__add('dados', ['id_us > ' => 0]);
		$u->__add('ordem', '`nome_us` ASC');
		$u->pegar_todos();
		$this->ver->contas = $u->fetchall ?: [];
		$this->renderizar('lista');
	}

	public function novo() {
		$this->so_admin();
		$this->ver->conta = ['nome_us' => '', 'email_us' => '', 'nivl_us' => 2, 'stto_us' => 1];
		$this->ver->novo = true;
		$this->renderizar('form');
	}

	public function guardar() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'utilizadores');
		}

		$campos = $this->validar(null);
		if(is_string($campos)){
			$this->voltar('warning', 'Falta corrigir', $campos, 'utilizadores/novo');
		}

		$u = new Usuario;
		$u->__add('dados', $campos + ['dtc_us' => date('Y-m-d H:i:s')]);
		$u->inserir();
		if(!$u->result){
			_registar_erro('Contas', (string)$u->sms);
			$this->voltar('danger', 'Erro', 'Não foi possível criar a conta. O motivo ficou no registo de erros.', 'utilizadores/novo');
		}
		$this->voltar('success', 'Conta criada', 'Dê a '.$campos['nome_us'].' o e-mail e a palavra-passe para entrar.', 'utilizadores');
	}

	public function editar() {
		$this->so_admin();
		$conta = $this->conta(id);
		if(!$conta){
			$this->voltar('warning', 'Contas', 'Essa conta não existe.', 'utilizadores');
		}
		$this->ver->conta = $conta;
		$this->ver->novo = false;
		$this->renderizar('form');
	}

	public function actualizar() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'utilizadores');
		}
		$conta = $this->conta(id);
		if(!$conta){
			$this->voltar('warning', 'Contas', 'Essa conta não existe.', 'utilizadores');
		}
		$volta = 'utilizadores/editar/'.(int)$conta['id_us'];

		$campos = $this->validar($conta);
		if(is_string($campos)){
			$this->voltar('warning', 'Falta corrigir', $campos, $volta);
		}

		$u = new Usuario;
		$u->__add('dados', $campos);
		$u->__add('onde', ['id_us = ' => (int)$conta['id_us']]);
		$u->actualizar();
		if(!$u->result){
			_registar_erro('Contas', (string)$u->sms);
			$this->voltar('danger', 'Erro', 'Não foi possível gravar. O motivo ficou no registo de erros.', $volta);
		}
		$this->voltar('success', 'Gravado', 'A conta de '.$campos['nome_us'].' foi actualizada.', 'utilizadores');
	}


	//=============================================================
	// Entrar com o Google
	//=============================================================

	/*
	As credenciais do "Entrar com o Google": o ID de cliente e o segredo,
	tirados da consola do Google Cloud (o guia "Contas de jogadores"
	explica onde). Ficam na app_config.

	O segredo NUNCA volta ao ecrã: o formulário só diz se já há um, e um
	campo vazio ao gravar quer dizer "fica o que está". Mostrá-lo era
	deixá-lo em qualquer ecrã partilhado, captura ou histórico do browser.
	*/
	public function google() {
		$this->so_admin();
		$this->ver->googleId     = (string)configura('google_id');
		$this->ver->temSegredo   = trim((string)configura('google_segredo')) !== '';
		$this->ver->googleVolta  = Conta::googleVolta();
		$this->renderizar('google');
	}

	public function guardargoogle() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'utilizadores/google');
		}

		$id      = trim((string)($_POST['google_id'] ?? ''));
		$segredo = trim((string)($_POST['google_segredo'] ?? ''));
		$desligar = !empty($_POST['desligar']);

		if($desligar){
			$id = '';
			$segredo = '';
		} else {
			if(!preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $id)){
				$this->voltar('warning', 'Google', 'O ID de cliente tem a forma 1234567890-abc...apps.googleusercontent.com. Copie-o da consola do Google.', 'utilizadores/google');
			}
			if($segredo === ''){
				$segredo = (string)configura('google_segredo');   //fica o que está
			}
			if(!preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $segredo)){
				$this->voltar('warning', 'Google', 'Falta o segredo do cliente (copie-o da consola do Google).', 'utilizadores/google');
			}
		}

		$stt = Con::ecta()->prepare(
			"INSERT INTO `app_config` (`conf_chave`, `conf_valor`) VALUES (?, ?)
			 ON DUPLICATE KEY UPDATE `conf_valor` = VALUES(`conf_valor`)"
		);
		$stt->execute(['google_id', $id]);
		$stt->execute(['google_segredo', $segredo]);

		$this->voltar('success', 'Google', $desligar ? 'A entrada com o Google foi desligada.' : 'Gravado. O botão "Continuar com o Google" já aparece na entrada.', 'utilizadores/google');
	}


	//=============================================================
	// Ajudantes
	//=============================================================

	private function conta($id) {
		$u = new Usuario;
		$u->__add('dados', ['id_us = ' => (int)$id]);
		$u->pegar();
		return $u->fetch ?: [];
	}

	/*
	Os campos validados, ou a primeira queixa. $conta é a linha a editar
	(null numa conta nova): na edição a palavra-passe é opcional -- vazia
	quer dizer "fica a que está".
	*/
	private function validar($conta) {
		$nome   = trim((string)($_POST['nome'] ?? ''));
		$email  = mb_strtolower(trim((string)($_POST['email'] ?? '')));
		$senha  = (string)($_POST['senha'] ?? '');
		$admin  = !empty($_POST['admin']);
		$activa = !empty($_POST['activa']);

		if($nome === '' || mb_strlen($nome) > self::MAX_NOME){
			return 'O nome é obrigatório (no máximo '.self::MAX_NOME.' caracteres).';
		}
		if(!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190){
			return 'Escreva um e-mail válido.';
		}

		//o e-mail é a chave da entrada: dois iguais e um deles nunca mais entrava
		$outro = new Usuario;
		$outro->__add('dados', ['email_us = ' => $email]);
		$outro->pegar();
		if($outro->fetch && (int)$outro->fetch['id_us'] !== (int)($conta['id_us'] ?? 0)){
			return 'Já existe uma conta com esse e-mail.';
		}

		if($conta === null || $senha !== ''){
			if(mb_strlen($senha) < self::MIN_SENHA){
				return 'A palavra-passe tem de ter pelo menos '.self::MIN_SENHA.' caracteres.';
			}
		}

		//a própria conta: nem se desactiva nem deixa de ser administrador
		if($conta !== null && (int)$conta['id_us'] === utilizador_actual() && (!$admin || !$activa)){
			return 'Não pode desactivar a sua própria conta, nem tirar-se de administrador.';
		}

		$campos = [
			'nome_us'  => $nome,
			'email_us' => $email,
			'nivl_us'  => $admin ? 1 : 2,
			'stto_us'  => $activa ? 1 : 0,
		];
		if($senha !== ''){
			//password_hash, sempre: nunca md5/sha1 (ver o CLAUDE.md)
			$campos['pss_us'] = password_hash($senha, PASSWORD_DEFAULT);
		}
		return $campos;
	}
}
