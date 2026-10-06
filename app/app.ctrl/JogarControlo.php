<?php
/*
JOGAR: a página do emulador, e os ficheiros que ela pede.

    /jogar/ver/7                   o jogo 7 do catálogo
    /jogar/local                   um jogo do computador de quem joga
    /jogar/ficheiro/7/<nome>       a ROM/ISO do jogo 7 (pedida pelo emulador)
    /jogar/capa/7                  a capa do jogo 7
    /jogar/bios/<consola>/<nome>   a BIOS de uma consola

ONDE O JOGO CORRE

No browser de quem joga, e não no servidor. O servidor entrega a página,
o EmulatorJS (núcleos do RetroArch compilados para WebAssembly) e o
ficheiro do jogo -- daí para a frente é o computador da pessoa que faz o
trabalho. É por isso que isto aguenta muitos jogadores num alojamento PHP
normal, e é também por isso que PS2 e PS3 não estão aqui: precisam de um
servidor com placa gráfica a correr o jogo e a enviar vídeo (Fase 2).

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

		$this->isolar();

		$this->ver->jogo    = $jogo;
		$this->ver->consola = $consola;
		$this->ver->emu     = $this->configEmulador($jogo['consola_jg']);
		$this->ver->emu['gameUrl']  = url_base('jogar/ficheiro/'.(int)$jogo['id_jg'].'/'.rawurlencode($jogo['nome_jg']));
		$this->ver->emu['gameName'] = $jogo['titulo_jg'];
		$this->ver->emu['gameID']   = (int)$jogo['id_jg'];

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
