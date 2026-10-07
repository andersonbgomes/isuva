<?php
/*
AS BIOS DE CADA CONSOLA.

Algumas consolas não arrancam sem a BIOS original (Sega CD), e outras
correm melhor com ela (PS1, Saturn). A BIOS tem direitos de autor e não
pode vir com o site: o administrador envia a sua, uma por consola, em
Admin > Emulador e BIOS.

Fica na app_config, na chave bios_<consola>, como JSON com o nome no
disco e o nome original. O nome original importa: os núcleos procuram a
BIOS pelo nome (scph5501.bin, bios_CD_U.bin...) e o EmulatorJS tira-o do
fim do endereço -- tal e qual como com os jogos.

Sem tabela própria de propósito: é no máximo uma linha por consola, e a
app_config já é lida inteira em cada página (ver configura()).
*/
class Bios {

	//a BIOS de uma consola, ['disco' => ..., 'nome' => ...], ou []
	public static function de($consola) {
		if(!Consolas::existe($consola)){ return []; }
		$b = json_decode((string)configura('bios_'.$consola), true);
		return (is_array($b) && !empty($b['disco']) && !empty($b['nome'])) ? $b : [];
	}

	/*
	Grava (ou troca) a BIOS de uma consola.

	O "INSERT ... ON DUPLICATE KEY UPDATE" é escrito à mão porque o
	DB_GLOBAL não o sabe fazer -- e é a forma de gravar uma definição
	exista ela ou não (a conf_chave é UNIQUE).
	*/
	/*
	Com a BIOS guarda-se também QUEM a enviou, QUANDO e o TAMANHO: a BIOS
	é do site inteiro (a padrão de cada consola), e um administrador que
	chegue depois tem de perceber, sem perguntar, que já lá está uma e de
	onde veio. São campos a mais no mesmo JSON da app_config -- as BIOS
	guardadas antes disto continuam a ler-se, só sem esses dados.
	*/
	public static function guardar($consola, $disco, $nome, $por = '') {
		$anterior = self::de($consola);
		$caminho = Armazem::caminho('bios', $disco);

		$stt = Con::ecta()->prepare(
			"INSERT INTO `app_config` (`conf_chave`, `conf_valor`) VALUES (?, ?)
			 ON DUPLICATE KEY UPDATE `conf_valor` = VALUES(`conf_valor`)"
		);
		$stt->execute(['bios_'.$consola, json_encode([
			'disco'   => $disco,
			'nome'    => $nome,
			'tamanho' => ($caminho && is_file($caminho)) ? filesize($caminho) : 0,
			'quando'  => date('Y-m-d H:i:s'),
			'por'     => mb_substr((string)$por, 0, 150),
		])]);

		//a antiga só sai depois de a nova estar gravada
		if($anterior && $anterior['disco'] !== $disco){
			Armazem::apagar('bios', $anterior['disco']);
		}
	}

	/*
	Uma BIOS que chegou num .zip (é assim que quase todas se descarregam).

	    browser   o .zip fica TAL E QUAL: o EmulatorJS abre-o sozinho e
	              põe cada ficheiro no sítio. Há consolas que precisam de
	              vários (a DS usa bios7.bin, bios9.bin e firmware.bin) --
	              tirar só um partia-as.
	    servidor  (PS2) o PCSX2 não lê .zip: tira-se de lá a BIOS
	              principal e é só essa que fica. O .zip é apagado.

	Devolve ['disco' => ..., 'nome' => ...] (o que fica guardado) ou a
	mensagem do problema. $disco é o ficheiro .zip já no armazém.
	*/
	public static function deZip($consola, $disco, $nome) {
		if(strtolower(pathinfo($nome, PATHINFO_EXTENSION)) !== 'zip' || !Consolas::noServidor($consola)){
			return ['disco' => $disco, 'nome' => $nome];
		}
		if(!class_exists('ZipArchive')){
			Armazem::apagar('bios', $disco);
			return 'O PHP deste servidor não abre .zip (falta a extensão zip). Extraia a BIOS e envie o ficheiro .bin.';
		}

		$zip = new ZipArchive;
		if($zip->open(Armazem::caminho('bios', $disco)) !== true){
			Armazem::apagar('bios', $disco);
			return 'O .zip não abre. Confirme que o ficheiro não está estragado.';
		}

		/*
		A BIOS principal da PS2 é um .bin de 4 MB (os outros ficheiros que
		os extractores criam -- .rom1, .erom, .nvm, .mec -- são extras de
		que os jogos não precisam). Escolhe-se o .bin de 4 MB, e entre
		vários o que se chama scph...; não havendo nenhum de 4 MB, o maior
		.bin.

		O tamanho vem do índice do .zip e é verificado ANTES de extrair:
		um .zip pequeno pode desdobrar-se em gigabytes (uma "bomba zip").
		*/
		$melhor = null;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$st = $zip->statIndex($i);
			$base = basename(str_replace('\\', '/', (string)$st['name']));
			if($base === '' || substr($st['name'], -1) === '/'){ continue; }
			if(strtolower(pathinfo($base, PATHINFO_EXTENSION)) !== 'bin'){ continue; }
			if($st['size'] <= 0 || $st['size'] > TAMANHO_MAX_BIOS){ continue; }

			$pontos = ($st['size'] === 4 * 1024 * 1024 ? 100 : 0) + (stripos($base, 'scph') === 0 ? 10 : 0);
			if($melhor === null || $pontos > $melhor['pontos'] || ($pontos === $melhor['pontos'] && $st['size'] > $melhor['size'])){
				$melhor = ['indice' => $i, 'nome' => $base, 'size' => $st['size'], 'pontos' => $pontos];
			}
		}

		if($melhor === null){
			$zip->close();
			Armazem::apagar('bios', $disco);
			return 'Não encontrei nenhuma BIOS (.bin) dentro do .zip.';
		}

		$dados = $zip->getFromIndex($melhor['indice'], TAMANHO_MAX_BIOS);
		$zip->close();
		Armazem::apagar('bios', $disco);

		if($dados === false || strlen($dados) !== (int)$melhor['size']){
			return 'Não foi possível tirar a BIOS de dentro do .zip.';
		}

		$nomeBios = Armazem::nomeLimpo($melhor['nome']);
		$novo = Armazem::nomeNovo($nomeBios);
		if(file_put_contents(Armazem::pasta('bios')._P_.$novo, $dados) === false){
			return 'Não foi possível guardar a BIOS no servidor.';
		}
		return ['disco' => $novo, 'nome' => $nomeBios];
	}

	public static function apagar($consola) {
		$anterior = self::de($consola);
		$stt = Con::ecta()->prepare("DELETE FROM `app_config` WHERE `conf_chave` = ?");
		$stt->execute(['bios_'.$consola]);
		if($anterior){
			Armazem::apagar('bios', $anterior['disco']);
		}
	}
}
