<?php
/*
O PAINEL DO ADMINISTRADOR: os jogos do catálogo, e o emulador.

    /admin                    a lista de todos os jogos (escondidos incluídos)
    /admin/novo               o formulário de um jogo novo
    /admin/iniciar            (POST, JSON) abre um envio aos pedaços
    /admin/pedaco             (POST, JSON) recebe um pedaço do envio
    /admin/guardar            (POST) grava o jogo novo
    /admin/editar/7           o formulário de edição
    /admin/actualizar/7       (POST) grava a edição
    /admin/apagar/7           (POST) apaga o jogo e os ficheiros dele
    /admin/emulador           o endereço do EmulatorJS e as BIOS
    /admin/guardaremulador    (POST)
    /admin/guardarbios        (POST) envia a BIOS de uma consola
    /admin/apagarbios         (POST)

TODAS AS ACÇÕES COMEÇAM POR so_admin(). Não chega esconder o menu: quem
souber o endereço escreve-o à mão.

COMO ENTRA UM JOGO GRANDE

Uma ISO de 1 GB não cabe num POST -- o post_max_size dos alojamentos anda
pelos 8 MB. Por isso o envio é feito em três tempos:

    1. iniciar   o browser diz o nome e o tamanho; o servidor valida a
                 extensão e o tamanho ANTES de receber um byte, e devolve
                 um identificador e o tamanho dos pedaços;
    2. pedaco    o browser manda o ficheiro aos bocados, por ordem;
    3. guardar   o formulário normal, com o identificador do envio no
                 lugar do ficheiro. Só aqui se cria o jogo, e só se o
                 envio estiver completo.
*/
class AdminControlo extends Acao {

	const MAX_TITULO    = 150;
	const MAX_DESCRICAO = 2000;
	const MAX_LINK      = 500;

	//as imagens aceites para capa, e a extensão com que ficam no disco
	const TIPOS_CAPA = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

	public function index() {
		$this->so_admin();

		$j = new Jogo;
		$j->__add('dados', ['id_jg > ' => 0]);
		$j->__add('ordem', '`titulo_jg` ASC');
		$j->pegar_todos();

		$this->ver->jogos = $j->fetchall ?: [];

		$total = 0;
		foreach ($this->ver->jogos as $jogo) { $total += (int)$jogo['tamanho_jg']; }
		$this->ver->espaco = $total;

		$this->renderizar('lista');
	}

	public function novo() {
		$this->so_admin();
		Armazem::limparEnviosVelhos();

		$this->ver->tamanhoMax = TAMANHO_MAX_JOGO;
		$this->ver->temCurl    = function_exists('curl_init');
		$this->renderizar('novo');
	}


	//=============================================================
	// O envio aos pedaços
	//=============================================================

