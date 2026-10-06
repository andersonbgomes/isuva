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
	public static function guardar($consola, $disco, $nome) {
		$anterior = self::de($consola);

		$stt = Con::ecta()->prepare(
			"INSERT INTO `app_config` (`conf_chave`, `conf_valor`) VALUES (?, ?)
			 ON DUPLICATE KEY UPDATE `conf_valor` = VALUES(`conf_valor`)"
		);
		$stt->execute(['bios_'.$consola, json_encode(['disco' => $disco, 'nome' => $nome])]);

		//a antiga só sai depois de a nova estar gravada
		if($anterior && $anterior['disco'] !== $disco){
			Armazem::apagar('bios', $anterior['disco']);
		}
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
