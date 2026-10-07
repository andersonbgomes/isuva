<?php
/*
AS GRAVAÇÕES DA CONTA: a página "As minhas gravações", e o que o
gravacoes.js (na página do jogo) pede.

    /gravacoes                       a lista, com descarregar e apagar
    /gravacoes/ler/<jogo>?tipo=&slot=   (GET) os bytes de uma gravação, ou 404
    /gravacoes/iniciar               (POST, JSON) abre um envio aos pedaços
    /gravacoes/pedaco                (POST, JSON) um pedaço
    /gravacoes/guardar               (POST, JSON) fecha o envio e grava
    /gravacoes/descarregar/<id>      a gravação como ficheiro, para guardar à parte
    /gravacoes/apagar/<id>           (POST)

CADA CONTA SÓ VÊ AS SUAS. Todas as leituras e escritas vão com o
utilizador da sessão no filtro; nada aqui aceita um id de utilizador
vindo de fora. Mudar o número no endereço dá 404, não a gravação de
outra pessoa.

PORQUE É QUE ATÉ UMA GRAVAÇÃO DE 8 KB VAI AOS PEDAÇOS: os estados (de
PSP, de N64) passam facilmente dos 8 MB do post_max_size. Um caminho só,
o mesmo envio.js dos jogos, em vez de dois com regras diferentes.
*/
class GravacoesControlo extends Acao {

	public function index() {
		$this->ver->gravacoes = Gravacoes::daConta(utilizador_actual());
		$this->ver->usado     = Gravacoes::usado(utilizador_actual());
		$this->renderizar('lista');
	}

	public function ler() {
		$k = $this->chaveDoPedido($_GET, id);
		if(is_string($k)){
			$this->json(['erro' => $k], 404);
		}

		$g = Gravacoes::pegar(utilizador_actual(), $k);
		if(!$g){
			//404 é a resposta normal de "ainda não há gravação": o
			//gravacoes.js conta com ela e não a trata como erro
			$this->json(['erro' => 'Ainda não há gravação.'], 404);
		}

		header('X-Gravacao-Data: '.strtotime($g['actualizado_gv']));
		Armazem::servir(Armazem::caminho('gravacoes', $g['ficheiro_gv']), $g['tipo_gv'].'.bin', 'application/octet-stream', false);
	}

	public function iniciar() {
		$this->soPost();

		$k = $this->chaveDoPedido($_POST, $_POST['jogo'] ?? 0);
		if(is_string($k)){
			$this->json(['erro' => $k], 422);
		}

		$tamanho = (int)($_POST['tamanho'] ?? 0);
		$cabe = Gravacoes::cabe(utilizador_actual(), $k, $tamanho);
		if($cabe !== true){
			$this->json(['erro' => $cabe], 422);
		}

		$id = Armazem::envioNovo($k['tipo'].'.bin', $tamanho, 'gravacao');
		$this->json(['envio' => $id, 'pedaco' => Armazem::tamanhoPedaco()]);
	}

	public function pedaco() {
		$this->soPost();
		list($codigo, $dados) = Armazem::receberPedaco();
		$this->json($dados, $codigo);
	}

	public function guardar() {
		$this->soPost();

		$k = $this->chaveDoPedido($_POST, $_POST['jogo'] ?? 0);
		if(is_string($k)){
			$this->json(['erro' => $k], 422);
		}

		$envio = (string)($_POST['envio'] ?? '');
		if(!Armazem::envioValido($envio) || ($_SESSION['envios'][$envio]['tipo'] ?? '') !== 'gravacao'){
			$this->json(['erro' => 'O envio da gravação não chegou completo.'], 422);
		}

		$r = Armazem::envioConcluir($envio, 'gravacoes');
		if($r === null){
			$this->json(['erro' => 'O envio da gravação não chegou completo.'], 422);
		}

		$ok = Gravacoes::registar(utilizador_actual(), $k, $r['disco'], $r['tamanho']);
		if($ok !== true){
			$this->json(['erro' => $ok], 422);
		}
		$this->json(['ok' => true, 'data' => time()]);
	}

	public function descarregar() {
		$g = $this->minha(id);
		if(!$g){
			$this->voltar('warning', 'Gravações', 'Essa gravação não existe.', 'gravacoes');
		}

		//um nome que diga o que é, para quem a guardar no computador
		$nome = Armazem::nomeLimpo(($g['tipo_gv'] === 'cartao' ? 'cartao-'.$g['consola_gv'] : $this->tituloJogo($g['jogo_gv']).'-'.$g['tipo_gv'].($g['slot_gv'] ? $g['slot_gv'] : '')).'.bin');
		Armazem::servir(Armazem::caminho('gravacoes', $g['ficheiro_gv']), $nome, 'application/octet-stream', false, true);
	}

	public function apagar() {
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'gravacoes');
		}
		$g = $this->minha(id);
		if(!$g){
			$this->voltar('warning', 'Gravações', 'Essa gravação não existe.', 'gravacoes');
		}
		if(!Gravacoes::apagar($g)){
			$this->voltar('danger', 'Erro', 'Não foi possível apagar.', 'gravacoes');
		}
		$this->voltar('success', 'Gravações', 'A gravação foi apagada.', 'gravacoes');
	}


	//=============================================================
	// Ajudantes
	//=============================================================

	/*
	A chave da gravação a partir do pedido (tipo, slot) e do jogo.

	O jogo tem de existir e estar visível (ou quem pede ser administrador)
	-- senão bastava inventar ids de jogos para encher o disco.
	*/
	private function chaveDoPedido($fonte, $idJogo) {
		$tipo = (string)($fonte['tipo'] ?? '');
		if($tipo === 'cartao'){
			//o cartão da PS2 é escrito pelo nó de jogo, não pelo browser
			return 'O cartão de memória da PS2 é guardado pelo servidor de jogo.';
		}

		$j = new Jogo;
		$j->__add('dados', ['id_jg = ' => (int)$idJogo]);
		$j->pegar();
		$jogo = $j->fetch ?: [];
		if($jogo && !$this->e_admin() && (int)$jogo['stto_jg'] !== 1){
			$jogo = [];
		}

		return Gravacoes::chave($tipo, $jogo, '', $fonte['slot'] ?? 0);
	}

	private function minha($id) {
		$g = new Gravacao;
		$g->__add('dados', ['id_gv = ' => (int)$id, 'AND us_gv = ' => utilizador_actual()]);
		$g->pegar();
		return $g->fetch ?: [];
	}

	private function tituloJogo($id) {
		$j = new Jogo;
		$j->__add('dados', ['id_jg = ' => (int)$id]);
		$j->pegar();
		return $j->fetch['titulo_jg'] ?? 'jogo';
	}

	private function soPost() {
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
}
