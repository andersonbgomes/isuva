<?php
abstract class DB_GLOBAL{

	//criando variaveis private
	//
	//`protected` e não `private`: há modelos que montam a sua própria query
	//e escrevem em $this->qr (ver um modelo que redefina pegar_todos()). Sendo private
	//aqui, essa escrita criava uma propriedade nova na subclasse em vez de
	//usar esta -- e o PHP 8.2 avisa a cada vez.
	protected $qr;

	/*
	$this->__add('ordem')= `nome do camo` ASC ou DESC nao e array
	$this->__add('criterio') = OR AND
	nos métodos select têm o onde que equivale ao where 
	a ordem que equivale a order
	e o critério que equivale ao AND/OR

	*/

	public $result;
	public $fetch = [];
	public $fetchall = [];
	public $todos;
	public $rw = 0;
	public $sms;
	public $novoId;

	/*
	As quatro que o __add() preenche.

	Estavam por declarar: `__add('dados', [...])` criava a propriedade em
	cima do joelho, e desde o PHP 8.2 isso é um aviso ("Creation of dynamic
	property"). Como todas as páginas fazem dezenas destas chamadas, o
	lib/logs/erros.log enchia-se de centenas de linhas por visita e os
	avisos que interessam ficavam enterrados no meio.

	Declaradas aqui, o comportamento é exactamente o mesmo -- só deixa de
	haver aviso. `null` por omissão é o que já valia antes de cada
	__add(), e os métodos verificam sempre antes de usar.
	*/
	public $dados;
	public $onde;
	public $ordem;
	public $criterio;

	 public function __add($nome, $valor){
	 		$this->$nome = $valor;
	}

	 public function __pega($nome){
	 		return $this->$nome;
	}

	//inserir
	protected function __inserir(){
		try {
            $colunas = implode(', ', array_keys($this->__pega('dados')));
            $valores = implode(', :', array_keys($this->__pega('dados')));

            $this->qr = "INSERT INTO {$this->tb} ({$colunas}) VALUES (:{$valores})";
            $con = Con::ecta();
            $stt = $con->prepare($this->qr);

            foreach ($this->__pega('dados') as $chave => $valor) {
                $stt->bindvalue(":{$chave}", $valor);
            }

            	if($stt->execute() === false){ throw new RuntimeException(implode(' ', $stt->errorInfo())); }
            	$this->result = true;
            	//id do registo inserido (mesma ligação usada no INSERT, para lastInsertId() ser fiável)
            	$this->novoId = $con->lastInsertId();

        } catch (Throwable $e) {
            $this->sms = 'Ocorreu um erro. Tente novamente '.$e->getMessage();
            $this->result = false;
        }
		return $this;	
	}

