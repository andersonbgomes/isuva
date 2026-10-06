<?php

class App {

	public function __construct() {
		$this->ranar($this->pegUrl());
	}
	//rana a a calsse de acordo com o controlador e rota chamada
	protected function ranar($url) {
		//rota
		define("rota", (isset($url[0]) && $url[0] != '') ? $url[0]:rota_default);
		array_shift($url);

		//acao
		define("acao", (isset($url[0]) && $url[0] != '') ? $url[0]:rota_default);
		array_shift($url);

		//id
		define("id", (isset($url[0]) && $url[0] != '') ? $url[0]:rota_default);
		array_shift($url);
		//verifica se a rota pegada existe nas nossas routas
		//verifica primeiro a conexao
		if(Con::ecta()){
			$rta = str_replace('-', '', ucfirst(rota.'Controlo'));
			$rtacao = str_replace('-', '', ucfirst(acao));

			if($this->eAccaoValida($rta, $rtacao)){

				$class = $rta;

				$controlador = new $class;

				$acao = $rtacao;

				$controlador->$acao();


		}else{
			require_once (_C_ . _P_ . "tema" . _P_ . tema . _P_ . "extras" . _P_ . "404.phtml");
		}

		}



	}

	/*
	Só um método PÚBLICO declarado no próprio controlador é uma rota.

	Isto era `method_exists($rta, $rtacao)`, que responde true também aos
	métodos protegidos herdados de Acao. Ou seja, endereços como
	`/veiculos/renderizar` ou `/facturas/conteudo` passavam a verificação e
	rebentavam a seguir, ao tentar chamar de fora um método protegido --
	quem fosse a esses endereços via a página de erro em vez do 404, e via
	nomes internos da aplicação que não são da conta de ninguém.

	As duas condições, juntas:

	    isPublic()              deixa de fora tudo o que é protegido/privado;
	    getDeclaringClass()     deixa de fora o que é herdado de Acao,
	                            mesmo que um dia algum desses métodos passe
	                            a público.
	*/
	private function eAccaoValida($classe, $metodo) {
		if(!class_exists($classe) || !method_exists($classe, $metodo)){
			return false;
		}

		try {
			$reflexao = new ReflectionMethod($classe, $metodo);
		} catch (ReflectionException $e) {
			return false;
		}

		return $reflexao->isPublic()
			&& !$reflexao->isStatic()
			&& $reflexao->getDeclaringClass()->getName() !== 'Acao';
	}

	protected function pegUrl() {
		return isset($_SERVER['PATH_INFO']) ? explode('/', ltrim($_SERVER['PATH_INFO'], '/')) : [''];

	}
}

?>
