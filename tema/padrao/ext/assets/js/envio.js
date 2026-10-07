/*
ENVIAR UM FICHEIRO GRANDE AOS PEDAÇOS (ver AdminControlo::iniciar/pedaco).

Usado pelo "Adicionar jogo" e pelas BIOS. Um ficheiro passa num POST só
se for mais pequeno do que o upload_max_filesize do servidor -- que em
muitos alojamentos é 2 MB, menos do que uma BIOS de PS2. Aos pedaços, o
limite deixa de importar.

    var id = await enviarAosPedacos({
        ficheiro:  input.files[0],
        campos:    { consola: 'ps2', tipo: 'bios' },   // vão para o iniciar
        csrf:      '...',
        iniciar:   url_base('admin/iniciar'),
        pedaco:    url_base('admin/pedaco'),
        progresso: function (pct) { ... },             // opcional
        aviso:     function (texto) { ... },           // opcional
        parar:     function () { return false; }       // opcional: true cancela
    });

Devolve o identificador do envio (que vai no formulário, no campo
"envio"), ou null se parar() o mandou parar. Uma falha lança Error com
uma mensagem pronta a mostrar.
*/
(function () {
	function esperar(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }

	//a resposta em JSON, ou um erro legível (uma sessão expirada devolve HTML)
	function lerJson(r) {
		return r.json().catch(function () {
			return { erro: 'O servidor respondeu de forma inesperada. A sessão pode ter expirado: recarregue a página.' };
		});
	}

	window.enviarAosPedacos = async function (o) {
		var f = o.ficheiro;
		var aviso = o.aviso || function () {};
		var progresso = o.progresso || function () {};
		var parar = o.parar || function () { return false; };

		var fd = new FormData();
		fd.append('csrf_token', o.csrf);
		fd.append('nome', f.name);
		fd.append('tamanho', f.size);
		Object.keys(o.campos || {}).forEach(function (k) { fd.append(k, o.campos[k]); });

		var r = await fetch(o.iniciar, { method: 'POST', body: fd, credentials: 'same-origin' });
		var d = await lerJson(r);
		if (!r.ok || !d.envio) { throw new Error(d.erro || 'Não foi possível começar o envio.'); }

		var id = d.envio, tamanho = d.pedaco, pos = 0, falhas = 0;

		while (pos < f.size) {
			if (parar()) { return null; }

			try {
				r = await fetch(o.pedaco, {
					method: 'POST',
					credentials: 'same-origin',
					body: f.slice(pos, pos + tamanho),
					headers: {
						'Content-Type': 'application/octet-stream',
						'X-CSRF-Token': o.csrf,
						'X-Envio': id,
						'X-Posicao': String(pos)
					}
				});
				d = await lerJson(r);
			} catch (e) {
				/*
				Falha de rede: tenta outra vez, com uma espera cada vez maior.
				O servidor só aceita o pedaço na posição certa, por isso
				repetir nunca estraga o ficheiro.
				*/
				if (++falhas > 6) { throw new Error('A ligação falhou várias vezes seguidas. Tente outra vez.'); }
				aviso('A ligação falhou. A tentar outra vez...');
				await esperar(2000 * falhas);
				continue;
			}

			//409: o servidor diz onde o envio realmente está, e continua-se daí
			if (r.status === 409 && typeof d.posicao === 'number') { pos = d.posicao; continue; }
			if (!r.ok) { throw new Error(d.erro || 'O servidor recusou o envio.'); }

			pos = d.posicao;
			falhas = 0;
			progresso(Math.floor(pos / f.size * 100));
		}

		return id;
	};
})();