	//atualizar
	/*$dados = [ 
	'`func_email`' => 'maria@gmail.com',
	'`func_nome`' => 'Andre Buela Gomes'
	];
	$onde = [
	'`func_id`' => '4'
	];*/
	protected function __atualizar(){

		try {
          $this->qr = "UPDATE {$this->tb} SET ";
        	$arrbings = array();

	        foreach ($this->__pega('dados') as $chave => $valor) {
	            if ($valor === null) {
	                $this->qr .= "$chave = NULL, ";
	            } else {
	                //nota: ao contrário do WHERE, um valor array não tem tradução válida
	                //num SET (não existe "SET coluna IN (...)"); se chegar aqui, o bindvalue
	                //abaixo falha de forma clara em vez de gerar SQL inválido silenciosamente
	                $this->qr .= "$chave = ?, ";
	                $arrbings[] = $valor;
	            }
	        }
	        $this->qr = rtrim($this->qr, ', ');
	        $this->qr .= " WHERE ";
	        foreach ($this->__pega('onde') as $chave => $valor) {
	            if ($valor === null) {
	                $this->qr .= "$chave IS NULL ";
	            } elseif (is_array($valor)) {
	                $this->qr .= "$chave IN (" . rtrim(str_repeat("?,", count($valor)), ",") . ") ";
	                $arrbings = array_merge($arrbings, $valor);
	            } else {
	                /*
	                O ESPAÇO NO FIM NÃO É ENFEITE

	                Sem ele, duas condições ficavam coladas: a chave da
	                segunda vem sempre com o operador à frente ('AND emp_x ='),
	                e o que saía era

	                    WHERE id_x = ?AND emp_x = ?

	                que o MySQL recusa com "1064 You have an error in your SQL
	                syntax". O UPDATE/DELETE não dava erro na cara de ninguém
	                -- o actualizar()/apagar() apanha a excepção, põe a
	                mensagem em ->sms e devolve ->result = false --, por isso
	                o que se via era uma gravação que dizia ter corrido bem e
	                não tinha mudado nada.

	                Só acontecia com DUAS ou mais condições. Com uma só (o
	                caso mais comum, e o que os testes de cada módulo faziam)
	                funcionava, e foi por isso que passou despercebido tanto
	                tempo. Os outros métodos (__pegar, __pegar_todos, _todos)
	                já tinham o espaço; faltava aqui e no __apagar.
	                */
	                $this->qr .= "$chave ? ";
	                $arrbings[] = $valor;
	            }
	        }
	        $this->qr = rtrim($this->qr, ', ');

            $stt = Con::ecta()->prepare($this->qr);

           foreach ($arrbings as $i => $arrbing) {
	            $stt->bindvalue($i+1, $arrbing);
	        }

            	if($stt->execute() === false){ throw new RuntimeException(implode(' ', $stt->errorInfo())); }
            	$this->result = true;

        } catch (Throwable $e) {
            $this->sms = 'Ocorreu um erro. Tente novamente '.$e->getMessage();
            $this->result = false;
        }
		
		return $this;
	}

