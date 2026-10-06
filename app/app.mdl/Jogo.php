<?php
/*
Um jogo do catálogo (tabela app_jogo). Molde: o Usuario.php.

Os ficheiros não vivem aqui -- a linha só guarda os NOMES deles no
armazém (ver lib/jogos/Armazem.php). Apagar a linha não apaga o ficheiro:
isso é o AdminControlo::apagar() que faz, pelos dois lados.
*/
class Jogo extends DB_GLOBAL {

	protected $tb = "`app_jogo`";

	public function inserir(){     $this->__inserir();     }
	public function apagar(){      $this->__apagar();      }
	public function pegar(){       $this->__pegar();       }
	public function pegar_todos(){ $this->__pegar_todos(); }
	public function todos(){       $this->__todos();       }
	public function actualizar(){  $this->__atualizar();   }
}
