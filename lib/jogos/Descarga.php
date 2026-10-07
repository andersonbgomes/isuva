<?php
/*
TRAZER UM JOGO DE UM LINK PARA O ARMAZÉM.

PORQUE É QUE É O SERVIDOR A DESCARREGAR, E NÃO O BROWSER

A primeira ideia era guardar só o link e deixar o EmulatorJS ir buscá-lo
na hora de jogar. Não funciona com quase nenhum link: o browser só deixa
uma página ler um ficheiro de OUTRO site se esse site o autorizar (CORS),
e Google Drive, Mega, Dropbox e a maior parte dos alojamentos não
autorizam. O jogo aparecia no catálogo e falhava sempre ao abrir.

Descarregado pelo servidor, o CORS não se mete, e o jogo fica servido
como qualquer outro -- incluindo para quem jogar quando o link original
já tiver morrido.

O CUIDADO QUE ISTO EXIGE (SSRF)

Um servidor que vai buscar "o endereço que lhe dão" pode ser mandado
buscar coisas que só ele alcança: o painel do router, o MySQL na rede
interna, os metadados da nuvem em 169.254.169.254 (que entregam as
chaves da máquina). Mesmo sendo uma função só de administrador, fecha-se:

    - só http e https;
    - o nome é resolvido AQUI e recusado se der um IP privado, local ou
      reservado;
    - a ligação é feita a ESSE IP (CURLOPT_RESOLVE), e não a uma segunda
      resolução -- senão um DNS que responde uma coisa à verificação e
      outra à ligação (DNS rebinding) passava por cima de tudo;
    - os redireccionamentos seguem-se à mão, e cada um passa pela mesma
      verificação.
*/
class Descarga {

	const MAX_REDIRECCIONAMENTOS = 5;

	/*
	Descarrega $url para um ficheiro temporário do armazém.

	Devolve ['caminho' => ..., 'nome' => ..., 'tamanho' => ...] ou uma
	string com o motivo da falha, pronta a mostrar ao administrador.
	*/
	public static function buscar($url, $tamanhoMax) {
		if(!function_exists('curl_init')){
			return 'Este servidor não tem a extensão curl do PHP, que é precisa para descarregar por link.';
		}

		@set_time_limit(0);
		$destino = Armazem::pasta('envios') . _P_ . bin2hex(random_bytes(16)) . '.part';

		for ($salto = 0; $salto <= self::MAX_REDIRECCIONAMENTOS; $salto++) {
			$alvo = self::validar($url);
			if(is_string($alvo)){ return $alvo; }

			$fp = fopen($destino, 'wb');
			if(!$fp){ return 'Não foi possível escrever no servidor.'; }

			$nomeCabecalho = null;
			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_FILE            => $fp,
				CURLOPT_FOLLOWLOCATION  => false,
				CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
				CURLOPT_RESOLVE         => [$alvo['host'].':'.$alvo['porta'].':'.$alvo['ip']],
				CURLOPT_CONNECTTIMEOUT  => 20,
				CURLOPT_LOW_SPEED_LIMIT => 1024,
				CURLOPT_LOW_SPEED_TIME  => 60,
				CURLOPT_USERAGENT       => 'Mozilla/5.0 (compatible; '.preg_replace('/[^A-Za-z0-9 ]/', '', (string)configura('tit')).')',
				CURLOPT_NOPROGRESS      => false,
				//pára a descarga mal passe do limite, em vez de encher o disco primeiro
				CURLOPT_PROGRESSFUNCTION => function ($ch, $total, $recebido) use ($tamanhoMax) {
					return ($total > $tamanhoMax || $recebido > $tamanhoMax) ? 1 : 0;
				},
				CURLOPT_HEADERFUNCTION  => function ($ch, $linha) use (&$nomeCabecalho) {
					if(stripos($linha, 'content-disposition:') === 0
					   && preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";\r\n]+)"?/i', $linha, $m)){
						$nomeCabecalho = rawurldecode(trim($m[1]));
					}
					return strlen($linha);
				},
			]);

			$ok       = curl_exec($ch);
			$codigo   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$proximo  = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
			$erro     = curl_error($ch);
			$abortado = curl_errno($ch) === CURLE_ABORTED_BY_CALLBACK;
			curl_close($ch);
			fclose($fp);

			if($abortado){
				@unlink($destino);
				return 'O ficheiro passa do tamanho máximo ('.Armazem::legivel($tamanhoMax).').';
			}
			if($ok === false){
				@unlink($destino);
				return 'A descarga falhou: '.$erro;
			}

			if($codigo >= 300 && $codigo < 400 && $proximo){
				$url = $proximo;
				continue;
			}

			if($codigo !== 200){
				@unlink($destino);
				return 'O servidor do link respondeu '.$codigo.'. Confirme que o link é de descarga directa.';
			}

			clearstatcache(true, $destino);
			$tamanho = filesize($destino);
			if($tamanho <= 0){
				@unlink($destino);
				return 'O link devolveu um ficheiro vazio.';
			}

			$nome = $nomeCabecalho ?: rawurldecode(basename((string)parse_url($url, PHP_URL_PATH)));

			return ['caminho' => $destino, 'nome' => Armazem::nomeLimpo($nome), 'tamanho' => $tamanho];
		}

		@unlink($destino);
		return 'O link redirecciona demasiadas vezes.';
	}

	/*
	O endereço pode ser visitado pelo servidor?

	Devolve ['host', 'porta', 'ip'] ou a mensagem da recusa.
	*/
	private static function validar($url) {
		$p = parse_url((string)$url);
		$esquema = strtolower($p['scheme'] ?? '');

		if(!in_array($esquema, ['http', 'https'], true) || empty($p['host'])){
			return 'O link tem de começar por http:// ou https://.';
		}
		if(isset($p['user']) || isset($p['pass'])){
			return 'O link não pode levar utilizador e palavra-passe.';
		}

		$host  = trim($p['host'], '[]');
		$porta = (int)($p['port'] ?? ($esquema === 'https' ? 443 : 80));

		$ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
		if(!filter_var($ip, FILTER_VALIDATE_IP)){
			return 'Não foi possível encontrar o servidor '.$host.'.';
		}

		//privados (10.x, 192.168.x, ...) e reservados (127.x, 169.254.x, 0.x, ...)
		if(!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)){
			return 'Esse link aponta para a rede interna do servidor, e isso não é permitido.';
		}

		return ['host' => $host, 'porta' => $porta, 'ip' => $ip];
	}
}
