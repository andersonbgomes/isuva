<?php
/*
O QUE O NÓ DE JOGO DESCARREGA DAQUI: o ficheiro do jogo e a BIOS.

    /no/ficheiro/<id do jogo>?no=..&prazo=..&sig=..&nome=..
    /no/bios/<consola>?no=..&prazo=..&sig=..&nome=..

SEM SESSÃO DE UTILIZADOR (está em Acao::SEM_SESSAO): quem pede é o agente
do nó, uma máquina, que não entra com e-mail e palavra-passe. Em troca, a
protecção é esta, e é toda obrigatória:

    - o endereço tem de vir assinado com o segredo do nó (ver
      Agente::urlAssinada) -- e só o site e o nó conhecem esse segredo;
    - o nó tem de estar ACTIVO: desligar um nó no painel corta-lhe as
      descargas logo, mesmo as de endereços já entregues;
    - o prazo tem de não ter passado (endereços de 6 horas).

O "nome" no fim do endereço é só para o agente saber a extensão do
ficheiro. Não entra na assinatura porque não decide nada: o ficheiro
servido é sempre o do jogo/consola assinado.

Qualquer falha responde 403 sem dizer qual das três foi -- quem está a
tentar adivinhar não precisa de ajuda.
*/
class NoControlo extends Acao {

	public function index() {
		$this->negar();
	}

	public function ficheiro() {
		$this->validar('ficheiro', (string)(int)id);

		$j = new Jogo;
		$j->__add('dados', ['id_jg = ' => (int)id]);
		$j->pegar();
		$jogo = $j->fetch ?: [];
		if(!$jogo){ $this->negar(); }

		Armazem::servir(Armazem::caminho('jogos', $jogo['ficheiro_jg']), $jogo['nome_jg']);
	}

	public function bios() {
		$this->validar('bios', (string)id);

		$bios = Bios::de(id);
		if(!$bios){ $this->negar(); }

		Armazem::servir(Armazem::caminho('bios', $bios['disco']), $bios['nome']);
	}

	private function validar($tipo, $alvo) {
		$prazo = (int)($_GET['prazo'] ?? 0);
		$sig   = (string)($_GET['sig'] ?? '');

		if($prazo < time() || !preg_match('/^[a-f0-9]{64}$/', $sig)){
			$this->negar();
		}

		$n = new No;
		$n->__add('dados', ['id_no = ' => (int)($_GET['no'] ?? 0), 'AND stto_no = ' => 1]);
		$n->pegar();
		$no = $n->fetch ?: [];
		if(!$no){ $this->negar(); }

		$certa = Agente::assinaturaFicheiro($no['segredo_no'], $tipo, $alvo, $prazo);
		if(!hash_equals($certa, $sig)){
			$this->negar();
		}
	}

	private function negar() {
		http_response_code(403);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Negado.';
		exit;
	}
}
