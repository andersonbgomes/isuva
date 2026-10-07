/*
AS GRAVAÇÕES DO EMULADOR, GUARDADAS NO SERVIDOR (ver GravacoesControlo).

A página do jogo (jogar/ver.phtml) põe a configuração em
window.ISUVA_GRAVACOES e carrega este ficheiro ANTES do loader.js do
EmulatorJS -- é preciso, porque o loader só liga os EJS_on* que já
existirem quando ele corre.

DUAS COISAS DIFERENTES

1. A gravação do PRÓPRIO JOGO (a "sram": o cartão de memória da PS1, a
   pilha de um cartucho de SNES). O EmulatorJS escreve-a de x em x tempo
   e ao sair, e avisa com o evento "saveSaveFiles", com os bytes. Aqui
   sobe para o servidor, só quando mudou. Ao abrir o jogo, desce do
   servidor e entra no emulador antes de a pessoa chegar ao menu.

2. Os ESTADOS (os botões "Guardar estado" / "Carregar estado"). O
   EmulatorJS deixa substituir o que esses botões fazem (EJS_onSaveState,
   EJS_onLoadState): em vez de descarregar um ficheiro, vão ao servidor,
   no lugar (1 a 9) escolhido nas definições do emulador.

A ARMADILHA DO ARRANQUE

Entre o jogo arrancar e a gravação do servidor chegar há um instante em
que o emulador tem a gravação LOCAL (a do browser, que pode ser velha ou
vazia). Se o temporizador de gravação disparasse nesse instante, a
gravação vazia subia e apagava a do servidor. Por isso nada sobe antes
de "pronto" -- isto é, antes de a do servidor ter sido lida e aplicada.

O QUE FICA DE FORA

As teclas de atalho de estado rápido do EmulatorJS (não os botões) não
passam por estes eventos: esses estados continuam só no browser.
*/
(function () {
	var C = window.ISUVA_GRAVACOES;
	if (!C) { return; }

	//sem conta: o jogo corre e grava no browser, mas nada sobe -- ver visitante()
	if (!C.conta) { visitante(C); return; }

	var pronto = false;        //a gravação do servidor já foi aplicada
	var ultimaSram = null;     //o resumo da última sram que o servidor tem
	var aSubir = false;
	var pendente = null;       //uma sram que chegou enquanto outra subia

	function emu() { return window.EJS_emulator; }

	function aviso(texto) {
		try { emu().displayMessage(texto); } catch (e) { /* o emulador ainda não tem ecrã */ }
	}

	//um resumo dos bytes, para não subir 60 vezes por hora a mesma gravação
	async function resumo(bytes) {
		var h = await crypto.subtle.digest('SHA-256', bytes);
		return Array.from(new Uint8Array(h)).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
	}

	async function ler(tipo, slot) {
		var r = await fetch(C.ler + '?tipo=' + tipo + '&slot=' + (slot || 0), { credentials: 'same-origin', cache: 'no-store' });
		if (r.status === 404) { return null; }
		if (!r.ok) { throw new Error('O servidor não devolveu a gravação (' + r.status + ').'); }
		return new Uint8Array(await r.arrayBuffer());
	}

	async function guardar(tipo, slot, bytes) {
		var id = await enviarAosPedacos({
			ficheiro: new File([bytes], tipo + '.bin'),
			campos:   { tipo: tipo, jogo: C.jogo, slot: slot || 0 },
			csrf:     C.csrf,
			iniciar:  C.iniciar,
			pedaco:   C.pedaco
		});
		var fd = new FormData();
		fd.append('csrf_token', C.csrf);
		fd.append('envio', id);
		fd.append('tipo', tipo);
		fd.append('jogo', C.jogo);
		fd.append('slot', slot || 0);
		var r = await fetch(C.guardar, { method: 'POST', body: fd, credentials: 'same-origin' });
		var d = await r.json().catch(function () { return { erro: 'Resposta inesperada do servidor.' }; });
		if (!r.ok) { throw new Error(d.erro || 'O servidor recusou a gravação.'); }
	}

	//------------------------------------------------------------------
	// 1. A gravação do jogo (sram)
	//------------------------------------------------------------------

	async function subirSram(bytes) {
		if (!pronto || !bytes || !bytes.length) { return; }
		if (aSubir) { pendente = bytes; return; }

		aSubir = true;
		try {
			var h = await resumo(bytes);
			if (h !== ultimaSram) {
				await guardar('sram', 0, bytes);
				ultimaSram = h;
			}
		} catch (e) {
			//não interrompe o jogo: tenta outra vez na próxima gravação
			aviso('Não foi possível guardar no servidor: ' + e.message);
		}
		aSubir = false;

		if (pendente) { var p = pendente; pendente = null; subirSram(p); }
	}

	/*
	Ao arrancar: a gravação do servidor entra no emulador. Se o servidor
	ainda não tem nenhuma mas o browser tem (alguém que já jogava antes de
	isto existir), é a do browser que sobe -- ninguém perde o que tinha.
	*/
	async function aoArrancar() {
		var gm = emu().gameManager;
		try {
			var doServidor = await ler('sram', 0);

			var local = gm.getSaveFile(false);

			if (doServidor && !(local && await resumo(local) === await resumo(doServidor))) {
				var caminho = gm.getSaveFilePath();
				var partes = caminho.split('/'), pasta = '';
				for (var i = 0; i < partes.length - 1; i++) {
					if (!partes[i]) { continue; }
					pasta += '/' + partes[i];
					if (!gm.FS.analyzePath(pasta).exists) { gm.FS.mkdir(pasta); }
				}
				if (gm.FS.analyzePath(caminho).exists) { gm.FS.unlink(caminho); }
				gm.FS.writeFile(caminho, doServidor);
				gm.loadSaveFiles();
				/*
				E a consola reinicia. O EmulatorJS só deixa trocar a gravação
				DEPOIS de o jogo arrancar, e há jogos que a lêem logo no
				arranque: sem reiniciar, esses ficavam com a do browser (ou
				com nenhuma) até à próxima vez. Isto acontece no primeiro
				segundo, ainda antes do ecrã-título -- não se nota.
				*/
				gm.restart();
				aviso('Gravação carregada do servidor');
			}
			if (doServidor) { ultimaSram = await resumo(doServidor); }

			pronto = true;

			if (!doServidor && local && local.length) { subirSram(local); }
		} catch (e) {
			/*
			Sem a gravação do servidor, NÃO se passa a "pronto": senão a
			próxima gravação automática subia a local por cima da do
			servidor, que só não se conseguiu ler agora.
			*/
			aviso('Não foi possível ler a gravação do servidor. O progresso desta sessão não vai ser guardado lá.');
		}
	}

	//------------------------------------------------------------------
	// 2. Os estados (botões do emulador)
	//------------------------------------------------------------------

	function lugar() {
		var s = parseInt(emu().getSettingValue('save-state-slot'), 10);
		return (s >= 1 && s <= 9) ? s : 1;
	}

	window.EJS_onSaveState = function (d) {
		var slot = lugar();
		aviso('A guardar o estado ' + slot + ' no servidor...');
		guardar('estado', slot, d.state).then(function () {
			aviso('Estado ' + slot + ' guardado no servidor');
		}).catch(function (e) {
			aviso('O estado não foi guardado: ' + e.message);
		});
	};

	window.EJS_onLoadState = function () {
		var slot = lugar();
		ler('estado', slot).then(function (bytes) {
			if (!bytes) { aviso('Não há estado guardado no lugar ' + slot); return; }
			emu().gameManager.loadState(bytes);
			aviso('Estado ' + slot + ' carregado do servidor');
		}).catch(function (e) {
			aviso('O estado não foi carregado: ' + e.message);
		});
	};

	//------------------------------------------------------------------
	// A ligação ao emulador
	//------------------------------------------------------------------

	/*
	O EJS_onGameStart é ligado pelo loader ao evento "start". O
	saveSaveFiles não tem EJS_on* próprio: liga-se aqui, ao emulador, que
	já existe quando o jogo arranca.
	*/
	var antes = window.EJS_onGameStart;
	window.EJS_onGameStart = function () {
		if (typeof antes === 'function') { antes.apply(this, arguments); }
		emu().on('saveSaveFiles', function (bytes) { subirSram(bytes); });
		aoArrancar();
	};

	/*
	Ao sair da página, a última gravação. O EmulatorJS grava a sram no
	"exit"; aqui pede-se-lhe uma gravação final quando a página se esconde
	(fechar o separador, mudar de aplicação no telemóvel). É um esforço, não
	uma garantia: um separador fechado pode não esperar pelo envio -- e é
	por isso que também se grava de minuto a minuto.
	*/
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden' && pronto && emu() && emu().started) {
			try { emu().gameManager.saveSaveFiles(); } catch (e) { /* nada a fazer */ }
		}
	});
})();