	public function iniciar() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->json(['erro' => 'A sessão expirou. Recarregue a página.'], 403);
		}

		$nome    = Armazem::nomeLimpo($_POST['nome'] ?? '');
		$tamanho = (int)($_POST['tamanho'] ?? 0);
		$consola = (string)($_POST['consola'] ?? '');

		if(!Consolas::existe($consola)){
			$this->json(['erro' => 'Escolha a consola primeiro.'], 422);
		}
		if($nome === '' || !Consolas::aceita($consola, $nome)){
			$this->json(['erro' => 'Um ficheiro .'.pathinfo($nome, PATHINFO_EXTENSION).' não serve para '
				.Consolas::nome($consola).'. Aceites: '.implode(', ', Consolas::extensoes($consola)).'.'], 422);
		}
		if($tamanho <= 0 || $tamanho > TAMANHO_MAX_JOGO){
			$this->json(['erro' => 'O ficheiro tem de ter entre 1 byte e '.Armazem::legivel(TAMANHO_MAX_JOGO).'.'], 422);
		}

		/*
		Há espaço em disco? Melhor dizer já do que deixar alguém enviar
		1,5 GB durante meia hora para rebentar no último pedaço.
		*/
		$livre = @disk_free_space(Armazem::pasta('envios'));
		if($livre !== false && $livre < $tamanho + 50 * 1024 * 1024){
			$this->json(['erro' => 'Não há espaço suficiente no servidor ('.Armazem::legivel($livre).' livres).'], 507);
		}

		$id = Armazem::envioNovo($nome, $tamanho);
		$this->json(['envio' => $id, 'pedaco' => Armazem::tamanhoPedaco()]);
	}

	public function pedaco() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->json(['erro' => 'A sessão expirou. Recarregue a página.'], 403);
		}

		$id = (string)($_SERVER['HTTP_X_ENVIO'] ?? '');
		if(!Armazem::envioValido($id)){
			$this->json(['erro' => 'Esse envio não existe. Comece outra vez.'], 404);
		}

		$r = Armazem::envioPedaco($id, (int)($_SERVER['HTTP_X_POSICAO'] ?? -1), 'php://input');

		//"pos:N" é o servidor a dizer onde o envio está, para o browser
		//continuar dali (um pedaço repetido depois de uma falha de rede)
		if(is_string($r) && strpos($r, 'pos:') === 0){
			$this->json(['posicao' => (int)substr($r, 4)], 409);
		}
		if(is_string($r)){
			$this->json(['erro' => $r], 422);
		}
		$this->json(['posicao' => $r]);
	}


	//=============================================================
	// Gravar
	//=============================================================

	public function guardar() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'admin/novo');
		}

		$campos = $this->validarCampos();
		if(is_string($campos)){
			$this->voltar('warning', 'Falta corrigir', $campos, 'admin/novo');
		}

		/*
		A capa primeiro, e o jogo depois: a capa falha em segundos e o
		jogo por link pode levar minutos. Ao contrário, um erro na capa
		deitava fora uma descarga inteira.
		*/
		$capa = $this->receberCapa();
		if(is_string($capa) && $capa !== ''){
			$this->voltar('warning', 'Capa', $capa, 'admin/novo');
		}

		$origem = (($_POST['origem'] ?? '') === 'link') ? 'link' : 'ficheiro';
		$ficheiro = ($origem === 'link')
			? $this->trazerDoLink($campos['consola_jg'])
			: $this->fecharEnvio($campos['consola_jg']);

		if(is_string($ficheiro)){
			if(is_array($capa)){ Armazem::apagar('capas', $capa['disco']); }
			$this->voltar('danger', 'O jogo não foi gravado', $ficheiro, 'admin/novo');
		}

		$j = new Jogo;
		$j->__add('dados', $campos + [
			'ficheiro_jg' => $ficheiro['disco'],
			'nome_jg'     => $ficheiro['nome'],
			'tamanho_jg'  => $ficheiro['tamanho'],
			'origem_jg'   => $ficheiro['origem'] ?? null,
			'capa_jg'     => is_array($capa) ? $capa['disco'] : null,
			'us_jg'       => utilizador_actual(),
			'dtc_jg'      => date('Y-m-d H:i:s'),
		]);
		$j->inserir();

		if(!$j->result){
			//sem linha na base de dados, os ficheiros ficavam órfãos no disco
			Armazem::apagar('jogos', $ficheiro['disco']);
			if(is_array($capa)){ Armazem::apagar('capas', $capa['disco']); }
			_registar_erro('Jogo', (string)$j->sms);
			$this->voltar('danger', 'Erro', 'Não foi possível gravar o jogo. O motivo ficou no registo de erros.', 'admin/novo');
		}

		$this->voltar('success', 'Gravado', '"'.$campos['titulo_jg'].'" já está no catálogo.', 'admin');
	}

	public function editar() {
		$this->so_admin();

		$jogo = $this->jogo(id);
		if(empty($jogo)){
			$this->voltar('warning', 'Não encontrado', 'Esse jogo não existe.', 'admin');
		}
		$this->ver->jogo = $jogo;
		$this->renderizar('editar');
	}

	public function actualizar() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'admin');
		}

		$jogo = $this->jogo(id);
		if(empty($jogo)){
			$this->voltar('warning', 'Não encontrado', 'Esse jogo não existe.', 'admin');
		}
		$volta = 'admin/editar/'.(int)$jogo['id_jg'];

		$campos = $this->validarCampos();
		if(is_string($campos)){
			$this->voltar('warning', 'Falta corrigir', $campos, $volta);
		}

		//mudar a consola só se o ficheiro que já lá está servir para a nova
		if(!Consolas::aceita($campos['consola_jg'], $jogo['nome_jg'])){
			$this->voltar('warning', 'Consola', 'O ficheiro deste jogo ('.$jogo['nome_jg'].') não serve para '
				.Consolas::nome($campos['consola_jg']).'.', $volta);
		}

		$capa = $this->receberCapa();
		if(is_string($capa) && $capa !== ''){
			$this->voltar('warning', 'Capa', $capa, $volta);
		}

		$campos['stto_jg'] = !empty($_POST['visivel']) ? 1 : 0;

		$capaAntiga = $jogo['capa_jg'];
		if(is_array($capa)){
			$campos['capa_jg'] = $capa['disco'];
		} elseif(!empty($_POST['tirar_capa'])){
			$campos['capa_jg'] = null;
		}

		$j = new Jogo;
		$j->__add('dados', $campos);
		$j->__add('onde',  ['id_jg = ' => (int)$jogo['id_jg']]);
		$j->actualizar();

		if(!$j->result){
			if(is_array($capa)){ Armazem::apagar('capas', $capa['disco']); }
			_registar_erro('Jogo', (string)$j->sms);
			$this->voltar('danger', 'Erro', 'Não foi possível gravar. O motivo ficou no registo de erros.', $volta);
		}

		//a capa antiga só sai do disco depois de a linha já não apontar para ela
		if($capaAntiga && array_key_exists('capa_jg', $campos) && $campos['capa_jg'] !== $capaAntiga){
			Armazem::apagar('capas', $capaAntiga);
		}

		$this->voltar('success', 'Gravado', 'As alterações foram gravadas.', 'admin');
	}

	public function apagar() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'admin');
		}

		$jogo = $this->jogo(id);
		if(empty($jogo)){
			$this->voltar('warning', 'Não encontrado', 'Esse jogo não existe.', 'admin');
		}

		$j = new Jogo;
		$j->__add('onde', ['id_jg = ' => (int)$jogo['id_jg']]);
		$j->apagar();

		if(!$j->result){
			_registar_erro('Jogo', (string)$j->sms);
			$this->voltar('danger', 'Erro', 'Não foi possível apagar. O motivo ficou no registo de erros.', 'admin');
		}

		//os ficheiros depois da linha: ao contrário, uma falha na base de
		//dados deixava no catálogo um jogo sem ficheiro
		Armazem::apagar('jogos', $jogo['ficheiro_jg']);
		if($jogo['capa_jg']){ Armazem::apagar('capas', $jogo['capa_jg']); }

		$this->voltar('success', 'Apagado', '"'.$jogo['titulo_jg'].'" saiu do catálogo e do disco.', 'admin');
	}


	//=============================================================
	// O emulador e as BIOS
	//=============================================================

	public function emulador() {
		$this->so_admin();

		$bios = [];
		foreach (Consolas::LISTA as $chave => $c) {
			if($c['bios'] !== 'nao'){
				$bios[$chave] = ['consola' => $c, 'ficheiro' => Bios::de($chave)];
			}
		}

		$this->ver->bios     = $bios;
		$this->ver->emuDados = (string)configura('emu_dados');
		$this->renderizar('emulador');
	}

	public function guardaremulador() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'admin/emulador');
		}

		$dados = trim((string)($_POST['emu_dados'] ?? ''));

		/*
		Ou um endereço https, ou um caminho DENTRO da aplicação. O valor vai
		parar a um <script src>: um javascript:, um data: ou um "//outro"
		aqui era entregar a página inteira a quem o escrevesse.
		*/
		$https    = preg_match('#^https://[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._~\-/]*)?$#', $dados);
		$relativo = preg_match('#^[A-Za-z0-9_\-][A-Za-z0-9._\-/]*$#', $dados) && strpos($dados, '..') === false;

		if($dados === '' || strlen($dados) > self::MAX_LINK || (!$https && !$relativo)){
			$this->voltar('warning', 'Endereço', 'Use um endereço https:// ou um caminho dentro do site (ex.: emulatorjs/data/).', 'admin/emulador');
		}

		$stt = Con::ecta()->prepare(
			"INSERT INTO `app_config` (`conf_chave`, `conf_valor`) VALUES ('emu_dados', ?)
			 ON DUPLICATE KEY UPDATE `conf_valor` = VALUES(`conf_valor`)"
		);
		$stt->execute([$dados]);

		$this->voltar('success', 'Gravado', 'O endereço do EmulatorJS foi gravado.', 'admin/emulador');
	}

	public function guardarbios() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'admin/emulador');
		}

		$consola = (string)($_POST['consola'] ?? '');
		$c = Consolas::pegar($consola);
		if($c === null || $c['bios'] === 'nao'){
			$this->voltar('warning', 'BIOS', 'Essa consola não usa BIOS.', 'admin/emulador');
		}

		$f = $_FILES['bios'] ?? null;
		if(!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])){
			$this->voltar('warning', 'BIOS', $this->erroEnvio($f['error'] ?? UPLOAD_ERR_NO_FILE), 'admin/emulador');
		}
		if($f['size'] <= 0 || $f['size'] > TAMANHO_MAX_BIOS){
			$this->voltar('warning', 'BIOS', 'A BIOS tem de ter menos de '.Armazem::legivel(TAMANHO_MAX_BIOS).'.', 'admin/emulador');
		}

		$nome = Armazem::nomeLimpo($f['name']);
		if($nome === ''){
			$this->voltar('warning', 'BIOS', 'O ficheiro precisa de um nome.', 'admin/emulador');
		}

		$disco = Armazem::nomeNovo($nome);
		if(!move_uploaded_file($f['tmp_name'], Armazem::pasta('bios')._P_.$disco)){
			$this->voltar('danger', 'BIOS', 'Não foi possível guardar o ficheiro no servidor.', 'admin/emulador');
		}

		Bios::guardar($consola, $disco, $nome);
		$this->voltar('success', 'BIOS', 'A BIOS de '.$c['nome'].' foi gravada.', 'admin/emulador');
	}

	public function apagarbios() {
		$this->so_admin();
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'admin/emulador');
		}

		$consola = (string)($_POST['consola'] ?? '');
		if(Consolas::existe($consola)){
			Bios::apagar($consola);
		}
		$this->voltar('success', 'BIOS', 'A BIOS foi apagada.', 'admin/emulador');
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
	Os campos comuns a criar e editar, já validados.

	Devolve o array pronto para o __add('dados'), ou a primeira queixa --
	volta-se com ela em vez de gravar meio registo.
	*/
	private function validarCampos() {
		$titulo    = trim((string)($_POST['titulo'] ?? ''));
		$consola   = (string)($_POST['consola'] ?? '');
		$descricao = trim((string)($_POST['descricao'] ?? ''));

		if($titulo === ''){ return 'Escreva o título do jogo.'; }
		if(mb_strlen($titulo) > self::MAX_TITULO){ return 'O título tem no máximo '.self::MAX_TITULO.' caracteres.'; }
		if(!Consolas::existe($consola)){ return 'Escolha a consola.'; }
		if(mb_strlen($descricao) > self::MAX_DESCRICAO){ return 'A descrição tem no máximo '.self::MAX_DESCRICAO.' caracteres.'; }

		return [
			'titulo_jg'    => $titulo,
			'consola_jg'   => $consola,
			'descricao_jg' => $descricao !== '' ? $descricao : null,
		];
	}

	/*
	A capa enviada, se houver.

	    ''                    não veio capa nenhuma (é opcional)
	    'mensagem'            veio, mas não serve
	    ['disco' => ...]      veio e ficou guardada

	O tipo é lido do CONTEÚDO (finfo), e não da extensão nem do tipo que o
	browser diz: os dois vêm de quem envia, e um .jpg que é na verdade um
	HTML ia ser servido a toda a gente.
	*/
	private function receberCapa() {
		$f = $_FILES['capa'] ?? null;
		if(!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE){
			return '';
		}
		if($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])){
			return $this->erroEnvio($f['error']);
		}
		if($f['size'] > TAMANHO_MAX_CAPA){
			return 'A capa tem de ter menos de '.Armazem::legivel(TAMANHO_MAX_CAPA).'.';
		}

		$tipo = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
		if(!isset(self::TIPOS_CAPA[$tipo])){
			return 'A capa tem de ser uma imagem JPG, PNG, WEBP ou GIF.';
		}

		$disco = bin2hex(random_bytes(16)).'.'.self::TIPOS_CAPA[$tipo];
		if(!move_uploaded_file($f['tmp_name'], Armazem::pasta('capas')._P_.$disco)){
			return 'Não foi possível guardar a capa no servidor.';
		}
		return ['disco' => $disco];
	}

	//o jogo enviado aos pedaços, já completo e na pasta dos jogos
	private function fecharEnvio($consola) {
		$id = (string)($_POST['envio'] ?? '');
		if(!Armazem::envioValido($id)){
			return 'Escolha o ficheiro do jogo e espere que o envio chegue aos 100%.';
		}

		//a extensão foi verificada no iniciar(), mas a consola pode ter
		//mudado no formulário entretanto
		$nome = $_SESSION['envios'][$id]['nome'];
		if(!Consolas::aceita($consola, $nome)){
			return 'O ficheiro '.$nome.' não serve para '.Consolas::nome($consola).'.';
		}

		$r = Armazem::envioConcluir($id, 'jogos');
		if($r === null){
			return 'O envio não chegou completo ao servidor. Envie o ficheiro outra vez.';
		}
		return $r;
	}

	//o jogo descarregado de um link, já na pasta dos jogos
	private function trazerDoLink($consola) {
		$link = trim((string)($_POST['link'] ?? ''));
		if($link === '' || strlen($link) > self::MAX_LINK){
			return 'Escreva o link do jogo (no máximo '.self::MAX_LINK.' caracteres).';
		}

		$r = Descarga::buscar($link, TAMANHO_MAX_JOGO);
		if(is_string($r)){ return $r; }

		/*
		O nome que o link deu nem sempre traz a extensão certa (muitos
		alojamentos dão "download" ou "file"). Para isso existe o campo
		"nome do ficheiro", que manda sobre o do link.
		*/
		$nomeDado = Armazem::nomeLimpo($_POST['nome_ficheiro'] ?? '');
		$nome = $nomeDado !== '' ? $nomeDado : $r['nome'];

		if($nome === '' || !Consolas::aceita($consola, $nome)){
			@unlink($r['caminho']);
			return 'O ficheiro descarregado chama-se "'.$r['nome'].'", e não tem uma extensão que sirva para '
				.Consolas::nome($consola).' ('.implode(', ', Consolas::extensoes($consola)).'). '
				.'Escreva o nome certo no campo "Nome do ficheiro" e tente outra vez.';
		}

		$disco = Armazem::nomeNovo($nome);
		if(!rename($r['caminho'], Armazem::pasta('jogos')._P_.$disco)){
			@unlink($r['caminho']);
			return 'Não foi possível guardar o ficheiro no servidor.';
		}

		return ['disco' => $disco, 'nome' => $nome, 'tamanho' => $r['tamanho'], 'origem' => $link];
	}

	private function erroEnvio($codigo) {
		switch ((int)$codigo) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE: return 'O ficheiro passa do limite do servidor (upload_max_filesize).';
			case UPLOAD_ERR_PARTIAL:   return 'O ficheiro chegou só em parte. Tente outra vez.';
			case UPLOAD_ERR_NO_FILE:   return 'Escolha um ficheiro.';
			default:                   return 'O servidor não conseguiu receber o ficheiro (erro '.(int)$codigo.').';
		}
	}

	//uma resposta JSON, para os pedidos feitos por JavaScript
	private function json($dados, $codigo = 200) {
		http_response_code($codigo);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($dados);
		exit;
	}
}
