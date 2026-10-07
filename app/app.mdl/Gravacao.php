<?php
/*
Uma gravação de uma conta (tabela app_gravacao). Quem a lê e escreve é o
lib/jogos/Gravacoes.php -- o ficheiro vive no armazém, a linha só o aponta.

Molde: o Usuario.php.
*/
class Gravacao extends DB_GLOBAL {

	protected $tb = "`app_gravacao`";

	public function inserir(){     $this->__inserir();     }
	public function apagar(){      $this->__apagar();      }
	public function pegar(){       $this->__pegar();       }
	public function pegar_todos(){ $this->__pegar_todos(); }
	public function actualizar(){  $this->__atualizar();   }
}