/*
QUEM JOGA SEM CONTA.

O jogo corre e grava como sempre -- no browser (o EmulatorJS guarda a
gravação do jogo no IndexedDB). O que não há é servidor: por isso, quando o
jogo grava pela primeira vez, ou quando a pessoa carrega em "Guardar
estado", aparece o convite para criar conta (ou entrar, ou o Google).

Não se perde nada por criar a conta depois: na primeira vez que abrir o
jogo já com conta, a gravação que ficou no browser sobe sozinha (ver
aoArrancar, acima: "o servidor ainda não tem nenhuma mas o browser tem").

O convite aparece UMA vez por página, e se for fechado não volta: lembrar
uma vez é ajudar, lembrar a cada minuto é afastar quem só quer jogar.
*/
function visitante(C) {
	var mostrado = false;
	var inicial = null;      //a gravação que o browser tinha quando o jogo arrancou

	function emu() { return window.EJS_emulator; }

	async function resumo(bytes) {
		var h = await crypto.subtle.digest('SHA-256', bytes);
		return Array.from(new Uint8Array(h)).map(function (b) { return b.toString(16).padStart(2, '0'); }).join('');
	}

	function botao(texto, url, classe) {
		var a = document.createElement('a');
		a.className = 'btn ' + (classe || '');
		a.href = url;
		a.textContent = texto;
		return a;
	}

	function convite(motivo) {
		if (mostrado) { return; }
		mostrado = true;

		var caixa = document.createElement('div');
		caixa.className = 'convite';
		caixa.setAttribute('role', 'dialog');
		caixa.setAttribute('aria-label', 'Guardar o progresso');
		caixa.innerHTML = '<svg class="icone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>';

		var corpo = document.createElement('div');
		var titulo = document.createElement('strong');
		titulo.textContent = 'Guarde o seu progresso';
		var texto = document.createElement('p');
		texto.textContent = motivo === 'estado'
			? 'Para guardar estados precisa de uma conta. É grátis e demora um minuto: o jogo continua aqui.'
			: 'O jogo gravou, mas sem conta a gravação fica só neste browser. Crie uma conta (é grátis) para a guardar e continuar em qualquer computador.';

		var botoes = document.createElement('div');
		botoes.className = 'convite-botoes';
		botoes.appendChild(botao('Criar conta', C.registo));
		if (C.google) { botoes.appendChild(botao('Continuar com o Google', C.google, 'btn-google')); }
		botoes.appendChild(botao('Entrar', C.entrar, 'btn-claro'));

		corpo.appendChild(titulo);
		corpo.appendChild(texto);
		corpo.appendChild(botoes);
		caixa.appendChild(corpo);

		var fechar = document.createElement('button');
		fechar.type = 'button';
		fechar.className = 'convite-fechar';
		fechar.setAttribute('aria-label', 'Fechar');
		fechar.textContent = '\u2715';
		fechar.addEventListener('click', function () { caixa.remove(); });
		caixa.appendChild(fechar);

		document.body.appendChild(caixa);
	}

	//os botões de estado: sem conta não guardam no servidor -- explicam porquê
	window.EJS_onSaveState = function () { convite('estado'); };
	window.EJS_onLoadState = function () { convite('estado'); };

	/*
	A gravação do jogo: o EmulatorJS avisa de minuto a minuto, mesmo quando
	nada mudou. O convite só aparece quando a gravação é DIFERENTE da que
	havia ao arrancar -- isto é, quando a pessoa gravou mesmo alguma coisa.
	*/
	var antes = window.EJS_onGameStart;
	window.EJS_onGameStart = function () {
		if (typeof antes === 'function') { antes.apply(this, arguments); }
		var local = emu().gameManager.getSaveFile(false);
		(local && local.length ? resumo(local) : Promise.resolve('')).then(function (h) { inicial = h; });

		emu().on('saveSaveFiles', function (bytes) {
			if (!bytes || !bytes.length || inicial === null) { return; }
			resumo(bytes).then(function (h) { if (h !== inicial) { convite('sram'); } });
		});
	};
}

