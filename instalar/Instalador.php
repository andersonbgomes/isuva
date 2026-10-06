<?php
/*
O MOTOR DO INSTALADOR. Sem HTML, sem echo -- só o que sabe fazer as
coisas, para o index.php ao lado ficar a tratar só do ecrã.

PORQUE É QUE ISTO EXISTE

Sem instalador, pôr uma aplicação de pé é: criar a base de dados à mão,
importar um .sql à mão, escrever o app/Conecta.php à mão e corrigir o
`url` à mão. Quatro passos manuais, cada um com a sua armadilha
silenciosa -- um .sql importado para a base de dados errada, um
Conecta.php que esconde o erro da ligação, um `url` do domínio antigo que
deixa a aplicação sem CSS.

Nenhum desses erros é difícil de diagnosticar. São difíceis de VER. Um
instalador resolve isso de uma vez: pergunta, confirma, e diz o que está
mal antes de escrever seja o que for.
*/
class Instalador {

	const FICHEIRO_CONFIG = 'app/Conecta.php';
	const FICHEIRO_SQL    = 'instalacao/instalacao.sql';
	const FICHEIRO_TRAVA  = 'instalar/INSTALADO';

	private $raiz;

	public function __construct() {
		//a raiz da aplicação é a pasta acima desta
		$this->raiz = dirname(__DIR__);
	}

