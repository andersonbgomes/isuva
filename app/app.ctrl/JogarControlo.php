<?php
/*
JOGAR: a página do emulador, e os ficheiros que ela pede.

    /jogar/ver/7                   o jogo 7 do catálogo
    /jogar/local                   um jogo do computador de quem joga
    /jogar/ficheiro/7/<nome>       a ROM/ISO do jogo 7 (pedida pelo emulador)
    /jogar/capa/7                  a capa do jogo 7
    /jogar/bios/<consola>/<nome>   a BIOS de uma consola
    /jogar/pedir/7                 (POST) um lugar num nó de jogo, para a PS2
    /jogar/estado/<sessão>         (POST) como está a sessão, e sinal de vida
    /jogar/terminar/<sessão>       (POST) sair e libertar o lugar

ONDE O JOGO CORRE

Depende do 'modo' da consola (lib/jogos/Consolas.php):

  browser   no browser de quem joga. O servidor entrega a página, o
            EmulatorJS e o ficheiro do jogo -- daí para a frente é o
            computador da pessoa que faz o trabalho, e por isso um
            alojamento PHP normal aguenta muitos jogadores.
  servidor  (PS2) num nó de jogo com placa gráfica, e a imagem chega por
            streaming. A página só pede um lugar (pedir), pergunta pelo
            estado (estado) e mostra o nó num <iframe> quando está pronto.

O MODO "DO MEU COMPUTADOR" NÃO ENVIA NADA

Em /jogar/local o ficheiro escolhido é lido pelo browser e entregue
directamente ao emulador -- nunca sai do computador da pessoa. Não ocupa
disco no servidor, não gasta a ligação, e não deixa cópia nenhuma cá.
*/
class JogarControlo extends Acao {

	public function index() {
		header('Location:'.url_base('jogos'));
		exit;
	}

	public function ver() {
		$jogo = $this->jogo(id);
		if(empty($jogo) || (!$this->e_admin() && (int)$jogo['stto_jg'] !== 1)){
			$this->voltar('warning', 'Não encontrado', 'Esse jogo não existe ou já não está disponível.', 'jogos');
		}

		$consola = Consolas::pegar($jogo['consola_jg']);
		if($consola === null){
			$this->voltar('danger', 'Consola desconhecida',
				'Este jogo é de uma consola que o site já não suporta. Edite-o no painel.', 'jogos');
		}

		$this->ver->jogo    = $jogo;
		$this->ver->consola = $consola;

		/*
		PS2 (e o que mais correr no servidor): a página não tem emulador
		nenhum. Pede um lugar num nó de jogo e mostra a imagem que vem de
		lá -- ver stream.phtml e lib/jogos/Fila.php.

		Sem isolar(): a página embebe o nó num <iframe> de outro endereço,
		e com COEP o browser recusava-o.
		*/
		if($consola['modo'] === 'servidor'){
			$this->renderizar_solto('stream');
			return;
		}

		$this->isolar();

		$this->ver->emu     = $this->configEmulador($jogo['consola_jg']);
		$this->ver->emu['gameUrl']  = url_base('jogar/ficheiro/'.(int)$jogo['id_jg'].'/'.rawurlencode($jogo['nome_jg']));
		$this->ver->emu['gameName'] = $jogo['titulo_jg'];
		$this->ver->emu['gameID']   = (int)$jogo['id_jg'];

		//as gravações desta conta neste jogo vivem no servidor (gravacoes.js)
		$this->ver->gravacoes = [
			'jogo'    => (int)$jogo['id_jg'],
			'csrf'    => csrf_token(),
			'ler'     => url_base('gravacoes/ler/'.(int)$jogo['id_jg']),
			'iniciar' => url_base('gravacoes/iniciar'),
			'pedaco'  => url_base('gravacoes/pedaco'),
			'guardar' => url_base('gravacoes/guardar'),
		];

		$this->renderizar_solto('ver');
	}

	public function local() {
		$this->isolar();

		/*
		A configuração de TODAS as consolas vai para a página de uma vez,
		e é o JavaScript que escolhe a que serve depois de a pessoa dizer
		qual é. O caminho dos núcleos, a BIOS e o "precisa de threads"
		ficam assim decididos aqui, num sítio só, igual ao do catálogo.
		*/
		$porConsola = [];
		foreach (Consolas::LISTA as $chave => $c) {
			//as de servidor (PS2) não correm a partir de um ficheiro local
			if($c['modo'] !== 'browser'){ continue; }
			$porConsola[$chave] = $this->configEmulador($chave) + [
				'nome' => $c['nome'],
				'ext'  => Consolas::extensoes($chave),
				'nota' => $c['nota'],
				'semBios' => $c['bios'] === 'obrigatoria' && empty(Bios::de($chave)),
			];
		}

		$this->ver->consolas = $porConsola;
		$this->renderizar_solto('local');
	}

	//=============================================================
	// As sessões no nó de jogo (PS2) -- pedidos JSON da stream.phtml
	//=============================================================

	/*
	POST /jogar/pedir/<id do jogo>: um lugar na fila para este jogo.

	É um POST, e não acontece só por abrir a página: pedir uma sessão
	ocupa (ou põe na fila para ocupar) uma placa gráfica, e isso não pode
	ser disparado por um pré-carregamento do browser ou por um link.
	*/
	public function pedir() {
		$this->soPostJson();

		$jogo = $this->jogo(id);
		if(empty($jogo) || (!$this->e_admin() && (int)$jogo['stto_jg'] !== 1) || !Consolas::noServidor($jogo['consola_jg'])){
			$this->json(['erro' => 'Esse jogo não existe ou não corre no servidor.'], 404);
		}

		$s = Fila::pedir(utilizador_actual(), $jogo);
		if(is_string($s)){
			$this->json(['erro' => $s], 422);
		}

		$this->json(['sessao' => (int)$s['id_ss']] + Fila::consultar($s));
	}

