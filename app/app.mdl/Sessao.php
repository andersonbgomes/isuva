<?php
/*
Uma sessão de jogo num nó (tabela app_sessao). Quem a gere é o lib/jogos/Fila.php.

Molde: o Usuario.php.
*/
class Sessao extends DB_GLOBAL {

	protected $tb = "`app_sessao`";

	public function inserir(){     $this->__inserir();     }
	public function apagar(){      $this->__apagar();      }
	public function pegar(){       $this->__pegar();       }
	public function pegar_todos(){ $this->__pegar_todos(); }
	public function actualizar(){  $this->__atualizar();   }
}
