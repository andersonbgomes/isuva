<?php
/*
TESTAR UM COMANDO: /comandos

Uma página que mostra, botão a botão, o que o browser está a receber do
comando -- para a pessoa ver se o comando funciona ANTES de abrir um jogo,
e para quem ajuda perceber o que se passa ("o X não faz nada" é muito
diferente de "o browser nem vê o comando").

Pública (está em Acao::SEM_SESSAO): não toca em dados nenhuns, só lê o
comando no browser de quem a abre. O trabalho todo é JavaScript (a
Gamepad API) -- ver comandos/index.phtml e ext/assets/js/comandos.js.
*/
class ComandosControlo extends Acao {

	public function index() {
		$this->renderizar('index');
	}
}
