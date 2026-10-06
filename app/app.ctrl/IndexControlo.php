<?php
/*
A primeira página -- a que abre quando não se pede rota nenhuma (ver
rota_default, em lib/define.php).

Neste projecto a página de entrada é o catálogo de jogos, por isso aqui
só se reencaminha para lá. Fica o controlador, e não se muda o
rota_default, porque o router usa o mesmo rota_default como acção por
omissão ('index') -- mudá-lo para 'jogos' partia todas as rotas sem
acção.

O MOLDE de um controlador continua a ser o mesmo:

  - estende Acao;
  - tem um método PÚBLICO por acção (o index() é a acção por omissão);
  - junta o que a vista precisa em $this->ver->qualquercoisa;
  - acaba a chamar $this->renderizar('<nome da vista>').

O ENDEREÇO DE UMA ACÇÃO é /<rota>/<accao>/<id>, e dentro do controlador
estão nas constantes `rota`, `acao` e `id` (postas pelo vdr/App.php).
Só métodos públicos são rotas.
*/
class IndexControlo extends Acao {

	public function index() {
		header('Location:'.url_base('jogos'));
		exit;
	}
}
