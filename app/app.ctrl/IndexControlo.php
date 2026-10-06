<?php
/*
A primeira página -- a que abre quando não se pede rota nenhuma (ver
rota_default, em lib/define.php).

É também o molde de um controlador. Um controlador:

  - estende Acao;
  - tem um método PÚBLICO por acção (o index() é a acção por omissão);
  - junta o que a vista precisa em $this->ver->qualquercoisa;
  - acaba a chamar $this->renderizar('<nome da vista>').

O ENDEREÇO DE UMA ACÇÃO é /<rota>/<accao>/<id>:

    /                    IndexControlo::index()
    /clientes            ClientesControlo::index()
    /clientes/editar/7   ClientesControlo::editar(), com id == 7

Dentro do controlador, a rota, a acção e o id estão nas constantes
`rota`, `acao` e `id` -- postas pelo router (vdr/App.php).

SÓ MÉTODOS PÚBLICOS SÃO ROTAS. Um método que não deva ser alcançável
pelo endereço declara-se `private` ou `protected` -- o router não lhe
pega.
*/
class IndexControlo extends Acao {

	public function index() {
		$this->ver->titulo = 'Olá Mundo';
		$this->ver->agora  = date('d/m/Y H:i');

		$this->renderizar('inicio');
	}
}
