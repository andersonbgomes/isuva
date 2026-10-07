<?php
/*
ONDE FICAM OS FICHEIROS DOS JOGOS, E COMO SAEM DE LÁ.

Três pastas debaixo de armazem/ (ver PASTA_ARMAZEM em lib/define.php):

    jogos/    as ROMs e as ISOs
    capas/    as imagens do catálogo
    bios/     as BIOS de cada consola
    envios/   os pedaços de um envio que ainda não acabou

NENHUM FICHEIRO DAQUI É SERVIDO DIRECTAMENTE. O armazem/.htaccess fecha a
pasta, e tudo sai pelo JogarControlo, que verifica a sessão primeiro. É a
diferença entre "quem tem conta joga" e "quem adivinhar o endereço leva o
jogo" -- e os nomes no disco são aleatórios precisamente para não serem
adivinháveis, caso o servidor não leia o .htaccess (nginx, por exemplo).

O NOME NO DISCO NUNCA É O QUE VEIO DE FORA. Um nome enviado pelo browser
pode trazer "../", barras, nulos -- é gerado aqui, e o nome original fica
só na base de dados, para o endereço e para o ecrã.
*/
class Armazem {

	//------------------------------------------------------------------
	// Caminhos
	//------------------------------------------------------------------

	public static function pasta($qual) {
		$p = PASTA_ARMAZEM . _P_ . $qual;
		if(!is_dir($p)){
			@mkdir($p, 0775, true);
		}
		return $p;
	}

	/*
	O caminho de um ficheiro guardado, a partir do nome gravado na base
	de dados -- e só se o nome tiver a forma que este código gera.

	A base de dados é nossa, mas um nome que chegue aqui com "../" quer
	dizer que alguém lá chegou primeiro. Recusar é barato.
	*/
	public static function caminho($qual, $nome) {
		if(!is_string($nome) || !preg_match('/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9]{1,8})?$/', $nome)){
			return null;
		}
		return self::pasta($qual) . _P_ . $nome;
	}

	//um nome de disco novo, aleatório, com a extensão do original
	public static function nomeNovo($nomeOriginal) {
		$ext = strtolower(pathinfo((string)$nomeOriginal, PATHINFO_EXTENSION));
		$ext = preg_replace('/[^a-z0-9]/', '', $ext);
		return bin2hex(random_bytes(16)) . ($ext !== '' ? '.'.substr($ext, 0, 8) : '');
	}

	/*
	O nome original, limpo para poder ir no fim de um endereço.

	O EmulatorJS tira o nome do ficheiro do FIM DO ENDEREÇO (o último
	pedaço depois da barra) e escolhe o que arrancar pela extensão desse
	nome. É por isso que o endereço de um jogo é
	jogar/ficheiro/<id>/<nome-original> e não só jogar/ficheiro/<id>:
	sem o nome, um .cue ficava "game" e o núcleo não sabia o que fazer.
	*/
	public static function nomeLimpo($nome) {
		$nome = basename(str_replace('\\', '/', (string)$nome));
		$nome = preg_replace('/[^A-Za-z0-9 ._\-()\[\]]/', '_', $nome);
		$nome = trim(preg_replace('/\s+/', ' ', $nome), ' .');
		if(strlen($nome) > 180){
			$ext  = pathinfo($nome, PATHINFO_EXTENSION);
			$nome = substr($nome, 0, 170) . ($ext !== '' ? '.'.$ext : '');
		}
		return $nome;
	}

	public static function apagar($qual, $nome) {
		$c = self::caminho($qual, $nome);
		if($c !== null && is_file($c)){
			@unlink($c);
		}
	}

	//------------------------------------------------------------------
	// Tamanhos
	//------------------------------------------------------------------

	public static function legivel($bytes) {
		$bytes = (float)$bytes;
		foreach (['B', 'KB', 'MB', 'GB'] as $u) {
			if($bytes < 1024 || $u === 'GB'){
				return numero($bytes, $u === 'B' ? 0 : 1) . ' ' . $u;
			}
			$bytes /= 1024;
		}
	}

	//um valor do php.ini ("8M", "1G") em bytes
	public static function iniBytes($valor) {
		$valor = trim((string)$valor);
		if($valor === '' || $valor === '-1' || $valor === '0'){ return PHP_INT_MAX; }
		$n = (int)$valor;
		switch (strtolower(substr($valor, -1))) {
			case 'g': $n *= 1024;
			case 'm': $n *= 1024;
			case 'k': $n *= 1024;
		}
		return $n;
	}

