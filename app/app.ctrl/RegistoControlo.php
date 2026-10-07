<?php
/*
CRIAR CONTA: qualquer pessoa, sem pedir a ninguém.

    /registo          o formulário
    /registo/criar    (POST) cria a conta e entra logo

Simples de propósito -- nome, e-mail e palavra-passe, e já está dentro.
Não há confirmação por e-mail (o site não envia e-mails); por isso uma
conta destas nunca é de confiança para mais do que jogar e gravar, e o
"Entrar com o Google" apaga a palavra-passe de uma conta que ligue (ver
Conta::daGoogle).

O QUE TRAVA OS ROBÔS, sem incomodar pessoas:
    - um campo escondido ("site") que uma pessoa nunca vê nem preenche, e
      que um robô que preenche tudo preenche;
    - no máximo 3 contas por hora a partir do mesmo IP.
      (Era por sessão, e um teste mostrou o buraco: sair da conta apaga a
      sessão, e o contador voltava a zero. O IP fica guardado só como
      resumo -- ver Conta::resumoIp.)
Não é uma muralha, é o suficiente para o lixo automático. Se um dia
deixar de chegar, o sítio a mudar é este controlador (um CAPTCHA, um
limite por IP numa tabela).
*/
class RegistoControlo extends Acao {

	const MIN_SENHA = 8;
	const MAX_NOME  = 150;
	const MAX_POR_HORA = 3;

	public function index() {
		loga();
		if(isset($_GET['volta'])){
			$_SESSION['volta'] = volta_segura($_GET['volta']);
		}
		$this->ver->google = Conta::googleLigado();
		$this->renderizar_solto('index');
	}

	public function criar() {
		loga();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'registo');
		}

		//o campo escondido: uma pessoa deixa-o vazio
		if(trim((string)($_POST['site'] ?? '')) !== ''){
			$this->voltar('danger', 'Erro', 'Não foi possível criar a conta.', 'registo');
		}

		if(Conta::criadasDaquiNaUltimaHora() >= self::MAX_POR_HORA){
			$this->voltar('warning', 'Calma', 'Já criou várias contas há pouco. Tente daqui a uma hora.', 'registo');
		}

		$nome  = trim((string)($_POST['nome'] ?? ''));
		$email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
		$senha = (string)($_POST['senha'] ?? '');

		//o que se escreveu volta ao formulário, para não ter de escrever tudo outra vez
		$_SESSION['registo_form'] = ['nome' => $nome, 'email' => $email];

		if($nome === '' || mb_strlen($nome) > self::MAX_NOME){
			$this->voltar('warning', 'Falta corrigir', 'Escreva o seu nome (no máximo '.self::MAX_NOME.' caracteres).', 'registo');
		}
		if(!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190){
			$this->voltar('warning', 'Falta corrigir', 'Escreva um e-mail válido.', 'registo');
		}
		if(mb_strlen($senha) < self::MIN_SENHA){
			$this->voltar('warning', 'Falta corrigir', 'A palavra-passe tem de ter pelo menos '.self::MIN_SENHA.' caracteres.', 'registo');
		}
		if(Conta::porEmail($email)){
			$this->voltar('warning', 'Já existe', 'Já há uma conta com esse e-mail. Entre com ela.', 'auth');
		}

		//password_hash, sempre (ver o CLAUDE.md)
		$conta = Conta::criar($nome, $email, password_hash($senha, PASSWORD_DEFAULT));
		if(is_string($conta)){
			$this->voltar('danger', 'Erro', $conta, 'registo');
		}

		unset($_SESSION['registo_form']);

		$this->aviso('success', 'Bem-vindo, '.$conta['nome_us'], 'A sua conta está criada: o que gravar fica guardado nela.');
		Conta::abrirSessao($conta);
	}
}
