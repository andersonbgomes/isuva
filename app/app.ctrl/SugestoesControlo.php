<?php
/*
SUGERIR UM JOGO: o jogador cola um link, e o administrador decide.

    /sugestoes            o formulário e as sugestões desta conta
    /sugestoes/enviar     (POST) grava a sugestão
    /sugestoes/apagar/7   (POST) retira uma sugestão que ainda está à espera

PRECISA DE CONTA: este controlador não está em Acao::SEM_SESSAO. Os
limites (Sugestoes::POR_ESPERAR, POR_DIA) contam por conta; sem conta
qualquer um enchia a lista do administrador.

O lado do administrador (ver, aceitar, recusar) vive no AdminControlo,
ao lado do "Adicionar jogo" -- aceitar uma sugestão É adicionar um jogo.
*/
class SugestoesControlo extends Acao {

	public function index() {
		/*
		O formulário pode vir preenchido: o "Jogar a partir de um link"
		(jogar/local) oferece "sugerir este jogo" com o link e a consola.
		São só valores iniciais de campos -- vão por esc() na vista, e o
		enviar() valida-os de raiz como a qualquer outro.
		*/
		$this->ver->inicio = [
			'link'    => mb_substr(trim((string)($_GET['link'] ?? '')), 0, Sugestoes::MAX_LINK),
			'consola' => Consolas::existe((string)($_GET['consola'] ?? '')) ? (string)$_GET['consola'] : '',
			'titulo'  => mb_substr(trim((string)($_GET['titulo'] ?? '')), 0, AdminControlo::MAX_TITULO),
		];
		$this->ver->sugestoes = Sugestoes::daConta(utilizador_actual());
		$this->renderizar('index');
	}

	public function enviar() {
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'sugestoes');
		}

		$titulo  = trim((string)($_POST['titulo'] ?? ''));
		$consola = (string)($_POST['consola'] ?? '');
		$link    = trim((string)($_POST['link'] ?? ''));
		$nome    = Armazem::nomeLimpo($_POST['nome_ficheiro'] ?? '');
		$nota    = trim((string)($_POST['nota'] ?? ''));

		if($titulo === ''){
			$this->voltar('warning', 'Falta corrigir', 'Escreva o nome do jogo.', 'sugestoes');
		}
		if(mb_strlen($titulo) > AdminControlo::MAX_TITULO){
			$this->voltar('warning', 'Falta corrigir', 'O nome tem no máximo '.AdminControlo::MAX_TITULO.' caracteres.', 'sugestoes');
		}
		if(!Consolas::existe($consola)){
			$this->voltar('warning', 'Falta corrigir', 'Escolha a consola.', 'sugestoes');
		}
		$ok = Sugestoes::linkValido($link);
		if($ok !== true){
			$this->voltar('warning', 'Falta corrigir', $ok, 'sugestoes');
		}
		if($nome !== '' && !Consolas::aceita($consola, $nome)){
			$this->voltar('warning', 'Falta corrigir', 'Um ficheiro "'.$nome.'" não serve para '.Consolas::nome($consola)
				.'. Aceites: '.implode(', ', Consolas::extensoes($consola)).'.', 'sugestoes');
		}
		if(mb_strlen($nota) > Sugestoes::MAX_NOTA){
			$this->voltar('warning', 'Falta corrigir', 'A nota tem no máximo '.Sugestoes::MAX_NOTA.' caracteres.', 'sugestoes');
		}

		$pode = Sugestoes::podeSugerir(utilizador_actual());
		if($pode !== true){
			$this->voltar('warning', 'Ainda não', $pode, 'sugestoes');
		}
		$repetido = Sugestoes::repetido($link);
		if($repetido !== null){
			$this->voltar('info', 'Já cá está', $repetido, 'sugestoes');
		}

		$s = new Sugestao;
		$s->__add('dados', [
			'us_sg'      => utilizador_actual(),
			'titulo_sg'  => $titulo,
			'consola_sg' => $consola,
			'link_sg'    => $link,
			'nome_sg'    => $nome !== '' ? $nome : null,
			'nota_sg'    => $nota !== '' ? $nota : null,
			'estado_sg'  => Sugestoes::ESPERA,
			'dtc_sg'     => date('Y-m-d H:i:s'),
		]);
		$s->inserir();

		if(!$s->result){
			_registar_erro('Sugestao', (string)$s->sms);
			$this->voltar('danger', 'Erro', 'Não foi possível gravar a sugestão. Tente outra vez daqui a pouco.', 'sugestoes');
		}

		$this->voltar('success', 'Obrigado', 'A sugestão foi enviada. Quando o administrador a vir, o resultado aparece aqui.', 'sugestoes');
	}

	public function apagar() {
		if($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()){
			$this->voltar('danger', 'Erro', 'A sessão expirou. Tente outra vez.', 'sugestoes');
		}

		//a conta vai no filtro: o número de outra pessoa no endereço não apaga nada
		$stt = Con::ecta()->prepare("DELETE FROM `app_sugestao` WHERE `id_sg` = ? AND `us_sg` = ? AND `estado_sg` = 0");
		$stt->execute([(int)id, utilizador_actual()]);

		$this->voltar('success', 'Retirada', 'A sugestão foi retirada.', 'sugestoes');
	}
}
