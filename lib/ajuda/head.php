<?php
/*
O <head> da página, e o título de cada rota.

Está num sítio só para o cabeçalho ser igual em todas as páginas, e para
o título do separador do browser não ficar esquecido de cada vez que se
acrescenta uma rota.

AO ACRESCENTAR UMA ROTA, ACRESCENTAR AQUI UM case.

Não é obrigatório -- o fundo da função inventa um título a partir do nome
da rota --, mas o inventado é o nome do ficheiro, não o nome que a pessoa
conhece. "Notas de Crédito" lê-se melhor do que "Notascredito".
*/
function titulo(){
	$titulo = '';

	switch (rota) {

		case 'index':
			$titulo = 'Painel';
			break;

		case 'auth':
			$titulo = 'Entrar';
			break;

		/*
		As rotas do projecto entram aqui. Com acções diferentes, um
		switch dentro do case:

		case 'clientes':
			switch (acao) {
				case 'editar': $titulo = 'Editar Cliente'; break;
				default:       $titulo = 'Clientes';       break;
			}
			break;
		*/
	}

	/*
	Nenhum case acima respondeu: em vez de deixar o separador do browser
	com o nome do sistema e mais nada, escreve-se a rota e a acção. Não é
	bonito, mas diz onde se está -- e é o que faz notar que falta aqui um
	case.
	*/
	if($titulo === ''){
		$titulo = ucfirst(str_replace(['-', '_'], ' ', rota));
		if(acao !== '' && acao !== 'index'){
			$titulo .= ' - '.ucfirst(str_replace(['-', '_'], ' ', acao));
		}
	}

	return $titulo;
}

/*
As meta-etiquetas e o título, prontos a imprimir no <head>.

    <head><?=head()?></head>
*/
function head(){
	return '<meta charset="utf-8">'
	     . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
	     . '<title>'.esc(titulo()).' | '.esc(configura('tit')).'</title>'
	     . '<meta name="description" content="'.esc(configura('descricao')).'">'
	     . '<meta http-equiv="X-UA-Compatible" content="IE=edge">';
}
