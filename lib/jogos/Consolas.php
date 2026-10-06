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

O QUE FICA DE FORA, E PORQUÊ

PS2, PS3, GameCube, Wii e Switch não têm emulador que corra no browser
com velocidade jogável. Essas consolas só entram na Fase 2, a correr num
servidor com placa gráfica e com a imagem enviada por streaming -- não é
uma questão de acrescentar uma linha aqui.
*/
class Consolas {

	//os .zip e .7z servem para todas: o EmulatorJS descomprime-os sozinho
	//e procura lá dentro o ficheiro certo (o .cue de um jogo de PS1, por exemplo)
	const COMPRIMIDOS = ['zip', '7z'];

	const LISTA = [
		'nes' => [
			'nome' => 'Nintendo (NES)', 'familia' => 'Nintendo', 'nucleo' => 'nes',
			'ext' => ['nes', 'fds', 'unf', 'unif'], 'bios' => 'nao', 'threads' => false,
			'nota' => 'Muito leve. Corre em qualquer computador ou telemóvel.',
		],
		'snes' => [
			'nome' => 'Super Nintendo', 'familia' => 'Nintendo', 'nucleo' => 'snes',
			'ext' => ['sfc', 'smc', 'fig', 'swc', 'bs'], 'bios' => 'nao', 'threads' => false,
			'nota' => 'Leve.',
		],
		'n64' => [
			'nome' => 'Nintendo 64', 'familia' => 'Nintendo', 'nucleo' => 'n64',
			'ext' => ['n64', 'z64', 'v64'], 'bios' => 'nao', 'threads' => false,
			'nota' => 'Pede um computador razoável.',
		],
		'gb' => [
			'nome' => 'Game Boy / Game Boy Color', 'familia' => 'Nintendo', 'nucleo' => 'gb',
			'ext' => ['gb', 'gbc', 'dmg'], 'bios' => 'nao', 'threads' => false,
			'nota' => 'Muito leve.',
		],
		'gba' => [
			'nome' => 'Game Boy Advance', 'familia' => 'Nintendo', 'nucleo' => 'gba',
			'ext' => ['gba'], 'bios' => 'opcional', 'threads' => false,
			'nota' => 'Leve. A BIOS é opcional.',
		],
		'nds' => [
			'nome' => 'Nintendo DS', 'familia' => 'Nintendo', 'nucleo' => 'nds',
			'ext' => ['nds'], 'bios' => 'opcional', 'threads' => false,
			'nota' => 'Pede um computador razoável.',
		],
		'segaMS' => [
			'nome' => 'Sega Master System', 'familia' => 'Sega', 'nucleo' => 'segaMS',
			'ext' => ['sms'], 'bios' => 'nao', 'threads' => false,
			'nota' => 'Muito leve.',
		],
		'segaGG' => [
			'nome' => 'Sega Game Gear', 'familia' => 'Sega', 'nucleo' => 'segaGG',
			'ext' => ['gg'], 'bios' => 'nao', 'threads' => false,
			'nota' => 'Muito leve.',
		],
		'segaMD' => [
			'nome' => 'Sega Mega Drive / Genesis', 'familia' => 'Sega', 'nucleo' => 'segaMD',
			'ext' => ['md', 'smd', 'gen', 'bin'], 'bios' => 'nao', 'threads' => false,
			'nota' => 'Leve.',
		],
		'segaCD' => [
			'nome' => 'Sega CD / Mega CD', 'familia' => 'Sega', 'nucleo' => 'segaCD',
			'ext' => ['cue', 'iso', 'chd', 'm3u'], 'bios' => 'obrigatoria', 'threads' => false,
			'nota' => 'Precisa da BIOS. Enviar o .cue e o .bin juntos num .zip, ou um .chd.',
		],
		'sega32x' => [
			'nome' => 'Sega 32X', 'familia' => 'Sega', 'nucleo' => 'sega32x',
			'ext' => ['32x'], 'bios' => 'nao', 'threads' => false,
			'nota' => 'Leve.',
		],
		'segaSaturn' => [
			'nome' => 'Sega Saturn', 'familia' => 'Sega', 'nucleo' => 'segaSaturn',
			'ext' => ['cue', 'iso', 'chd', 'ccd', 'm3u'], 'bios' => 'opcional', 'threads' => false,
			'nota' => 'Pesado e com compatibilidade irregular. A BIOS melhora muito.',
		],
		'psx' => [
			'nome' => 'PlayStation 1', 'familia' => 'PlayStation', 'nucleo' => 'psx',
			'ext' => ['cue', 'bin', 'img', 'iso', 'chd', 'pbp', 'm3u', 'ccd'], 'bios' => 'opcional', 'threads' => false,
			'nota' => 'Leve. Melhor em .chd, ou .cue + .bin juntos num .zip. A BIOS é opcional, mas recomendada.',
		],
		'psp' => [
			'nome' => 'PlayStation Portable (PSP)', 'familia' => 'PlayStation', 'nucleo' => 'psp',
			'ext' => ['iso', 'cso', 'pbp', 'elf'], 'bios' => 'nao', 'threads' => true,
			'nota' => 'Pede um computador bom e um browser recente (Chrome, Edge ou Firefox).',
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

	//as consolas agrupadas por família, para os <optgroup> e o catálogo
	public static function porFamilia() {
		$grupos = [];
		foreach (self::LISTA as $chave => $c) {
			$grupos[$c['familia']][$chave] = $c;
		}
		return $grupos;
	}

	//todas as extensões que uma consola aceita, comprimidos incluídos
	public static function extensoes($chave) {
		$c = self::pegar($chave);
		return $c ? array_merge($c['ext'], self::COMPRIMIDOS) : [];
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
