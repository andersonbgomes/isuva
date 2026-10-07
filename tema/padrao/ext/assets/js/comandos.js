/*
OS COMANDOS (gamepads): reconhecer, dar nome, e garantir que jogam.

Usado no ecrã de jogo (o indicador #comando-estado) e na página de teste
(/comandos). Qualquer comando que o sistema operativo reconheça -- PS4,
PS5, Xbox, Switch Pro, genéricos, por USB ou Bluetooth -- chega ao browser
pela Gamepad API. Não é preciso nenhum programa.

AS TRÊS COISAS QUE FAZEM UM COMANDO "NÃO FUNCIONAR" (e que esta página diz):

1. O browser só mostra o comando ao site DEPOIS DE SE PREMIR UM BOTÃO com
   a página aberta. É de propósito (privacidade: um site não pode saber
   que comandos tem ligados sem os usar). Ligar por Bluetooth não chega.
2. Os browsers só dão comandos a páginas em HTTPS (isSecureContext). Num
   site em http://, o comando nunca aparece, faça-se o que se fizer.
3. Um comando com o "mapping" que não é "standard" (alguns genéricos, ou
   o da PS4 em certos sistemas) chega com os botões por outra ordem: joga,
   mas o botão X pode ser o quadrado. Corrige-se no menu de controlos do
   emulador, carregando em cada botão.

O QUE ISTO CORRIGE NO EMULATORJS: ele só atribui um comando a um jogador
no instante em que o comando aparece. Um comando que apareça num momento
em que isso não acontece (ligado durante o carregamento, desligado e
voltado a ligar) fica sem jogador -- e um comando sem jogador é ignorado.
atribuir() corre de segundo a segundo e põe qualquer comando sem jogador
no primeiro lugar livre.
*/
(function () {
	function lista() {
		var g = navigator.getGamepads ? navigator.getGamepads() : [];
		return Array.prototype.filter.call(g || [], Boolean);
	}

	/*
	Um nome que uma pessoa reconhece. O "id" que o browser dá é técnico
	("Wireless Controller (STANDARD GAMEPAD Vendor: 054c Product: 09cc)"):
	o fabricante tira-se do código do vendedor (054c é a Sony, 045e a
	Microsoft, 057e a Nintendo), que vem no id em todos os browsers.
	*/
	function nome(g) {
		var id = (g && g.id) || '';
		var baixo = id.toLowerCase();
		if (/054c/.test(baixo)) {
			if (/0ce6|0df2/.test(baixo) || /dualsense/.test(baixo)) { return 'Comando PS5 (DualSense)'; }
			return 'Comando PS4 (DualShock 4)';
		}
		if (/045e/.test(baixo) || /xbox|xinput/.test(baixo)) { return 'Comando Xbox'; }
		if (/057e/.test(baixo)) { return 'Comando Nintendo'; }
		if (/wireless controller/.test(baixo)) { return 'Comando PS4 (DualShock 4)'; }
		var curto = id.replace(/\s*\(.*$/, '').trim();
		return curto || 'Comando';
	}

	function atribuir() {
		var e = window.EJS_emulator;
		if (!e || !e.gamepad || !Array.isArray(e.gamepadSelection) || !e.gamepadSelection.length) { return; }
		var mudou = false;
		(e.gamepad.gamepads || []).forEach(function (g) {
			var chave = g.id + '_' + g.index;
			if (e.gamepadSelection.indexOf(chave) !== -1) { return; }
			var livre = e.gamepadSelection.indexOf('');
			if (livre !== -1) { e.gamepadSelection[livre] = chave; mudou = true; }
		});
		if (mudou) { try { e.updateGamepadLabels(); } catch (x) { /* o menu ainda não existe */ } }
	}

	//o jogador (1 a 4) a que o EmulatorJS deu este comando, ou 0
	function jogador(g) {
		var e = window.EJS_emulator;
		if (!e || !Array.isArray(e.gamepadSelection)) { return 0; }
		return e.gamepadSelection.indexOf(g.id + '_' + g.index) + 1;
	}

	window.IsuvaComandos = { lista: lista, nome: nome, jogador: jogador };

	//------------------------------------------------------------------
	// O indicador no ecrã de jogo
	//------------------------------------------------------------------

	var chip = document.getElementById('comando-estado');
	if (!chip) { return; }
	var texto = chip.querySelector('span');

	function mostrar(estado, msg, dica) {
		chip.className = 'comando-estado comando-' + estado;
		texto.textContent = msg;
		chip.title = dica || msg;
	}

	function actualizar() {
		if (!window.isSecureContext) {
			mostrar('mal', 'Comandos precisam de HTTPS', 'O browser só dá comandos a sites em https://. Fale com o administrador do site.');
			return;
		}
		var p = lista();
		if (!p.length) {
			mostrar('espera', 'Comando: prima um botão', 'Ligue o comando (USB ou Bluetooth) e prima um botão qualquer: só assim o browser o mostra ao site.');
			return;
		}

		atribuir();

		var g = p[0];
		var n = jogador(g);
		var msg = nome(g) + (n ? ' · Jogador ' + n : '') + (p.length > 1 ? ' (+' + (p.length - 1) + ')' : '');
		if (g.mapping !== 'standard') {
			mostrar('aviso', msg, 'Este comando não usa a disposição padrão: se os botões estiverem trocados, acerte-os no ícone do comando, na barra do emulador.');
		} else {
			mostrar('ok', msg, 'Comando pronto.');
		}
	}

	window.addEventListener('gamepadconnected', actualizar);
	window.addEventListener('gamepaddisconnected', actualizar);
	setInterval(actualizar, 1000);
	actualizar();
})();
