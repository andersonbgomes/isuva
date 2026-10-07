<?php
/*
ENTRAR.

O único controlador que NÃO exige sessão (ver Acao::SEM_SESSAO) -- quem
vem entrar ainda não tem nenhuma. Em troca, é o que precisa de mais
cuidado, porque é a porta.

O QUE ESTÁ AQUI, E PORQUÊ

    password_verify()      a palavra-passe é guardada com password_hash()
                           e nunca em texto. Um md5() ou um sha1() de uma
                           palavra-passe quebra-se em segundos numa placa
                           gráfica -- não é encriptação, é um resumo.

    a mesma mensagem       "o e-mail ou a palavra-passe não estão certos",
                           nunca "esse e-mail não existe". A segunda diz a
                           quem tenta QUAIS as contas que existem, e isso
                           é meio caminho andado.

    travão de tentativas   cinco enganos seguidos e a conta espera. Sem
                           isto, uma palavra-passe fraca cai sozinha: um
                           programa experimenta milhares por minuto e
                           ninguém dá por nada.

    session_regenerate_id  o identificador da sessão muda ao entrar. Sem
                           isto, quem conseguisse fixar um identificador
                           ANTES da entrada ficava com a sessão depois
                           dela (fixação de sessão).
*/
class AuthControlo extends Acao {

	//quantos enganos seguidos, e quanto tempo espera depois disso
	const MAX_TENTATIVAS = 5;
	const MINUTOS_CASTIGO = 15;

	public function index() {
		//já entrou: não fica aqui a olhar para um formulário que não serve
		loga();

		//para onde voltar depois de entrar (o jogo que se estava a abrir)
		if(isset($_GET['volta'])){
			$_SESSION['volta'] = volta_segura($_GET['volta']);
		}

		$this->ver->bloqueado = $this->bloqueado();
		$this->ver->minutos   = self::MINUTOS_CASTIGO;
		$this->ver->google    = Conta::googleLigado();

		$this->renderizar_solto('entrar');
	}

	public function entrar() {
		loga();

		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'auth');
		}

		if($this->bloqueado()){
			$this->voltar('danger', 'Demasiadas tentativas',
				'Espere '.self::MINUTOS_CASTIGO.' minutos antes de tentar outra vez.', 'auth');
		}

		$email = trim((string)($_POST['email'] ?? ''));
		$senha = (string)($_POST['senha'] ?? '');

		if($email === '' || $senha === ''){
			$this->voltar('warning', 'Faltam dados', 'Escreva o e-mail e a palavra-passe.', 'auth');
		}

		$u = new Usuario;
		$u->__add('dados', ['email_us = ' => $email, 'AND stto_us = ' => 1]);
		$u->pegar();
		$conta = $u->fetch ?: [];

		/*
		A verificação corre MESMO quando a conta não existe, contra um
		hash de mentira. Sem isso, uma conta inexistente respondia num
		milésimo e uma existente demorava os ~100ms do password_verify --
		e essa diferença de tempo diz, sozinha, quais os e-mails
		registados, por muito igual que seja a mensagem no ecrã.
		*/
		$hash = $conta['pss_us'] ?? '$2y$10$usuarioQueNaoExisteXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';

		if(empty($conta) || !password_verify($senha, $hash)){
			$this->registarTentativa();
			$this->voltar('danger', 'Não entrou', 'O e-mail ou a palavra-passe não estão certos.', 'auth');
		}

		/*
		A palavra-passe estava certa, mas guardada com um algoritmo mais
		fraco do que o de hoje (o PHP vai subindo o custo do PASSWORD_DEFAULT).
		É a única altura em que a temos em claro: aproveita-se para a
		regravar, e a conta fica em dia sem ninguém ter de fazer nada.
		*/
		if(password_needs_rehash($hash, PASSWORD_DEFAULT)){
			$novo = new Usuario;
			$novo->__add('dados', ['pss_us' => password_hash($senha, PASSWORD_DEFAULT)]);
			$novo->__add('onde',  ['id_us = ' => $conta['id_us']]);
			$novo->actualizar();
		}

		Conta::abrirSessao($conta);
	}


	//=============================================================
	// Entrar com o Google (ver lib/contas/Conta.php)
	//=============================================================

	public function google() {
		loga();
		if(!Conta::googleLigado()){
			$this->voltar('warning', 'Google', 'A entrada com o Google ainda não está configurada.', 'auth');
		}
		if(isset($_GET['volta'])){
			$_SESSION['volta'] = volta_segura($_GET['volta']);
		}
		header('Location:'.Conta::urlGoogle());
		exit;
	}

	public function googlevolta() {
		loga();

		//quem desistiu no ecrã do Google volta com ?error=access_denied
		if(isset($_GET['error'])){
			$this->voltar('info', 'Google', 'A entrada com o Google foi cancelada.', 'auth');
		}
		if(!Conta::stateValido($_GET['state'] ?? null) || empty($_GET['code'])){
			$this->voltar('danger', 'Google', 'O pedido expirou. Carregue outra vez em "Continuar com o Google".', 'auth');
		}

		$c = Conta::trocarCodigo((string)$_GET['code']);
		if(is_string($c)){
			$this->voltar('danger', 'Google', $c, 'auth');
		}

		$conta = Conta::daGoogle($c);
		if(is_string($conta)){
			$this->voltar('danger', 'Google', $conta, 'auth');
		}
		if((int)$conta['stto_us'] !== 1){
			$this->voltar('danger', 'Conta desactivada', 'Esta conta foi desactivada pelo administrador.', 'auth');
		}

		Conta::abrirSessao($conta);
	}


	//=============================================================
	// O travão
	//=============================================================

	/*
	As tentativas ficam na SESSÃO, e não numa tabela.

	É o travão mais simples que serve de alguma coisa, e chega para um
	esqueleto: trava quem está a tentar à mão. Quem atacar a sério usa
	uma sessão nova a cada pedido e passa por aqui -- nesse dia, o travão
	passa a viver numa tabela, com o endereço IP e a hora, e esta função
	é o único sítio a mudar.
	*/
	private function bloqueado() {
		$t = $_SESSION['tentativas'] ?? null;
		if(!is_array($t) || ($t['n'] ?? 0) < self::MAX_TENTATIVAS){ return false; }

		if((time() - ($t['quando'] ?? 0)) > self::MINUTOS_CASTIGO * 60){
			unset($_SESSION['tentativas']);
			return false;
		}
		return true;
	}

	private function registarTentativa() {
		$t = $_SESSION['tentativas'] ?? ['n' => 0, 'quando' => 0];
		$_SESSION['tentativas'] = ['n' => (int)$t['n'] + 1, 'quando' => time()];
	}
}
