<?php
/*
A ligação à base de dados.

COMO SE USA ISTO

Este ficheiro é um MODELO. Copiar para `app/Conecta.php` (que está no
.gitignore, e é assim que tem de ser -- a password de produção nunca entra
no git) e preencher as quatro definições abaixo com os dados do servidor:

    cp app/Conecta.exemplo.php app/Conecta.php

Num alojamento partilhado (Hostinger, cPanel), os quatro valores estão no
painel, em "Bases de dados MySQL". Atenção a dois enganos frequentes:

  - o nome da base de dados e o do utilizador vêm com o prefixo da conta
    (ex.: u850363695_app), e não apenas "app";
  - o utilizador tem de estar ATRIBUÍDO à base de dados no painel. Criar os
    dois não chega -- sem a atribuição, a password está certa e a ligação é
    recusada na mesma.

PORQUE E QUE O catch NAO PODE FICAR VAZIO

A versão anterior deste ficheiro apanhava a PDOException, deitava a
mensagem fora e fazia `header('Location:instalar')` sem `exit`. O resultado
era o pior de todos os mundos: a execução continuava, o método acabava sem
`return` e devolvia **null**; quem chamasse fazia `Con::ecta()->prepare()`
sobre esse null; e o que ficava no log era

    Call to a member function prepare() on null

três ficheiros à frente do sítio onde a coisa realmente correu mal. Uma
password errada, uma base de dados por criar e um utilizador sem permissões
davam os três exactamente este erro -- e o motivo real, que o MySQL diz em
texto claro, não era escrito em lado nenhum. (A rota 'instalar' também não
existe nesta aplicação, por isso nem o redireccionamento fazia alguma coisa.)

Aqui o catch grava o motivo real no lib/logs/erros.log, mostra uma página
que diz o que se passa, e PARA. Nunca devolve null.
*/

define('_HOST_', 'localhost');
define('_DB_',   'nome_da_base_de_dados');
define('_US_',   'utilizador');
define('_PS_',   'password');

class Con {

	/*
	A ligação é uma só por pedido.

	Era um `new PDO` a cada chamada -- e há centenas por página. Além do
	desperdício, partia o que dependesse de estado da ligação: uma
	transacção aberta numa chamada não existia na seguinte, e o
	lastInsertId() vinha de uma ligação que não era a que tinha inserido.
	*/
	private static $conn = null;

	public static function ecta() {
		if (self::$conn !== null) {
			return self::$conn;
		}

		try {
			self::$conn = new PDO(
				"mysql:host=" . _HOST_ . ";dbname=" . _DB_ . ";charset=utf8mb4",
				_US_,
				_PS_,
				[
					PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					//os inteiros e os decimais chegam como números e não
					//como texto -- as contas da facturação contam com isso
					PDO::ATTR_EMULATE_PREPARES => false,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				]
			);
		} catch (PDOException $e) {
			//o motivo real vai para o log; no ecrã fica uma página que diz
			//o que se passa, sem expor o nome do utilizador nem da base de
			//dados a quem passar pelo endereço. E pára aqui -- ver o
			//comentário no topo, e _pagina_erro_bd() em lib/erros.php
			_pagina_erro_bd($e->getMessage());
		}

		return self::$conn;
	}
}
