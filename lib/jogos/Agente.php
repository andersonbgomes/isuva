<?php
/*
FALAR COM O AGENTE DE UM NÓ DE JOGO.

O agente (no-de-jogo/agente.py) é o programa que corre na máquina com
placa gráfica: recebe as sessões, arranca o PCSX2 num contentor por
jogador, e diz quando está pronto. Este ficheiro é o lado do site dessa
conversa -- e o agente tem o lado dele, que tem de assinar exactamente
da mesma maneira.

A ASSINATURA

O agente está na internet, e quem lá chegar pode pedir-lhe sessões (isto
é, gastar a placa gráfica). Cada pedido do site vai assinado com o segredo
do nó (segredo_no), por HMAC-SHA256 sobre:

    MÉTODO \n CAMINHO \n TEMPO \n sha256(corpo)

nos cabeçalhos X-Tempo e X-Assinatura. O TEMPO entra na conta para um
pedido apanhado a meio não poder ser repetido mais tarde: o agente
recusa tudo o que tenha mais de 2 minutos.

O CAMINHO INVERSO

O nó também pede coisas ao site: o ficheiro do jogo e a BIOS, que copia
para a cache dele. Esses endereços saem de urlFicheiro()/urlBios(), com
uma assinatura e um prazo no próprio endereço, e são verificados pelo
NoControlo -- que não tem sessão de utilizador, porque quem pede é uma
máquina.
*/
class Agente {

	const TIMEOUT_LIGAR = 5;
	const TIMEOUT_TOTAL = 15;

	//quanto tempo um endereço de ficheiro dado ao nó continua a valer:
	//chega para copiar uma ISO de 8 GB numa ligação lenta, com folga
	const PRAZO_FICHEIRO = 6 * 3600;

	//------------------------------------------------------------------
	// Os pedidos
	//------------------------------------------------------------------

	public static function estado($no) {
		return self::pedido($no, 'GET', '/estado');
	}

	public static function criarSessao($no, $dados) {
		return self::pedido($no, 'POST', '/sessoes', $dados);
	}

	public static function sessao($no, $idSessao) {
		return self::pedido($no, 'GET', '/sessoes/'.(int)$idSessao);
	}

	public static function vivo($no, $idSessao) {
		return self::pedido($no, 'POST', '/sessoes/'.(int)$idSessao.'/vivo', []);
	}

	public static function terminar($no, $idSessao) {
		return self::pedido($no, 'DELETE', '/sessoes/'.(int)$idSessao);
	}

	/*
	Um pedido assinado ao agente.

	Devolve sempre o mesmo formato, e nunca lança excepção: um nó
	desligado é uma coisa normal (a máquina reiniciou, a rede caiu), e
	quem chama decide o que fazer -- deixar o jogador na fila, mostrar o
	erro no painel.

	    ['ok' => bool, 'codigo' => int, 'dados' => array, 'erro' => string]
	*/
	public static function pedido($no, $metodo, $caminho, $corpo = null) {
		if(!function_exists('curl_init')){
			return ['ok' => false, 'codigo' => 0, 'dados' => [], 'erro' => 'O PHP deste servidor não tem a extensão curl.'];
		}

		$json  = $corpo === null ? '' : json_encode($corpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$tempo = (string)time();

		$ch = curl_init(rtrim($no['api_no'], '/').$caminho);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => $metodo,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_LIGAR,
			CURLOPT_TIMEOUT        => self::TIMEOUT_TOTAL,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'X-Tempo: '.$tempo,
				'X-Assinatura: '.self::assinar($no['segredo_no'], $metodo, $caminho, $tempo, $json),
			],
		]);
		if($json !== ''){
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
		}

		$resposta = curl_exec($ch);
		$codigo   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$erroCurl = curl_error($ch);
		curl_close($ch);

		if($resposta === false){
			return ['ok' => false, 'codigo' => 0, 'dados' => [], 'erro' => 'O nó não responde: '.$erroCurl];
		}

		$dados = json_decode((string)$resposta, true);
		if(!is_array($dados)){ $dados = []; }

		$ok = $codigo >= 200 && $codigo < 300;
		return [
			'ok'     => $ok,
			'codigo' => $codigo,
			'dados'  => $dados,
			'erro'   => $ok ? '' : (string)($dados['erro'] ?? ('O nó respondeu '.$codigo.'.')),
		];
	}

	public static function assinar($segredo, $metodo, $caminho, $tempo, $corpo) {
		$texto = strtoupper($metodo)."\n".$caminho."\n".$tempo."\n".hash('sha256', (string)$corpo);
		return hash_hmac('sha256', $texto, (string)$segredo);
	}

	//------------------------------------------------------------------
	// Os endereços que o nó usa para descarregar daqui
	//------------------------------------------------------------------

	/*
	O endereço do ficheiro de um jogo, para o nó o copiar.

	Assinado com o segredo DO NÓ, e com prazo: um endereço destes que vá
	parar a um registo qualquer deixa de servir ao fim de umas horas, e
	um nó desligado no painel deixa de conseguir descarregar logo (o
	NoControlo só aceita nós activos).
	*/
	public static function urlFicheiro($no, $jogo) {
		return self::urlAssinada($no, 'ficheiro', (int)$jogo['id_jg'], $jogo['nome_jg']);
	}

	public static function urlBios($no, $consola, $bios) {
		return self::urlAssinada($no, 'bios', $consola, $bios['nome']);
	}

	private static function urlAssinada($no, $tipo, $alvo, $nome) {
		$prazo = time() + self::PRAZO_FICHEIRO;
		$sig   = self::assinaturaFicheiro($no['segredo_no'], $tipo, $alvo, $prazo);
		return url_base('no/'.$tipo.'/'.rawurlencode((string)$alvo))
			.'?no='.(int)$no['id_no'].'&prazo='.$prazo.'&sig='.$sig.'&nome='.rawurlencode($nome);
	}

	public static function assinaturaFicheiro($segredo, $tipo, $alvo, $prazo) {
		return hash_hmac('sha256', $tipo.'|'.$alvo.'|'.(int)$prazo, (string)$segredo);
	}

	//------------------------------------------------------------------
	// O endereço por onde o jogador entra
	//------------------------------------------------------------------

	/*
	Cada lugar (slot) do nó é uma porta: o lugar 0 na porta_no, o 1 na
	porta_no + 1, e por aí fora. Ver no-de-jogo/gerar-nginx.py, que monta
	o nginx do nó com uma porta por lugar.

	Porquê portas, e não caminhos (/s/1/, /s/2/)? O cliente web do Selkies
	liga-se a /webrtc/signalling/ na RAIZ do servidor, sem prefixo. Um
	lugar por porta deixa cada sessão com a sua raiz, sem mexer no Selkies.
	*/
	public static function urlEntrada($no, $sessao) {
		$base = rtrim($no['publico_no'], '/');
		$porta = (int)$no['porta_no'] + (int)$sessao['slot_ss'];
		return $base.':'.$porta.'/entrar?s='.(int)$sessao['id_ss'].'&t='.rawurlencode($sessao['token_ss']);
	}
}
