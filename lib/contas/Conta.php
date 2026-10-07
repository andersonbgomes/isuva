<?php
/*
AS CONTAS: abrir a sessão, criar uma conta, e entrar com o Google.

Num sítio só porque são três portas para o mesmo sítio -- a entrada com
palavra-passe (AuthControlo::entrar), a conta nova (RegistoControlo) e o
Google (AuthControlo::googlevolta) -- e as três têm de abrir a sessão
exactamente da mesma maneira.

ENTRAR COM O GOOGLE (OpenID Connect, sem biblioteca nenhuma)

    1. urlGoogle()   manda o browser ao Google, com um "state" e um
                     "nonce" aleatórios guardados na sessão;
    2. o Google devolve o browser a auth/googlevolta com um "code";
    3. trocarCodigo() troca esse code pelo id_token, num pedido DIRECTO
                     do servidor ao Google (com o segredo);
    4. lerIdToken()  confirma que o token é para ESTE site, do Google,
                     válido, com o nonce certo e com o e-mail verificado.

A assinatura do id_token não é verificada, e é de propósito: o token
chega num pedido HTTPS feito pelo próprio servidor ao endereço do Google,
e a especificação do OpenID Connect (3.1.3.7) diz que nesse caso a
verificação TLS substitui a da assinatura. Verificar a assinatura obrigava
a ir buscar e guardar as chaves do Google -- mais código, e nenhuma
segurança a mais neste caminho.
*/
class Conta {

	const GOOGLE_AUTORIZAR = 'https://accounts.google.com/o/oauth2/v2/auth';
	const GOOGLE_TOKEN     = 'https://oauth2.googleapis.com/token';

	//------------------------------------------------------------------
	// A sessão
	//------------------------------------------------------------------

	/*
	Abre a sessão desta conta. O identificador da sessão muda (contra a
	fixação de sessão), e o caminho para onde voltar sai da sessão antiga.
	*/
	public static function abrirSessao($conta) {
		$volta = $_SESSION['volta'] ?? '';
		session_regenerate_id(true);
		unset($_SESSION['tentativas'], $_SESSION['volta'], $_SESSION['google']);

		$_SESSION['us_id']  = $conta['id_us'];
		$_SESSION['us_em']  = $conta['email_us'];
		$_SESSION['us_nvl'] = $conta['nivl_us'];

		header('Location:'.url_base(volta_segura($volta)));
		exit;
	}

	public static function porEmail($email) {
		$u = new Usuario;
		$u->__add('dados', ['email_us = ' => mb_strtolower(trim((string)$email))]);
		$u->pegar();
		return $u->fetch ?: [];
	}

