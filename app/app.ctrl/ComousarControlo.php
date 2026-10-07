<?php
/*
O "COMO USAR": os guias do site, um por cartão.

    /como-usar                  os cartões, por grupo
    /como-usar/guia/<chave>     um guia

O endereço tem hífen, e o router tira-o para achar a classe
(como-usar -> ComousarControlo); a pasta das vistas sai do nome da classe
(tema/padrao/comousar/). O texto dos guias vive em lib/jogos/Guias.php.

Os guias de administração só abrem para administradores: não basta não
mostrar o cartão, quem souber o endereço escrevia-o à mão.
*/
class ComousarControlo extends Acao {

	public function index() {
		$this->ver->grupos = Guias::visiveis($this->e_admin());
		$this->renderizar('index');
	}

	public function guia() {
		$chave = (string)id;
		$guia = Guias::pegar($chave, $this->e_admin());
		if($guia === null){
			$this->voltar('warning', 'Como usar', 'Esse guia não existe.', 'como-usar');
		}

		$this->ver->chave  = $chave;
		$this->ver->guia   = $guia;
		$this->ver->grupos = Guias::visiveis($this->e_admin());
		$this->renderizar('guia');
	}
}