	public function caminho($relativo) {
		return $this->raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativo);
	}


	//=================================================================
	// 1. Requisitos
	//=================================================================

	/*
	O que o servidor tem de ter. Cada linha é ['nome', ok?, 'o que há'].

	Vale a pena verificar ANTES e não a meio: descobrir que falta a
	extensão pdo_mysql depois de escrever o Conecta.php e importar meio
	SQL deixa a instalação num estado que é preciso limpar à mão.
	*/
	public function requisitos() {
		$lista = [];

		$lista[] = [
			'nome' => 'PHP 7.4 ou superior',
			'ok'   => version_compare(PHP_VERSION, '7.4.0', '>='),
			'tem'  => PHP_VERSION,
		];

		foreach (['pdo_mysql' => 'ligação à base de dados',
		          'mbstring'  => 'texto com acentos'] as $ext => $paraQue) {
			$lista[] = [
				'nome' => 'Extensão '.$ext,
				'ok'   => extension_loaded($ext),
				'tem'  => extension_loaded($ext) ? 'instalada' : 'em falta ('.$paraQue.')',
			];
		}

		//escrever o Conecta.php é o primeiro sítio onde uma permissão
		//errada trava tudo -- e o erro que o PHP dá nesse caso não diz
		//"a pasta app/ não é gravável", diz só "failed to open stream"
		$lista[] = [
			'nome' => 'Pasta app/ gravável',
			'ok'   => is_writable($this->caminho('app')),
			'tem'  => is_writable($this->caminho('app')) ? 'sim' : 'sem permissão de escrita',
		];

		$logs = $this->caminho('lib/logs');
		$lista[] = [
			'nome' => 'Pasta lib/logs/ gravável',
			'ok'   => is_dir($logs) ? is_writable($logs) : is_writable($this->caminho('lib')),
			'tem'  => 'para o registo de erros',
		];

		$lista[] = [
			'nome' => 'Ficheiro de instalação',
			'ok'   => is_readable($this->caminho(self::FICHEIRO_SQL)),
			'tem'  => self::FICHEIRO_SQL,
		];

		return $lista;
	}

	public function requisitosOk($lista) {
		foreach ($lista as $r) { if(!$r['ok']){ return false; } }
		return true;
	}


	//=================================================================
	// 2. Estado da instalação
	//=================================================================

	/*
	Já está instalado?

	Duas perguntas diferentes, e as duas contam:

	  a trava   um ficheiro que o instalador escreve no fim. É o que
	            impede alguém de voltar a correr o instalador num sistema
	            a funcionar e deitar a base de dados fora -- o buraco
	            clássico de um instalador esquecido no servidor;
	  o config  se o app/Conecta.php já existe, alguém já cá andou.

	Os dois desfazem-se à mão (apagando os ficheiros), de propósito: quem
	tem acesso ao servidor pode reinstalar; quem só tem o endereço não.
	*/
	public function jaInstalado() { return is_file($this->caminho(self::FICHEIRO_TRAVA)); }
	public function temConfig()   { return is_file($this->caminho(self::FICHEIRO_CONFIG)); }


	//=================================================================
	// 3. A base de dados
	//=================================================================

	public function testarLigacao($host, $base, $utilizador, $senha, $porta = '') {
		try {
			$pdo = new PDO(
				$this->dsn($host, $base, $porta),
				$utilizador, $senha,
				[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
			);
			return ['ok' => true, 'pdo' => $pdo, 'erro' => null, 'detalhe' => null];
		} catch (PDOException $e) {
			return ['ok' => false, 'pdo' => null,
			        'erro' => $this->traduzirErroLigacao($e),
			        'detalhe' => $e->getMessage()];
		}
	}

	/*
	O endereço da base de dados, montado.

	A porta fica de fora quando é a de sempre (3306), para o Conecta.php
	gerado ficar igual ao que se escreveria à mão. Alguns alojamentos usam
	outra, e aí tem de lá estar -- sem ela a ligação vai bater na 3306 e
	falha com "não há servidor nenhum", que manda procurar o problema no
	sítio errado.
	*/
	public function dsn($host, $base, $porta = '') {
		$dsn = "mysql:host={$host}";
		$porta = trim((string)$porta);
		if($porta !== '' && $porta !== '3306'){ $dsn .= ";port=".(int)$porta; }
		return $dsn.";dbname={$base};charset=utf8mb4";
	}

	/*
	A mensagem do MySQL é verdadeira e não ajuda nada a quem instala.
	Traduz-se para o que a pessoa tem mesmo de ir fazer.
	*/
	private function traduzirErroLigacao(PDOException $e) {
		$m = $e->getMessage();

		if(strpos($m, 'Access denied') !== false){
			return 'O utilizador ou a palavra-passe não estão certos — ou o utilizador existe '
			     . 'mas não está ATRIBUÍDO a esta base de dados. Num alojamento partilhado, criar '
			     . 'o utilizador e a base de dados não chega: há um passo à parte para os ligar.';
		}
		if(strpos($m, 'Unknown database') !== false){
			return 'A base de dados com esse nome não existe. Num alojamento partilhado o nome leva '
			     . 'o prefixo da conta (por exemplo u123456_app), e não apenas "app".';
		}
		if(strpos($m, 'No such file or directory') !== false || strpos($m, "Can't connect") !== false){
			return 'Não há nenhum servidor MySQL nesse endereço. Num alojamento partilhado o servidor '
			     . 'é quase sempre "localhost".';
		}
		return 'Não foi possível ligar à base de dados.';
	}

	//a base de dados já tem a aplicação lá dentro?
	public function jaTemTabelas(PDO $pdo) {
		try {
			return $pdo->query("SHOW TABLES LIKE 'app_config'")->fetch() !== false;
		} catch (Throwable $e) {
			return false;
		}
	}


	//=================================================================
	// 4. Importar o SQL
	//=================================================================

	/*
	Corre o instalacao/instalacao.sql, instrução a instrução.

	PORQUE É QUE NÃO SE MANDA O FICHEIRO INTEIRO AO PDO

	Porque o PDO não corre várias instruções de uma vez de forma fiável, e
	sobretudo porque assim não se sabe QUAL falhou. Partindo o ficheiro,
	uma falha diz a instrução e o motivo -- em vez de uma importação que
	pára a meio sem ninguém dar por isso.
	*/
	public function importar(PDO $pdo, $caminhoSql = null) {
		$caminhoSql = $caminhoSql ?: $this->caminho(self::FICHEIRO_SQL);

		if(!is_readable($caminhoSql)){
			return ['ok' => false, 'erro' => 'Não encontrei o ficheiro '.self::FICHEIRO_SQL, 'instrucoes' => 0];
		}

		$sql = file_get_contents($caminhoSql);
		if($sql === false || trim($sql) === ''){
			return ['ok' => false, 'erro' => 'O ficheiro de instalação está vazio.', 'instrucoes' => 0];
		}

		$corridas = 0;
		foreach ($this->partirEmInstrucoes($sql) as $i => $instrucao) {
			try {
				$pdo->exec($instrucao);
				$corridas++;
			} catch (PDOException $e) {
				return [
					'ok' => false,
					'erro' => 'A instrução nº '.($i + 1).' falhou: '.$e->getMessage(),
					'instrucao' => mb_substr($instrucao, 0, 200),
					'instrucoes' => $corridas,
				];
			}
		}

		return ['ok' => true, 'erro' => null, 'instrucoes' => $corridas];
	}

	/*
	Partir o ficheiro em instruções.

	NÃO é um explode(';'): há pontos e vírgulas dentro de textos (uma
	observação, um nome) e dentro de comentários. Percorre-se o ficheiro a
	seguir as aspas e as escapes, que é a única forma de não partir uma
	instrução ao meio.
	*/
	public function partirEmInstrucoes($sql) {
		$instrucoes = [];
		$actual = '';
		$dentroDe = null;      //a aspa que abriu o texto onde estamos, ou null
		$escapado = false;
		$comentarioLinha = false;
		$tamanho = strlen($sql);

		for ($i = 0; $i < $tamanho; $i++) {
			$c = $sql[$i];

			//um comentário de linha (-- ou #) vai até ao fim da linha
			if($comentarioLinha){
				if($c === "\n"){ $comentarioLinha = false; $actual .= $c; }
				continue;
			}

			if($dentroDe === null){
				if(substr($sql, $i, 2) === '--' || $c === '#'){
					//o "--" só abre comentário seguido de espaço ou no
					//início da linha; "a--b" dentro de um nome não é
					if($c === '#' || substr($sql, $i, 3) === '-- '
					   || rtrim($actual, " \t") === '' || substr($actual, -1) === "\n"){
						$comentarioLinha = true;
						continue;
					}
				}
			}

			$actual .= $c;

			if($escapado){ $escapado = false; continue; }
			if($c === '\\'){ $escapado = true; continue; }

			if($dentroDe !== null){
				if($c === $dentroDe){ $dentroDe = null; }
				continue;
			}

			if($c === "'" || $c === '"' || $c === '`'){ $dentroDe = $c; continue; }

			if($c === ';'){
				$limpa = trim($actual);
				if(trim(rtrim($limpa, ';')) !== ''){ $instrucoes[] = $limpa; }
				$actual = '';
			}
		}

		$resto = trim($actual);
		if(trim(rtrim($resto, ';')) !== ''){ $instrucoes[] = $resto; }

		return $instrucoes;
	}


	//=================================================================
	// 5. Escrever o app/Conecta.php
	//=================================================================

	/*
	Gera o ficheiro de ligação a partir do app/Conecta.exemplo.php.

	Parte-se do exemplo e não de um texto escrito aqui de propósito: o
	exemplo é o ficheiro que é mantido quando a forma de ligar muda (o
	singleton, as opções do PDO, o catch que não engole o erro). Se o
	instalador tivesse a sua própria cópia, as duas divergiam no primeiro
	mês e as instalações novas nasciam com a versão velha.

	A password entra por var_export(), que trata das aspas e das barras --
	uma password com uma aspa lá dentro partia o ficheiro a meio.
	*/
	public function escreverConfig($host, $base, $utilizador, $senha, $porta = '') {
		$exemplo = $this->caminho('app/Conecta.exemplo.php');
		if(!is_readable($exemplo)){
			return ['ok' => false, 'erro' => 'Não encontrei o app/Conecta.exemplo.php.'];
		}

		$texto = file_get_contents($exemplo);

		$trocas = [
			"define('_HOST_', 'localhost');"             => "define('_HOST_', ".var_export($host, true).");",
			"define('_DB_',   'nome_da_base_de_dados');" => "define('_DB_',   ".var_export($base, true).");",
			"define('_US_',   'utilizador');"            => "define('_US_',   ".var_export($utilizador, true).");",
			"define('_PS_',   'password');"              => "define('_PS_',   ".var_export($senha, true).");",
		];

		$porta = trim((string)$porta);
		if($porta !== '' && $porta !== '3306'){
			$trocas['"mysql:host=" . _HOST_ . ";dbname=" . _DB_ . ";charset=utf8mb4",']
				= '"mysql:host=" . _HOST_ . ";port='.(int)$porta.';dbname=" . _DB_ . ";charset=utf8mb4",';
		}

		foreach ($trocas as $velho => $novo) {
			if(strpos($texto, $velho) === false){
				return ['ok' => false, 'erro' => 'O app/Conecta.exemplo.php não tem a linha esperada: '.$velho];
			}
			$texto = str_replace($velho, $novo, $texto);
		}

		//o cabeçalho do exemplo explica como se copia o ficheiro à mão. Num
		//ficheiro já gerado isso confunde -- fica a dizer de onde veio
		$texto = preg_replace(
			'/^<\?php\s*\/\*.*?\*\//s',
			"<?php\n/*\n Ficheiro gerado pelo instalador (instalar/) em ".date('Y-m-d H:i').".\n"
			." Para mudar a ligação, editar as quatro linhas abaixo.\n"
			." O modelo com a explicação toda está em app/Conecta.exemplo.php.\n*/",
			$texto,
			1
		);

		$destino = $this->caminho(self::FICHEIRO_CONFIG);
		if(@file_put_contents($destino, $texto) === false){
			return ['ok' => false, 'erro' => 'Não consegui escrever o '.self::FICHEIRO_CONFIG.'. A pasta app/ tem de ser gravável.'];
		}
		@chmod($destino, 0640);

		return ['ok' => true, 'erro' => null];
	}


	//=================================================================
	// 6. As definições e a primeira conta
	//=================================================================

	/*
	O nome do sistema e o endereço, na app_config.

	O `url` é o que mais custa quando está errado: o url_base() monta
	TODOS os endereços da aplicação a partir dele -- menu, CSS,
	JavaScript, imagens, acção dos formulários. Por isso o instalador
	propõe-no a partir do próprio pedido que está a servir, em vez de o
	deixar ao critério de quem instala.
	*/
	public function gravarDefinicoes(PDO $pdo, array $d) {
		$qr = $pdo->prepare(
			"INSERT INTO `app_config` (`conf_chave`, `conf_valor`) VALUES (?, ?)
			 ON DUPLICATE KEY UPDATE `conf_valor` = VALUES(`conf_valor`)"
		);

		foreach ([
			'tit' => $d['nome'],
			'url' => $this->normalizarUrl($d['url']),
		] as $chave => $valor) {
			$qr->execute([$chave, $valor]);
		}

		return true;
	}

	public function normalizarUrl($url) {
		$url = trim((string)$url);
		if($url === ''){ return ''; }
		if(!preg_match('#^https?://#i', $url)){ $url = 'http://'.$url; }
		return rtrim($url, '/').'/';
	}

	//o endereço por onde ESTE pedido chegou, que é quase sempre o certo
	public function urlSugerido() {
		$esquema = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
		           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';

		$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

		//este ficheiro corre em /instalar/, e o que se quer é a pasta acima
		$pasta = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/instalar/index.php')))), '/');
		if($pasta === '.' || $pasta === '/'){ $pasta = ''; }

		return $esquema.'://'.$host.$pasta.'/';
	}

	/*
	A primeira conta -- a de quem instalou.

	Nasce administrador (nivl_us = 1), porque senão não haveria ninguém
	que pudesse criar as outras.
	*/
	public function criarConta(PDO $pdo, array $d) {
		try {
			$qr = $pdo->prepare(
				"INSERT INTO `app_utilizador` (`nome_us`, `email_us`, `pss_us`, `nivl_us`, `stto_us`, `dtc_us`)
				 VALUES (?, ?, ?, 1, 1, NOW())
				 ON DUPLICATE KEY UPDATE `nome_us` = VALUES(`nome_us`), `pss_us` = VALUES(`pss_us`), `nivl_us` = 1, `stto_us` = 1"
			);
			$qr->execute([
				$d['conta_nome'],
				$d['conta_email'],
				password_hash($d['conta_senha'], PASSWORD_DEFAULT),
			]);
			return ['ok' => true, 'erro' => null];
		} catch (PDOException $e) {
			return ['ok' => false, 'erro' => 'Não consegui criar a conta: '.$e->getMessage()];
		}
	}


	//=================================================================
	// 7. Trancar
	//=================================================================

	public function trancar(array $resumo) {
		$texto = "Instalado em ".date('Y-m-d H:i:s')."\n"
		       . "endereço: ".($resumo['url'] ?? '')."\n\n"
		       . "Este ficheiro é o que impede o instalador de voltar a correr.\n"
		       . "Apagá-lo reabre o instalador -- e o instalador reescreve o\n"
		       . "app/Conecta.php. Só o apagar para reinstalar de propósito.\n";

		return @file_put_contents($this->caminho(self::FICHEIRO_TRAVA), $texto) !== false;
	}
}
