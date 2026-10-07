<?php
/*
AS CONSOLAS QUE O SITE SABE CORRER, E COMO.

Uma lista fixa, no código, e não uma tabela: cada consola depende de um
núcleo do EmulatorJS que existe ou não existe -- acrescentar uma linha
numa tabela não faz aparecer um emulador. Quando o EmulatorJS ganhar uma
consola nova, acrescenta-se aqui uma entrada e está feito.

CADA ENTRADA

    nome      o que aparece no ecrã
    familia   para agrupar no catálogo e nos <select>
    nucleo    o EJS_core -- o nome que o EmulatorJS usa para escolher o
              emulador (ver getCores() no emulator.js deles)
    ext       as extensões aceites. O EmulatorJS escolhe o ficheiro a
              arrancar PELA EXTENSÃO do nome -- um .bin de PS1 sem .cue,
              ou um .iso com o nome errado, simplesmente não arranca. Por
              isso o envio recusa tudo o que não estiver aqui, em vez de
              aceitar e falhar só na hora de jogar.
    bios      'nao' | 'opcional' | 'obrigatoria'
    threads   o núcleo precisa de SharedArrayBuffer (só o PSP). Obriga a
              página do jogo a ir com os cabeçalhos COOP/COEP -- ver
              JogarControlo::isolar().
    nota      uma linha para quem escolhe a consola

    modo      'browser' ou 'servidor' -- ver abaixo
    max       só nas de 'servidor': o maior ficheiro aceite

DOIS MODOS DE CORRER

    browser   o EmulatorJS corre no browser de quem joga. Barato: o
              servidor só entrega a página e o ficheiro.
    servidor  o emulador corre num NÓ DE JOGO (uma máquina com placa
              gráfica, ver no-de-jogo/) e a imagem chega ao browser por
              streaming (WebRTC). É o único caminho para a PS2: não há
              emulador de PS2 que corra num browser a velocidade jogável.
              Cada jogador ocupa um lugar no nó enquanto joga -- é caro,
              e é por isso que há fila (ver lib/jogos/Fila.php).

A PS3 NÃO ESTÁ AQUI. Também seria 'servidor', mas o RPCS3 pede uma
máquina inteira por jogador e só corre bem uma parte dos jogos. Entra
quando houver um nó medido que o aguente -- e entra como a PS2, com um
'nucleo' que o no-de-jogo/ saiba arrancar.
*/
class Consolas {

	//os .zip e .7z servem para todas: o EmulatorJS descomprime-os sozinho
	//e procura lá dentro o ficheiro certo (o .cue de um jogo de PS1, por exemplo)
	const COMPRIMIDOS = ['zip', '7z'];

	const LISTA = [
		'nes' => [
			'nome' => 'Nintendo (NES)', 'familia' => 'Nintendo', 'nucleo' => 'nes',
			'ext' => ['nes', 'fds', 'unf', 'unif'], 'bios' => 'nao', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Muito leve. Corre em qualquer computador ou telemóvel.',
		],
		'snes' => [
			'nome' => 'Super Nintendo', 'familia' => 'Nintendo', 'nucleo' => 'snes',
			'ext' => ['sfc', 'smc', 'fig', 'swc', 'bs'], 'bios' => 'nao', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Leve.',
		],
		'n64' => [
			'nome' => 'Nintendo 64', 'familia' => 'Nintendo', 'nucleo' => 'n64',
			'ext' => ['n64', 'z64', 'v64'], 'bios' => 'nao', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Pede um computador razoável.',
		],
		'gb' => [
			'nome' => 'Game Boy / Game Boy Color', 'familia' => 'Nintendo', 'nucleo' => 'gb',
			'ext' => ['gb', 'gbc', 'dmg'], 'bios' => 'nao', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Muito leve.',
		],
		'gba' => [
			'nome' => 'Game Boy Advance', 'familia' => 'Nintendo', 'nucleo' => 'gba',
			'ext' => ['gba'], 'bios' => 'opcional', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Leve. A BIOS é opcional.',
		],
		'nds' => [
			'nome' => 'Nintendo DS', 'familia' => 'Nintendo', 'nucleo' => 'nds',
			'ext' => ['nds'], 'bios' => 'opcional', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Pede um computador razoável.',
		],
		'segaMS' => [
			'nome' => 'Sega Master System', 'familia' => 'Sega', 'nucleo' => 'segaMS',
			'ext' => ['sms'], 'bios' => 'nao', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Muito leve.',
		],
		'segaGG' => [
			'nome' => 'Sega Game Gear', 'familia' => 'Sega', 'nucleo' => 'segaGG',
			'ext' => ['gg'], 'bios' => 'nao', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Muito leve.',
		],
		'segaMD' => [
			'nome' => 'Sega Mega Drive / Genesis', 'familia' => 'Sega', 'nucleo' => 'segaMD',
			'ext' => ['md', 'smd', 'gen', 'bin'], 'bios' => 'nao', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Leve.',
		],
		'segaCD' => [
			'nome' => 'Sega CD / Mega CD', 'familia' => 'Sega', 'nucleo' => 'segaCD',
			'ext' => ['cue', 'iso', 'chd', 'm3u'], 'bios' => 'obrigatoria', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Precisa da BIOS. Enviar o .cue e o .bin juntos num .zip, ou um .chd.',
		],
		'sega32x' => [
			'nome' => 'Sega 32X', 'familia' => 'Sega', 'nucleo' => 'sega32x',
			'ext' => ['32x'], 'bios' => 'nao', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Leve.',
		],
		'segaSaturn' => [
			'nome' => 'Sega Saturn', 'familia' => 'Sega', 'nucleo' => 'segaSaturn',
			'ext' => ['cue', 'iso', 'chd', 'ccd', 'm3u'], 'bios' => 'opcional', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Pesado e com compatibilidade irregular. A BIOS melhora muito.',
		],
		'psx' => [
			'nome' => 'PlayStation 1', 'familia' => 'PlayStation', 'nucleo' => 'psx',
			'ext' => ['cue', 'bin', 'img', 'iso', 'chd', 'pbp', 'm3u', 'ccd'], 'bios' => 'opcional', 'threads' => false, 'modo' => 'browser',
			'nota' => 'Leve. Melhor em .chd, ou .cue + .bin juntos num .zip. A BIOS é opcional, mas recomendada.',
		],
		'psp' => [
			'nome' => 'PlayStation Portable (PSP)', 'familia' => 'PlayStation', 'nucleo' => 'psp',
			'ext' => ['iso', 'cso', 'pbp', 'elf'], 'bios' => 'nao', 'threads' => true, 'modo' => 'browser',
			'nota' => 'Pede um computador bom e um browser recente (Chrome, Edge ou Firefox).',
		],
		/*
		A PS2 corre no nó de jogo, com o PCSX2 (o 'nucleo' é o nome que o
		agente do nó reconhece). Sem .zip/.7z: o PCSX2 não os abre.
		Os jogos em CD de PS2 (poucos) vêm em .bin/.cue -- convertem-se
		para .chd, que é um ficheiro só e mais pequeno.

		Até 9 GB: um DVD de camada dupla tem 8,5 GB. O limite de 2 GB das
		outras é do browser, que aqui não carrega o jogo.
		*/
		'ps2' => [
			'nome' => 'PlayStation 2', 'familia' => 'PlayStation', 'nucleo' => 'pcsx2',
			'ext' => ['iso', 'chd', 'cso', 'zso'], 'bios' => 'obrigatoria', 'threads' => false,
			'modo' => 'servidor', 'max' => 9 * 1024 * 1024 * 1024,
			'nota' => 'Corre no nosso servidor e chega por streaming: precisa de boa internet, não de um bom computador. Precisa da BIOS. Melhor em .chd.',
		],
	];

