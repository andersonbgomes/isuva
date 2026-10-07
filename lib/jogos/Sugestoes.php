<?php
/*
AS SUGESTÕES DE JOGOS: um jogador cola um link, o administrador decide.

    0 à espera   o jogador acabou de sugerir
    1 aceite     o administrador gravou o jogo; jogo_sg diz qual
    2 recusada   motivo_sg (opcional) é o que o jogador lê

PORQUE É QUE O LINK NÃO ENTRA SOZINHO NO CATÁLOGO

Num site público, deixar qualquer pessoa pôr um jogo no catálogo era
deixar o servidor descarregar e servir a toda a gente o que um estranho
escolhesse: encher o disco, gastar a banda, e o site a distribuir o que
ninguém viu. Por isso a sugestão é só um pedido. O servidor NÃO visita o
link ao receber a sugestão; só quando o administrador aceita, e aí pelo
caminho de sempre (AdminControlo::guardar -> Descarga.php, com a
protecção SSRF).

OS LIMITES (contra quem enche a lista do administrador)

Só com conta; no máximo POR_ESPERAR sugestões à espera por conta, e
POR_DIA em 24 horas.
*/
class Sugestoes {

	const ESPERA  = 0;
	const ACEITE  = 1;
	const RECUSADA = 2;

	const POR_ESPERAR = 5;
	const POR_DIA     = 10;

	const MAX_LINK   = 500;
	const MAX_NOTA   = 500;
	const MAX_MOTIVO = 300;

	/*
	O link serve? Devolve true ou a queixa.

	Só se olha para a forma: http(s), sem utilizador e palavra-passe. Não
	se resolve o nome nem se liga a nada -- isso é a Descarga::buscar que
	faz, no dia em que o administrador aceitar.
	*/
	public static function linkValido($link) {
		if($link === '' || strlen($link) > self::MAX_LINK){
			return 'Cole o link do jogo (no máximo '.self::MAX_LINK.' caracteres).';
		}
		$p = parse_url($link);
		if(!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])){
			return 'O link tem de começar por http:// ou https://.';
		}
		if(isset($p['user']) || isset($p['pass'])){
			return 'O link não pode levar utilizador e palavra-passe.';
		}
		return true;
	}

	//esta conta ainda pode sugerir? true ou a queixa
	public static function podeSugerir($conta) {
		$stt = Con::ecta()->prepare(
			"SELECT SUM(`estado_sg` = 0), SUM(`dtc_sg` > ?) FROM `app_sugestao` WHERE `us_sg` = ?"
		);
		$stt->execute([date('Y-m-d H:i:s', time() - 86400), (int)$conta]);
		list($espera, $hoje) = $stt->fetch(PDO::FETCH_NUM);

		if((int)$espera >= self::POR_ESPERAR){
			return 'Já tem '.self::POR_ESPERAR.' sugestões à espera. Espere que o administrador as veja.';
		}
		if((int)$hoje >= self::POR_DIA){
			return 'Já fez '.self::POR_DIA.' sugestões nas últimas 24 horas. Volte amanhã.';
		}
		return true;
	}

	/*
	O mesmo link já está à espera, ou já é um jogo do catálogo? Devolve a
	mensagem, ou null. Poupa ao administrador ver três vezes o mesmo.
	*/
	public static function repetido($link) {
		$stt = Con::ecta()->prepare("SELECT 1 FROM `app_sugestao` WHERE `link_sg` = ? AND `estado_sg` = 0 LIMIT 1");
		$stt->execute([$link]);
		if($stt->fetchColumn()){
			return 'Esse link já foi sugerido e está à espera do administrador.';
		}
		$stt = Con::ecta()->prepare("SELECT 1 FROM `app_jogo` WHERE `origem_jg` = ? LIMIT 1");
		$stt->execute([$link]);
		if($stt->fetchColumn()){
			return 'Esse jogo já está no catálogo.';
		}
		return null;
	}

	//as sugestões de uma conta, as mais recentes primeiro, com o jogo (se já for)
	public static function daConta($conta) {
		$stt = Con::ecta()->prepare(
			"SELECT s.*, j.`titulo_jg`, j.`stto_jg`
			   FROM `app_sugestao` s
			   LEFT JOIN `app_jogo` j ON j.`id_jg` = s.`jogo_sg`
			  WHERE s.`us_sg` = ?
			  ORDER BY s.`id_sg` DESC
			  LIMIT 100"
		);
		$stt->execute([(int)$conta]);
		return $stt->fetchAll(PDO::FETCH_ASSOC);
	}

	/*
	Para o administrador: as que estão à espera (as mais antigas primeiro,
	quem esperou mais vê-se primeiro) e as últimas já decididas.
	*/
	public static function paraRever() {
		$sql = "SELECT s.*, u.`nome_us`, u.`email_us`, j.`titulo_jg`
		          FROM `app_sugestao` s
		          LEFT JOIN `app_utilizador` u ON u.`id_us` = s.`us_sg`
		          LEFT JOIN `app_jogo` j ON j.`id_jg` = s.`jogo_sg`";
		$espera = Con::ecta()->query($sql." WHERE s.`estado_sg` = 0 ORDER BY s.`id_sg` ASC")->fetchAll(PDO::FETCH_ASSOC);
		$feitas = Con::ecta()->query($sql." WHERE s.`estado_sg` <> 0 ORDER BY s.`dtr_sg` DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
		return [$espera, $feitas];
	}

	public static function quantasEsperam() {
		return (int)Con::ecta()->query("SELECT COUNT(*) FROM `app_sugestao` WHERE `estado_sg` = 0")->fetchColumn();
	}

	public static function pegar($id) {
		$s = new Sugestao;
		$s->__add('dados', ['id_sg = ' => (int)$id]);
		$s->pegar();
		return $s->fetch ?: [];
	}

	//só passa de "à espera" para outro estado: decidir duas vezes não muda nada
	public static function decidir($id, $estado, $motivo = null, $jogo = null) {
		$stt = Con::ecta()->prepare(
			"UPDATE `app_sugestao` SET `estado_sg` = ?, `motivo_sg` = ?, `jogo_sg` = ?, `dtr_sg` = ?
			  WHERE `id_sg` = ? AND `estado_sg` = 0"
		);
		$stt->execute([(int)$estado, $motivo, $jogo, date('Y-m-d H:i:s'), (int)$id]);
		return $stt->rowCount() > 0;
	}
}