	/*
	Cria uma conta de jogador. $hash é o password_hash() da palavra-passe,
	ou null numa conta que nasce do Google (fica com uma palavra-passe
	aleatória que ninguém sabe: só entra pelo Google).

	Devolve a linha da conta, ou uma string com o motivo.
	*/
	/*
	O resumo do IP de quem faz o pedido. HMAC com uma chave do próprio site
	(guardada na app_config na primeira vez): um sha256 simples de um IP
	desfazia-se num minuto, porque há só 4 mil milhões de IPv4.
	*/
	public static function resumoIp() {
		static $chave = null;
		if($chave === null){
			$chave = (string)configura('chave_ip');
		}
		if($chave === ''){
			/*
			A primeira vez: gera-se, grava-se, e LÊ-SE DE VOLTA o que ficou.
			Dois pedidos em simultâneo podem gerar as duas; o INSERT IGNORE
			deixa ficar só a primeira, e é essa que os dois têm de usar --
			senão a mesma origem dava dois resumos e o limite falhava (foi
			o que o teste apanhou, com a chave gerada duas vezes).
			*/
			$stt = Con::ecta()->prepare("INSERT IGNORE INTO `app_config` (`conf_chave`, `conf_valor`) VALUES ('chave_ip', ?)");
			$stt->execute([bin2hex(random_bytes(32))]);
			$chave = (string)Con::ecta()->query("SELECT `conf_valor` FROM `app_config` WHERE `conf_chave` = 'chave_ip'")->fetchColumn();
		}
		return hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''), $chave);
	}

	//quantas contas nasceram deste IP na última hora
	public static function criadasDaquiNaUltimaHora() {
		$stt = Con::ecta()->prepare("SELECT COUNT(*) FROM `app_utilizador` WHERE `ip_us` = ? AND `dtc_us` > ?");
		$stt->execute([self::resumoIp(), date('Y-m-d H:i:s', time() - 3600)]);
		return (int)$stt->fetchColumn();
	}

	public static function criar($nome, $email, $hash, $google = null) {
		$u = new Usuario;
		$u->__add('dados', [
			'nome_us'   => mb_substr(trim((string)$nome), 0, 150),
			'email_us'  => mb_strtolower(trim((string)$email)),
			'pss_us'    => $hash ?? password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
			'nivl_us'   => 2,
			'stto_us'   => 1,
			'dtc_us'    => date('Y-m-d H:i:s'),
			'google_us' => $google,
			'ip_us'     => self::resumoIp(),
		]);
		$u->inserir();
		if(!$u->result){
			_registar_erro('Contas', (string)$u->sms);
			return 'Não foi possível criar a conta. Tente outra vez daqui a pouco.';
		}

		$n = new Usuario;
		$n->__add('dados', ['id_us = ' => (int)$u->novoId]);
		$n->pegar();
		return $n->fetch ?: 'Não foi possível criar a conta.';
	}

	//------------------------------------------------------------------
	// O Google
	//------------------------------------------------------------------

	public static function googleLigado() {
		return trim((string)configura('google_id')) !== '' && trim((string)configura('google_segredo')) !== '';
	}

	//o endereço para onde o Google devolve -- tem de estar registado, tal e
	//qual, na consola do Google (o painel mostra-o para copiar)
	public static function googleVolta() {
		return url_base('auth/googlevolta');
	}

	public static function urlGoogle() {
		/*
		O "state" prova que a volta do Google é a resposta a um pedido
		feito DESTE browser (sem ele, alguém podia fazer outra pessoa
		entrar na conta dele); o "nonce" vai dentro do id_token e prova que
		o token foi pedido agora, e não reaproveitado.
		*/
		$_SESSION['google'] = [
			'state' => bin2hex(random_bytes(16)),
			'nonce' => bin2hex(random_bytes(16)),
			'desde' => time(),
		];

		return self::GOOGLE_AUTORIZAR.'?'.http_build_query([
			'client_id'     => trim((string)configura('google_id')),
			'redirect_uri'  => self::googleVolta(),
			'response_type' => 'code',
			'scope'         => 'openid email profile',
			'state'         => $_SESSION['google']['state'],
			'nonce'         => $_SESSION['google']['nonce'],
			'prompt'        => 'select_account',
		]);
	}

	//o "state" que voltou é o que foi daqui, e ainda não passaram 10 minutos?
	public static function stateValido($state) {
		$g = $_SESSION['google'] ?? null;
		return is_array($g) && is_string($state) && hash_equals($g['state'], $state)
			&& time() - (int)$g['desde'] < 600;
	}

	/*
	Troca o code pelo id_token. Devolve as "claims" do token já
	verificadas (lerIdToken), ou a mensagem do que falhou.
	*/
	public static function trocarCodigo($code) {
		if(!function_exists('curl_init')){
			return 'O servidor não tem a extensão curl do PHP.';
		}

		$ch = curl_init(self::GOOGLE_TOKEN);
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_POSTFIELDS     => http_build_query([
				'code'          => (string)$code,
				'client_id'     => trim((string)configura('google_id')),
				'client_secret' => trim((string)configura('google_segredo')),
				'redirect_uri'  => self::googleVolta(),
				'grant_type'    => 'authorization_code',
			]),
		]);
		$resposta = curl_exec($ch);
		$codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		$d = json_decode((string)$resposta, true);
		if($codigo !== 200 || !is_array($d) || empty($d['id_token'])){
			_registar_erro('Google', 'A troca do código falhou ('.$codigo.'): '.substr((string)$resposta, 0, 300));
			return 'O Google não confirmou a entrada. Tente outra vez.';
		}

		return self::lerIdToken($d['id_token']);
	}

	private static function lerIdToken($token) {
		$partes = explode('.', (string)$token);
		if(count($partes) !== 3){ return 'Resposta do Google inválida.'; }

		$c = json_decode(base64_decode(strtr($partes[1], '-_', '+/')), true);
		if(!is_array($c)){ return 'Resposta do Google inválida.'; }

		$nonce = $_SESSION['google']['nonce'] ?? '';

		if(!in_array($c['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
		   || ($c['aud'] ?? '') !== trim((string)configura('google_id'))
		   || (int)($c['exp'] ?? 0) < time()
		   || !is_string($c['nonce'] ?? null) || !hash_equals($nonce, $c['nonce'])
		   || empty($c['sub'])){
			_registar_erro('Google', 'id_token recusado: '.json_encode(array_intersect_key($c, array_flip(['iss', 'aud', 'exp']))));
			return 'O Google não confirmou a entrada. Tente outra vez.';
		}

		//um e-mail que o Google não verificou não serve para reconhecer ninguém
		if(empty($c['email']) || ($c['email_verified'] ?? false) !== true){
			return 'Essa conta Google não tem um e-mail verificado.';
		}

		return $c;
	}

	/*
	A conta para estas claims do Google: a que já entrou com este Google,
	ou a que tem o mesmo e-mail (e passa a ficar ligada), ou uma nova.

	AO LIGAR UMA CONTA QUE JÁ EXISTIA, A PALAVRA-PASSE DELA É APAGADA. As
	contas criadas no site não confirmam o e-mail -- por isso alguém podia
	criar uma conta com o e-mail de outra pessoa, esperar que ela entrasse
	com o Google, e ficar com a palavra-passe de uma conta que é dela
	(a "pré-ocupação" de contas). Depois de ligada ao Google, quem prova
	ser dono do e-mail é o Google, e a palavra-passe antiga deixa de valer.
	*/
	public static function daGoogle($c) {
		$u = new Usuario;
		$u->__add('dados', ['google_us = ' => (string)$c['sub']]);
		$u->pegar();
		if($u->fetch){ return $u->fetch; }

		$conta = self::porEmail($c['email']);
		if($conta){
			$m = new Usuario;
			$m->__add('dados', [
				'google_us' => (string)$c['sub'],
				'pss_us'    => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
			]);
			$m->__add('onde', ['id_us = ' => (int)$conta['id_us']]);
			$m->actualizar();
			if(!$m->result){
				_registar_erro('Google', (string)$m->sms);
				return 'Não foi possível ligar a conta ao Google.';
			}
			$conta['google_us'] = (string)$c['sub'];
			return $conta;
		}

		$nome = trim((string)($c['name'] ?? '')) ?: strstr((string)$c['email'], '@', true);
		return self::criar($nome, $c['email'], null, (string)$c['sub']);
	}
}
