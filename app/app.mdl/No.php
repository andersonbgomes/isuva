<?php
/*
Um nó de jogo (tabela app_no). Ver lib/jogos/Agente.php para falar com ele.

Molde: o Usuario.php.
*/
class No extends DB_GLOBAL {

	protected $tb = "`app_no`";

	public function inserir(){     $this->__inserir();     }
	public function apagar(){      $this->__apagar();      }
	public function pegar(){       $this->__pegar();       }
	public function pegar_todos(){ $this->__pegar_todos(); }
	public function actualizar(){  $this->__atualizar();   }
}