	/*
	O tamanho de cada pedaço de um envio.

	Uma ISO não entra num POST só: o post_max_size de quase todos os
	alojamentos é 8 MB, ou menos. O browser corta o ficheiro em pedaços
	deste tamanho e manda-os um a um (ver admin/novo.phtml). Fica abaixo
	do limite do servidor com folga, para o cabeçalho do pedido caber, e
	nunca acima de 8 MB -- pedaços maiores não ganham nada e perdem mais
	quando a ligação falha a meio.
	*/
	public static function tamanhoPedaco() {
		$limite = self::iniBytes(ini_get('post_max_size'));
		return (int)max(256 * 1024, min(8 * 1024 * 1024, floor($limite * 0.8)));
	}

	//------------------------------------------------------------------
	// Envios aos pedaços
	//------------------------------------------------------------------

	//o identificador de um envio: 32 hexadecimais, gerados pelo servidor
	public static function envioValido($id) {
		return is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id)
			&& !empty($_SESSION['envios'][$id]);
	}

	//$tipo: 'jogo' ou 'bios' -- para um envio aberto para uma coisa não
	//poder ser fechado como a outra
	public static function envioNovo($nomeOriginal, $tamanho, $tipo = 'jogo') {
		$id = bin2hex(random_bytes(16));

		/*
		O envio fica preso à SESSÃO de quem o começou. Sem isto, quem
		soubesse (ou adivinhasse) o identificador de um envio alheio podia
		acrescentar-lhe pedaços seus, ou "acabá-lo" como se fosse dele.
		*/
		$_SESSION['envios'][$id] = [
			'nome'    => self::nomeLimpo($nomeOriginal),
			'tamanho' => (int)$tamanho,
			'tipo'    => $tipo,
			'inicio'  => time(),
		];

		//o ficheiro começa vazio, e cada pedaço acrescenta-se ao fim dele
		file_put_contents(self::caminhoEnvio($id), '');
		return $id;
	}

	public static function caminhoEnvio($id) {
		return self::pasta('envios') . _P_ . $id . '.part';
	}

	/*
	Acrescenta um pedaço ao envio.

	O browser diz em que posição o pedaço começa, e o servidor só o aceita
	se essa posição for exactamente o tamanho que já tem. Assim um pedaço
	repetido (o browser que tenta outra vez depois de uma falha de rede) ou
	fora de ordem é recusado, em vez de deixar um ficheiro corrompido que
	só se descobre ao jogar.

	Devolve o tamanho novo, ou uma string com o motivo da recusa.
	*/
	public static function envioPedaco($id, $posicao, $origem) {
		$caminho = self::caminhoEnvio($id);
		clearstatcache(true, $caminho);
		$actual = is_file($caminho) ? filesize($caminho) : -1;

		if($actual < 0){ return 'O envio já não existe. Comece outra vez.'; }
		if((int)$posicao !== $actual){ return 'pos:'.$actual; }

		$total = (int)$_SESSION['envios'][$id]['tamanho'];

		$in  = fopen($origem, 'rb');
		$out = fopen($caminho, 'ab');
		if(!$in || !$out){ return 'Não foi possível escrever no servidor.'; }

		$escritos = 0;
		while (!feof($in)) {
			$bloco = fread($in, 1024 * 1024);
			if($bloco === false){ break; }
			$escritos += strlen($bloco);

			//nunca mais do que o tamanho anunciado no início
			if($actual + $escritos > $total){
				fclose($in);
				//deita fora o que já entrou deste pedaço, para o envio
				//ficar como estava antes dele
				ftruncate($out, $actual);
				fclose($out);
				return 'O ficheiro é maior do que o anunciado.';
			}
			fwrite($out, $bloco);
		}
		fclose($in);
		fclose($out);

		return $actual + $escritos;
	}

	/*
	Um envio que acabou passa para a pasta de destino com um nome novo.

	Só se estiver COMPLETO: um envio interrompido a meio não pode virar
	jogo, porque falha só no momento de jogar e ninguém percebe porquê.
	*/
	public static function envioConcluir($id, $qual) {
		$info    = $_SESSION['envios'][$id];
		$caminho = self::caminhoEnvio($id);
		clearstatcache(true, $caminho);

		if(!is_file($caminho) || filesize($caminho) !== (int)$info['tamanho']){
			return null;
		}

		$nomeDisco = self::nomeNovo($info['nome']);
		if(!rename($caminho, self::pasta($qual) . _P_ . $nomeDisco)){
			return null;
		}
		unset($_SESSION['envios'][$id]);

		return ['disco' => $nomeDisco, 'nome' => $info['nome'], 'tamanho' => (int)$info['tamanho']];
	}

	public static function envioCancelar($id) {
		@unlink(self::caminhoEnvio($id));
		unset($_SESSION['envios'][$id]);
	}

	/*
	Os envios abandonados (o browser fechado a meio) ficavam a ocupar
	disco para sempre. Cada envio novo aproveita para varrer os que têm
	mais de um dia -- sem tarefa agendada, que num alojamento partilhado
	nem sempre há.
	*/
	public static function limparEnviosVelhos() {
		foreach (glob(self::pasta('envios') . _P_ . '*.part') ?: [] as $f) {
			if(filemtime($f) < time() - 86400){
				@unlink($f);
			}
		}
	}

	//------------------------------------------------------------------
	// Servir um ficheiro ao browser
	//------------------------------------------------------------------

	/*
	Envia um ficheiro guardado, com suporte a HEAD e a Range.

	O EmulatorJS faz um HEAD antes de descarregar, para comparar o tamanho
	com a cópia que tem na cache do browser -- sem o Content-Length certo,
	descarregava o jogo inteiro todas as vezes. O Range é para quem
	descarrega uma ISO de 1 GB e perde a ligação a meio.

	O session_write_close() vem antes de tudo: o PHP tranca a sessão
	enquanto o pedido dura, e uma ISO a descarregar durante minutos
	deixava o utilizador sem conseguir abrir mais nenhuma página do site.
	*/
	public static function servir($caminho, $nomeDownload, $tipo = 'application/octet-stream') {
		session_write_close();

		if($caminho === null || !is_file($caminho)){
			http_response_code(404);
			exit;
		}

		$tamanho = filesize($caminho);
		$inicio  = 0;
		$fim     = $tamanho - 1;

		if(isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)){
			if($m[1] === '' && $m[2] !== ''){
				//"bytes=-500": os últimos 500
				$inicio = max(0, $tamanho - (int)$m[2]);
			} else {
				$inicio = (int)$m[1];
				if($m[2] !== ''){ $fim = min($fim, (int)$m[2]); }
			}
			if($inicio > $fim || $inicio >= $tamanho){
				http_response_code(416);
				header('Content-Range: bytes */'.$tamanho);
				exit;
			}
			http_response_code(206);
			header("Content-Range: bytes {$inicio}-{$fim}/{$tamanho}");
		}

		header('Content-Type: '.$tipo);
		header('Content-Length: '.($fim - $inicio + 1));
		header('Accept-Ranges: bytes');
		header('Content-Disposition: inline; filename="'.str_replace('"', '', $nomeDownload).'"');
		header('X-Content-Type-Options: nosniff');
		//"private": cada um guarda a sua cópia, mas nenhum proxy a partilha
		header('Cache-Control: private, max-age=86400');

		/*
		Esta resposta é pedida por páginas com Cross-Origin-Embedder-Policy
		(as do PSP). Sendo do mesmo site não precisava, mas declarar não
		custa nada e evita surpresas se o armazém um dia for para outro
		domínio.
		*/
		header('Cross-Origin-Resource-Policy: same-origin');

		if($_SERVER['REQUEST_METHOD'] === 'HEAD'){
			exit;
		}

		//sem limite de tempo: uma ISO grande numa ligação lenta leva minutos
		@set_time_limit(0);
		while (ob_get_level() > 0) { ob_end_clean(); }

		$f = fopen($caminho, 'rb');
		fseek($f, $inicio);
		$falta = $fim - $inicio + 1;
		while ($falta > 0 && !feof($f) && !connection_aborted()) {
			$bloco = fread($f, (int)min(1024 * 1024, $falta));
			if($bloco === false){ break; }
			echo $bloco;
			flush();
			$falta -= strlen($bloco);
		}
		fclose($f);
		exit;
	}
}
