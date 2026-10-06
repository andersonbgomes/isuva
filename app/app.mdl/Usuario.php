<?php
/*
UM MODELO. É o molde de todos os outros.

Um modelo é uma tabela. Não tem lógica nenhuma -- só diz qual é a tabela
e quais os métodos que quer expor do DB_GLOBAL, que é quem monta e corre
a SQL.

PARA CRIAR OUTRO, copiar este ficheiro, mudar o nome da classe e o $tb:

    class Cliente extends DB_GLOBAL {
        protected $tb = "`app_cliente`";
        ...
    }

COMO SE USA, DE UM CONTROLADOR

    //ler uma linha
    $u = new Usuario;
    $u->__add('dados', ['id_us = ' => 7]);
    $u->pegar();
    $u->fetch['nome_us'];

    //ler várias, por ordem
    $u = new Usuario;
    $u->__add('dados', ['stto_us = ' => 1]);
    $u->__add('ordem', '`nome_us` ASC');
    $u->pegar_todos();
    foreach ($u->fetchall as $linha) { ... }

    //gravar
    $u = new Usuario;
    $u->__add('dados', ['nome_us' => 'Ana', 'email_us' => 'ana@exemplo.ao']);
    $u->inserir();
    $u->novoId;        //o id que acabou de nascer

    //alterar
    $u = new Usuario;
    $u->__add('dados', ['nome_us' => 'Ana Maria']);
    $u->__add('onde',  ['id_us = ' => 7]);
    $u->actualizar();

ATENÇÃO AO ESPAÇO E AO OPERADOR na chave ('id_us = ', 'AND stto_us = ').
É assim que o DB_GLOBAL monta o WHERE: a chave é SQL, o valor vai sempre
por bindValue(). Nunca colar texto ao VALOR na esperança de que ele seja
executado -- um 'ORDER BY ...' colado ao valor fica preso dentro de um
parâmetro e não ordena nada. Para ordenar existe o __add('ordem', ...).
*/
class Usuario extends DB_GLOBAL {

	protected $tb = "`app_utilizador`";

	public function inserir(){     $this->__inserir();     }
	public function apagar(){      $this->__apagar();      }
	public function pegar(){       $this->__pegar();       }
	public function pegar_todos(){ $this->__pegar_todos(); }
	public function actualizar(){  $this->__atualizar();   }
}