	/*
	$dados = [ 
	'`func_email` =' => 'acuco5@gmail.com', 
	'AND `func_pss` =' => 'f4a9cb127ccecb6ea8663a40a0bcbe6'
	];
	*/
	//listar 
	/*
	O ORDER BY de uma consulta montada pelo __add('ordem', ...).

	PORQUE É QUE ISTO EXISTE

	A propriedade `$ordem` e o comentário no topo deste ficheiro estão aqui
	desde o princípio -- mas nenhum dos métodos de leitura lhe pegava. Quem
	precisava de ordenar fazia o que parecia natural e colava o texto ao fim
	do VALOR da última condição:

	    __add('dados', ['emp_x ='=> $empresa.' ORDER BY `nome_x` ASC'])

	Isso nunca ordenou nada. O valor vai por bindValue(), portanto o "ORDER
	BY ..." fica preso dentro de um parâmetro e o MySQL limita-se a coagir a
	string a número para a comparação -- a query executa, o filtro até
	acerta por acaso, e a ordenação simplesmente não acontece. Estavam assim
	dezenas de consultas espalhadas por varios controladores, todas a sair
	por ordem de insercao, sem ninguem perceber porque.

	Agora ordena-se como estava previsto desde o início:

	    __add('ordem', '`nome_us` ASC')

	A LISTA BRANCA

	O ORDER BY não pode ir por parâmetro -- é SQL, não é valor. Por isso
	aceita-se só o que um ORDER BY legítimo tem: nomes entre crases, pontos,
	vírgulas, espaços e ASC/DESC. Qualquer outra coisa é ignorada (a
	consulta corre sem ordem nenhuma em vez de correr com algo que não se
	sabe o que é), e nada disto é sítio para pôr texto vindo de $_GET: uma
	ordenação escolhida pelo utilizador escolhe-se em PHP, de entre valores
	fixos, como se faz quando a ordenacao e escolhida pelo utilizador.
	*/
	private function __ordemSegura(){
		$ordem = isset($this->ordem) ? trim((string)$this->__pega('ordem')) : '';
		if($ordem === ''){ return ''; }

		//um nome de coluna, com ou sem crases, com ou sem tabela à frente
		$coluna = '(?:`[A-Za-z0-9_]+`|[A-Za-z_][A-Za-z0-9_]*)(?:\.(?:`[A-Za-z0-9_]+`|[A-Za-z_][A-Za-z0-9_]*))?(?:\s+(?:ASC|DESC))?';
		if(!preg_match('/^'.$coluna.'(?:\s*,\s*'.$coluna.')*$/i', $ordem)){
			loga('DB_GLOBAL: ordem recusada por não parecer um ORDER BY -> '.$ordem);
			return '';
		}

		/*
		A ordem só pode nomear a tabela DESTE modelo.

		Os métodos de leitura daqui fazem "SELECT * FROM <uma tabela só>" --
		não há JOIN nenhum. Uma coluna de outra tabela no ORDER BY não é uma
		ordenação que não acontece: é SQL inválido, e o MySQL recusa a
		consulta INTEIRA com "Unknown column 'x.y' in 'order clause'". O
		__pegar() apanha a excepção, o `rw` fica a 0, e quem chamou conclui
		que não existe linha nenhuma -- quando ela está lá.

		Ja custou caro uma vez: uma consulta pedia ORDER BY por uma coluna
		de OUTRA tabela e, a partir do momento em que esta ordenacao passou
		a ser mesmo aplicada (antes era ignorada por completo), a consulta
		inteira passou a falhar -- e o ecra respondia "nao existe" a um
		registo que estava la.

		Por isso não se deixa passar: tira-se o prefixo quando é o da
		própria tabela, e recusa-se a ordem toda quando é de outra. Perder a
		ordenação é um defeito pequeno; perder a consulta é um defeito que
		se disfarça de "não há dados".
		*/
		$minha = trim((string)$this->tb, '` ');
		$limpa = [];
		foreach (explode(',', $ordem) as $parte) {
			$parte = trim($parte);
			if(preg_match('/^`?([A-Za-z0-9_]+)`?\s*\.\s*(.+)$/', $parte, $m)){
				if(strcasecmp($m[1], $minha) !== 0){
					loga('DB_GLOBAL: ordem recusada por nomear `'.$m[1].'`, que não é a tabela da consulta (`'.$minha.'`) -> '.$ordem);
					return '';
				}
				$parte = trim($m[2]);
			}
			$limpa[] = $parte;
		}

		return ' ORDER BY '.implode(', ', $limpa);
	}

	protected function __pegar(){

		try {

	        $this->qr = "SELECT * FROM {$this->tb} WHERE ";
        	$arrbings = array();

	        foreach ($this->__pega('dados') as $chave => $valor) {
	            if ($valor === null) {
	                $this->qr .= "$chave IS NULL ";
	            } elseif (is_array($valor)) {
	                $this->qr .= "$chave IN (" . rtrim(str_repeat("?,", count($valor)), ",") . ") ";
	                $arrbings = array_merge($arrbings, $valor);
	            } else {
	                $this->qr .= "$chave ? ";
	                $arrbings[] = $valor;
	            }
	        }

	        $this->qr .= $this->__ordemSegura();

	       	$stt = Con::ecta()->prepare($this->qr);

	        foreach ($arrbings as $i => $arrbing) {
	            $stt->bindvalue($i+1, $arrbing);
	        }

            if($stt->execute() === false){ throw new RuntimeException(implode(' ', $stt->errorInfo())); }

            
            $this->fetch    = $stt->fetch(PDO::FETCH_ASSOC);
			$this->rw       = $stt->rowcount();
			$this->result   = true;


        } catch (Throwable $e) {
            $this->sms = 'Ocorreu um erro. Tente novamente '.$e->getMessage();
            $this->result = false;
        }
			return $this;
	}

