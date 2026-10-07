<?php
/*
Uma sugestão de jogo (tabela app_sugestao). Molde: o Usuario.php.

O jogador sugere (SugestoesControlo), o administrador aceita ou recusa
(AdminControlo::sugestoes). Aceitar NÃO cria o jogo aqui: abre o
formulário de "Adicionar jogo" já preenchido, e é o guardar() de lá que
descarrega o link e marca a sugestão como aceite.
*/
class Sugestao extends DB_GLOBAL {

	protected $tb = "`app_sugestao`";

	public function inserir(){     $this->__inserir();     }
	public function apagar(){      $this->__apagar();      }
	public function pegar(){       $this->__pegar();       }
	public function pegar_todos(){ $this->__pegar_todos(); }
	public function todos(){       $this->__todos();       }
	public function actualizar(){  $this->__atualizar();   }
}
