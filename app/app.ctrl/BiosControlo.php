<?php
/*
A PÁGINA "BIOS": de que BIOS precisa cada consola, e se o site já a tem.

NÃO HÁ FICHEIROS PARA DESCARREGAR AQUI, e é de propósito. As BIOS têm
direitos de autor (Sony, Sega, Nintendo): pô-las a descarregar a quem
entra no site é distribuí-las, e é das primeiras coisas que fazem um site
destes ser fechado. E não faz falta -- o site já entrega a BIOS ao
emulador sozinho (JogarControlo::bios, NoControlo::bios), sem que a
pessoa tenha de a ter.

O que esta página dá é a resposta à pergunta que leva alguém a procurar
uma BIOS: "este jogo vai arrancar?". E, para quem quiser a sua, o guia
de como a tirar da própria consola (Guias, 'bios').
*/
class BiosControlo extends Acao {

	public function index() {
		$linhas = [];
		foreach (Consolas::porFamilia() as $familia => $lista) {
			foreach ($lista as $chave => $c) {
				$linhas[$familia][$chave] = ['consola' => $c, 'bios' => Bios::de($chave)];
			}
		}
		$this->ver->linhas = $linhas;
		$this->renderizar('index');
	}
}