	//monta o WHERE opcional a partir de __add('dados', [...]) — usado pelos métodos
	//abaixo para que, ao contrário da versão antiga, seja possível filtrar por
	//empresa (emp_x) em vez de devolver sempre uma linha/lista de qualquer empresa
	private function __ondeOpcional(){
		$dados = isset($this->dados) ? $this->__pega('dados') : [];
		$sql = '';
		$arrbings = array();
		if(!empty($dados)){
			$sql = ' WHERE ';
			foreach ($dados as $chave => $valor) {
				if ($valor === null) {
					$sql .= "$chave IS NULL ";
				} elseif (is_array($valor)) {
					$sql .= "$chave IN (" . rtrim(str_repeat("?,", count($valor)), ",") . ") ";
					$arrbings = array_merge($arrbings, $valor);
				} else {
					$sql .= "$chave ? ";
					$arrbings[] = $valor;
				}
			}
		}
		return [$sql, $arrbings];
	}

	//busca uma única linha; opcionalmente filtrada por __add('dados', [...]) — sem
	//filtro, devolve a primeira linha da tabela seja ela de que empresa for, por
	//isso: em tabelas com emp_x, define sempre 'dados' com o filtro de empresa
	protected function __pegar_unico(){

		try {
			list($ondeSql, $arrbings) = $this->__ondeOpcional();

	        $this->qr = "SELECT * FROM {$this->tb}{$ondeSql} LIMIT 1";
	       	$stt = Con::ecta()->prepare($this->qr);

	        foreach ($arrbings as $i => $arrbing) {
	            $stt->bindvalue($i+1, $arrbing);
	        }

            if($stt->execute() === false){ throw new RuntimeException(implode(' ', $stt->errorInfo())); }


            $this->fetch    = $stt->fetch(PDO::FETCH_ASSOC);
			$this->rw       = $stt->rowcount();
			$this->result   = true;


        } catch (Throwable $e) {
            $this->sms = 'Ocorreu um erro. Tente novamente '.$e->getMessage();
            $this->result = false;
        }
			return $this;
	}

	//busca a última linha segundo $ordem (ex.: '`id_us` DESC'); opcionalmente
	//filtrada por __add('dados', [...]) — $ordem tem de vir sempre fixo no código
	//do modelo, nunca de $_GET/$_POST (não é parametrizável, vai direto para a SQL)
	protected function __pegar_ultimo($ordem){

		try {
			list($ondeSql, $arrbings) = $this->__ondeOpcional();

	        $this->qr = "SELECT * FROM {$this->tb}{$ondeSql} ORDER BY {$ordem} LIMIT 1";
	       	$stt = Con::ecta()->prepare($this->qr);

	        foreach ($arrbings as $i => $arrbing) {
	            $stt->bindvalue($i+1, $arrbing);
	        }

            if($stt->execute() === false){ throw new RuntimeException(implode(' ', $stt->errorInfo())); }


            $this->fetch    = $stt->fetch(PDO::FETCH_ASSOC);
			$this->rw       = $stt->rowcount();
			$this->result   = true;


        } catch (Throwable $e) {
            $this->sms = 'Ocorreu um erro. Tente novamente '.$e->getMessage();
            $this->result = false;
        }
			return $this;
	}