	//a entrada de uma consola, ou null se a chave não existir
	public static function pegar($chave) {
		return self::LISTA[$chave] ?? null;
	}

	public static function existe($chave) {
		return is_string($chave) && isset(self::LISTA[$chave]);
	}

	public static function nome($chave) {
		return self::LISTA[$chave]['nome'] ?? (string)$chave;
	}

	/*
	As consolas agrupadas por família, para os <optgroup> e o catálogo.

	Com $modo ('browser' ou 'servidor'), só as desse modo -- o "jogar do
	meu computador" só serve as de browser: um jogo de PS2 do computador
	da pessoa teria de ser enviado inteiro para o nó primeiro.
	*/
	public static function porFamilia($modo = null) {
		$grupos = [];
		foreach (self::LISTA as $chave => $c) {
			if($modo !== null && $c['modo'] !== $modo){ continue; }
			$grupos[$c['familia']][$chave] = $c;
		}
		return $grupos;
	}

	/*
	Esta consola só se joga com conta? Duas razões, e só estas:

	    servidor            cada jogador ocupa uma placa gráfica (cara e
	                        contada): sem conta, qualquer pessoa -- ou um
	                        robô -- enchia os lugares todos;
	    BIOS obrigatória    a BIOS só vai para quem tem conta (num site
	                        aberto, o ficheiro que o browser recebe ficava ao
	                        alcance de qualquer visitante), e sem ela esta
	                        consola não arranca.

	As outras jogam-se sem conta: as que usam BIOS opcional arrancam com a
	de substituição que o emulador já traz.
	*/
	public static function precisaConta($chave) {
		$c = self::pegar($chave);
		return $c !== null && ($c['modo'] === 'servidor' || $c['bios'] === 'obrigatoria');
	}

	public static function noServidor($chave) {
		return (self::LISTA[$chave]['modo'] ?? '') === 'servidor';
	}

	//todas as extensões que uma consola aceita (comprimidos só nas de browser:
	//é o EmulatorJS que os abre, e o PCSX2 não sabe)
	public static function extensoes($chave) {
		$c = self::pegar($chave);
		if(!$c){ return []; }
		return $c['modo'] === 'browser' ? array_merge($c['ext'], self::COMPRIMIDOS) : $c['ext'];
	}

	//o maior ficheiro aceite para uma consola (ver TAMANHO_MAX_JOGO)
	public static function tamanhoMax($chave) {
		return (int)(self::LISTA[$chave]['max'] ?? TAMANHO_MAX_JOGO);
	}

	/*
	O nome do ficheiro serve para esta consola?

	Pela extensão, e só por ela -- é a mesma coisa que o EmulatorJS olha.
	Ver o conteúdo do ficheiro não adiantava: um .bin de PS1 e um .bin de
	Mega Drive são os dois "dados binários" para qualquer verificação.
	*/
	public static function aceita($chave, $nomeFicheiro) {
		$ext = strtolower(pathinfo((string)$nomeFicheiro, PATHINFO_EXTENSION));
		return $ext !== '' && in_array($ext, self::extensoes($chave), true);
	}
}