	/*
	POST /jogar/estado/<id da sessão>: como está a sessão. É também o
	sinal de vida da página: sem ele, a sessão termina (Fila::SEM_SINAL).
	*/
	public function estado() {
		$this->soPostJson();
		$s = $this->minhaSessao(id);
		$this->json(Fila::consultar($s));
	}

	//POST /jogar/terminar/<id da sessão>: o jogador saiu, o lugar liberta-se já
	public function terminar() {
		$this->soPostJson();
		$s = $this->minhaSessao(id);
		Fila::terminar($s, 'O jogador terminou a sessão.');
		$this->json(['estado' => 'terminada']);
	}

	public function ficheiro() {
		$jogo = $this->jogo(id);
		if(empty($jogo) || (!$this->e_admin() && (int)$jogo['stto_jg'] !== 1)){
			http_response_code(404);
			exit;
		}
		Armazem::servir(Armazem::caminho('jogos', $jogo['ficheiro_jg']), $jogo['nome_jg']);
	}

	public function capa() {
		$jogo = $this->jogo(id);
		if(empty($jogo) || empty($jogo['capa_jg'])){
			http_response_code(404);
			exit;
		}

		$caminho = Armazem::caminho('capas', $jogo['capa_jg']);
		$tipo = 'application/octet-stream';
		if($caminho !== null && is_file($caminho) && function_exists('finfo_open')){
			$tipo = (new finfo(FILEINFO_MIME_TYPE))->file($caminho) ?: $tipo;
		}
		//só imagem: o envio já o garantiu, mas nada se serve com um tipo
		//que o browser pudesse executar
		if(strpos($tipo, 'image/') !== 0 || $tipo === 'image/svg+xml'){
			$tipo = 'application/octet-stream';
		}
		Armazem::servir($caminho, $jogo['capa_jg'], $tipo);
	}

	public function bios() {
		$bios = Bios::de(id);
		if(empty($bios)){
			http_response_code(404);
			exit;
		}
		Armazem::servir(Armazem::caminho('bios', $bios['disco']), $bios['nome']);
	}


	//=============================================================
	// Ajudantes
	//=============================================================

	/*
	Uma sessão do utilizador com sessão aberta, e só dele. O id vem do
	endereço: sem esta verificação, qualquer pessoa terminava (ou via o
	endereço de entrada de) a sessão de outra só por mudar um número.
	*/
	private function minhaSessao($id) {
		$s = Fila::sessao($id);
		if(empty($s) || (int)$s['us_ss'] !== utilizador_actual()){
			$this->json(['erro' => 'Essa sessão não existe.'], 404);
		}
		return $s;
	}

	private function soPostJson() {
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->json(['erro' => 'A sessão expirou. Recarregue a página.'], 403);
		}
	}

	private function json($dados, $codigo = 200) {
		http_response_code($codigo);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode($dados, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}

	private function jogo($id) {
		$j = new Jogo;
		$j->__add('dados', ['id_jg = ' => (int)$id]);
		$j->pegar();
		return $j->fetch ?: [];
	}

	/*
	O que o EmulatorJS precisa de saber, para uma consola.

	O endereço dos dados (emu_dados) vem das definições: por omissão é a
	CDN oficial do EmulatorJS, e o administrador pode trocá-lo por uma
	cópia no próprio servidor -- ver o LEIA-ME, secção "EmulatorJS".
	*/
	private function configEmulador($chave) {
		$c = Consolas::pegar($chave);

		$dados = trim((string)configura('emu_dados'));
		if($dados === ''){ $dados = 'https://cdn.emulatorjs.org/stable/data/'; }
		//um caminho relativo (emulatorjs/data/) é relativo à aplicação
		if(!preg_match('#^https?://#i', $dados)){ $dados = url_base(ltrim($dados, '/')); }
		$dados = rtrim($dados, '/').'/';

		$bios = Bios::de($chave);

		return [
			'core'      => $c['nucleo'],
			'pathtodata'=> $dados,
			'threads'   => (bool)$c['threads'],
			'biosUrl'   => $bios ? url_base('jogar/bios/'.$chave.'/'.rawurlencode($bios['nome'])) : '',
		];
	}

	/*
	Os dois cabeçalhos que ligam o SharedArrayBuffer no browser.

	O núcleo do PSP corre em várias threads, e o browser só as dá a uma
	página "isolada" (cross-origin isolated). Sem estes cabeçalhos, o
	EmulatorJS recusa-se a arrancar com "Error for site owner".

	`credentialless` e não `require-corp`: com require-corp, todos os
	ficheiros de outros sites (a CDN do EmulatorJS) teriam de vir com um
	cabeçalho Cross-Origin-Resource-Policy que não controlamos. O
	credentialless pede-os sem cookies, e isso chega. O Safari ainda não
	o conhece -- aí o PSP não arranca, e as outras consolas funcionam na
	mesma, porque não precisam de threads.

	Vão para as páginas do emulador e só para essas: no resto do site não
	fazem falta, e o COOP corta a ligação com janelas abertas por outros
	sites (um login social, um pagamento) se um dia houver.
	*/
	private function isolar() {
		header('Cross-Origin-Opener-Policy: same-origin');
		header('Cross-Origin-Embedder-Policy: credentialless');
	}
}
