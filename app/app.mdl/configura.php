<?php
/*
Uma definição da aplicação (tabela app_config).

É a tabela onde fica o que se muda sem tocar no código: o nome do
sistema, o tema, o endereço. Lê-se em qualquer lado com

    configura('tit')

UMA CONSULTA, E NÃO UMA POR CHAVE. Isto é chamado dezenas de vezes por
página -- o título, o tema, o endereço de cada ficheiro do tema. Cada
chamada ser uma ida à base de dados era absurdo: traz-se a tabela toda
(são poucas linhas) na primeira chamada e o resto sai de memória.

CHAVE QUE NÃO EXISTE DEVOLVE ''. Uma definição que ainda não foi criada
-- uma instalação a meio de uma migração, por exemplo -- é uma definição
vazia, não um erro.
*/
function configura($chave){
	static $tudo = null;

	if($tudo === null){
		$tudo = [];

		/*
		A PRIMEIRA COISA QUE A APLICAÇÃO FAZ É ESTA -- e por isso é aqui
		que uma ligação falhada tem de ser apanhada.

		O lib/define.php chama configura() na primeira linha, logo no
		arranque. Se o Con::ecta() devolver null, o que vinha a seguir
		era `null->query(...)`, ou seja:

		    Call to a member function query() on null

		O try/catch abaixo não apanha isso: em PHP 7+ um Error não é uma
		Exception. O resultado era a aplicação inteira a morrer com uma
		mensagem que não diz nada sobre o problema real -- que é só uma
		password errada, ou uma base de dados por criar.
		*/
		if(Con::ecta() === null){
			_pagina_erro_bd('O Con::ecta() devolveu null: o app/Conecta.php deste servidor está a engolir o erro da ligação em vez de o mostrar. Comparar com o app/Conecta.exemplo.php.');
		}

		try {
			$qr = Con::ecta()->query("SELECT `conf_chave`, `conf_valor` FROM `app_config`");
			foreach ($qr->fetchAll(PDO::FETCH_ASSOC) as $linha) {
				$tudo[$linha['conf_chave']] = $linha['conf_valor'];
			}
		} catch (Throwable $e) {
			/*
			LIGOU MAS NÃO HÁ TABELAS: a base de dados foi criada e o SQL
			de instalação ainda não foi importado.

			É o passo exactamente a seguir a acertar o Conecta.php, e sem
			isto o que se via era uma página em branco: sem app_config não
			há `tema`, e o renderizar() ia buscar uma vista a `tema//...`
			que não existe. Nenhum erro, nenhuma pista -- só um ecrã
			vazio. Vale mais dizer o que falta.

			Só para este caso. Qualquer outra falha degrada para
			definições vazias, que é o comportamento antigo: a aplicação
			pode estar a meio de uma migração, e não é por uma consulta
			que falhou que se fecha a porta a toda a gente.
			*/
			$tabelaEmFalta = ($e instanceof PDOException)
			                 && (($e->getCode() === '42S02')
			                     || strpos($e->getMessage(), "doesn't exist") !== false);

			if($tabelaEmFalta){
				_pagina_erro_bd(
					'A ligação à base de dados funciona, mas a tabela `app_config` não existe. '
					.'Falta importar o instalacao/instalacao.sql — ou correr o instalador, em instalar/. '
					.'Mensagem do MySQL: '.$e->getMessage()
				);
			}

			$tudo = [];
		}
	}

	return $tudo[$chave] ?? '';
}