	//lista TODAS as linhas da tabela, sem filtro nenhum — só para tabelas
	//sem filtro nenhum. Quando a tabela tem um dono (uma empresa, um
	//utilizador), usa antes pegar_todos(), que aceita 'dados' com o filtro
	protected function __todos(){

		try {

	        $this->qr = "SELECT * FROM {$this->tb} ";
			$stt = Con::ecta()->prepare($this->qr);
            if($stt->execute() === false){ throw new RuntimeException(implode(' ', $stt->errorInfo())); }


            $this->fetchall    = $stt->fetchAll(PDO::FETCH_ASSOC);
			$this->rw       = $stt->rowcount();
			$this->result   = true;


        } catch (Throwable $e) {
            $this->sms = 'Ocorreu um erro. Tente novamente '.$e->getMessage();
            $this->result = false;
        }
			return $this;
	}
	//pegar todos com limite, segundo $ordem (ex.: '`id_us` DESC'); opcionalmente
	//filtrada por __add('dados', [...]) — $ordem tem de vir sempre fixo no código do
	//modelo, nunca de $_GET/$_POST (não é parametrizável, vai direto para a SQL)
	protected function __limite($id, $ordem){

		try {
			list($ondeSql, $arrbings) = $this->__ondeOpcional();
			$id = (int) $id;

	        $this->qr = "SELECT * FROM {$this->tb}{$ondeSql} ORDER BY {$ordem} LIMIT {$id}";
			$stt = Con::ecta()->prepare($this->qr);

	        foreach ($arrbings as $i => $arrbing) {
	            $stt->bindvalue($i+1, $arrbing);
	        }

            if($stt->execute() === false){ throw new RuntimeException(implode(' ', $stt->errorInfo())); }


            $this->fetchall    = $stt->fetchAll(PDO::FETCH_ASSOC);
			$this->rw       = $stt->rowcount();
			$this->result   = true;


        } catch (Throwable $e) {
            $this->sms = 'Ocorreu um erro. Tente novamente '.$e->getMessage();
            $this->result = false;
        }
			return $this;
	}
	//listar todos com pesquisa
	protected function __pegar_todos(){

		try {

	        $this->qr = "SELECT * FROM {$this->tb} WHERE ";
        	$arrbings = array();

	        foreach ($this->__pega('dados') as $chave => $valor) {
	            if ($valor === null) {
	                $this->qr .= "$chave IS NULL ";
	            } elseif (is_array($valor)) {
	                $this->qr .= "$chave IN (" . rtrim(str_repeat("?,", count($valor)), ",") . ") ";
	                $arrbings = array_merge($arrbings, $valor);
	            } else {
	                $this->qr .= "$chave ? ";
	                $arrbings[] = $valor;
	            }
	        }

	        $this->qr .= $this->__ordemSegura();

	       	$stt = Con::ecta()->prepare($this->qr);

	        foreach ($arrbings as $i => $arrbing) {
	            $stt->bindvalue($i+1, $arrbing);
	        }

            if($stt->execute() === false){ throw new RuntimeException(implode(' ', $stt->errorInfo())); }

            
            $this->fetchall    = $stt->fetchAll(PDO::FETCH_ASSOC);
			$this->rw       = $stt->rowcount();
			$this->result   = true;


        } catch (Throwable $e) {
            $this->sms = 'Ocorreu um erro. Tente novamente '.$e->getMessage();
            $this->result = false;
        }
			return $this;
	}

	

	//apagar
	protected function __apagar(){
		try {

            $this->qr = "DELETE FROM {$this->tb} WHERE ";
            $arrbings = array();
             foreach ($this->__pega('onde') as $chave => $valor) {
	            if ($valor === null) {
	                $this->qr .= "$chave IS NULL ";
	            } elseif (is_array($valor)) {
	                $this->qr .= "$chave IN (" . rtrim(str_repeat("?,", count($valor)), ",") . ") ";
	                $arrbings = array_merge($arrbings, $valor);
	            } else {
	                //o espaço no fim é preciso: ver o comentário no __atualizar()
	                $this->qr .= "$chave ? ";
	                $arrbings[] = $valor;
	            }
	        }

            $stt = Con::ecta()->prepare($this->qr);

         
           foreach ($arrbings as $i => $arrbing) {
	            $stt->bindvalue($i+1, $arrbing);
	        }

            if($stt->execute() === false){ throw new RuntimeException(implode(' ', $stt->errorInfo())); }
            $this->result = true;

        } catch (Throwable $e) {
            $this->sms = 'Ocorreu um erro. Tente novamente '.$e->getMessage();
            $this->result = false;
        }
		return $this;
	}

}