<?php
/*
O ECRÃ DO INSTALADOR.

Três passos: requisitos, base de dados, conta. Quem faz as coisas é o
Instalador.php ao lado -- aqui só se pergunta, se mostra e se encaminha.

O QUE É PRECISO SABER ANTES DE MEXER

  - as respostas ficam na SESSÃO entre passos, para o "voltar atrás" não
    apagar o que já foi escrito;
  - nada é escrito em disco nem na base de dados antes do último passo:
    até lá, voltar atrás não deixa nada por limpar;
  - o token (inst_token) protege os POST. Não é CSRF a sério -- não há
    sessão de utilizador nenhuma aqui --, mas chega para o formulário
    não ser submetido de fora.

NO FIM, APAGAR ESTA PASTA. O instalador escreve o app/Conecta.php: quem
lhe chegue num sistema a funcionar deita a instalação fora. O ficheiro
INSTALADO trava isso, e apagar a pasta fecha a porta de vez.
*/

session_start();
require_once __DIR__.'/Instalador.php';

$inst = new Instalador;

//---------------------------------------------------------------------
// Já está instalado?
//---------------------------------------------------------------------
if($inst->jaInstalado()){
	ecra('Já está instalado',
		'<p>Esta aplicação já foi instalada. O instalador não volta a correr enquanto o ficheiro '
		.'<code>instalar/INSTALADO</code> existir — é ele que impede alguém de reinstalar por cima '
		.'de um sistema a funcionar.</p>'
		.'<p class="fraco">Se a instalação está feita, o passo que falta é <strong>apagar a pasta '
		.'<code>instalar/</code></strong>.</p>'
		.'<p><a class="btn" href="../">Abrir a aplicação</a></p>');
}

if(!isset($_SESSION['inst_token'])){ $_SESSION['inst_token'] = bin2hex(random_bytes(16)); }
function token(){ return $_SESSION['inst_token']; }
function tokenValido(){ return isset($_POST['token']) && hash_equals(token(), (string)$_POST['token']); }

$passo = $_GET['passo'] ?? 'requisitos';
$d = $_SESSION['inst_dados'] ?? [];
$erro = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
	if(!tokenValido()){
		$erro = 'A sessão expirou. Volte a começar.';
		$passo = 'requisitos';
	}else{
		$passo = tratarPost($inst, $d, $erro, $passo);
		$_SESSION['inst_dados'] = $d;
	}
}


//---------------------------------------------------------------------
// O que cada passo faz com o que lhe foi respondido
//---------------------------------------------------------------------
function tratarPost(Instalador $inst, array &$d, &$erro, $passo) {

	switch ($passo) {

		case 'base':
			$d['host']  = trim((string)($_POST['host'] ?? 'localhost'));
			$d['porta'] = trim((string)($_POST['porta'] ?? ''));
			$d['base']  = trim((string)($_POST['base'] ?? ''));
			$d['us']    = trim((string)($_POST['us'] ?? ''));
			$d['ps']    = (string)($_POST['ps'] ?? '');

			if($d['base'] === '' || $d['us'] === ''){
				$erro = 'Faltam o nome da base de dados e o utilizador.';
				return 'base';
			}

			$r = $inst->testarLigacao($d['host'], $d['base'], $d['us'], $d['ps'], $d['porta']);
			if(!$r['ok']){
				$erro = $r['erro'].'<br><span class="fraco">'.htmlspecialchars((string)$r['detalhe']).'</span>';
				return 'base';
			}

			/*
			A base já tem a aplicação lá dentro.

			Pergunta-se em vez de decidir: importar por cima apaga dados
			de alguém, e é exactamente o engano que custa caro -- apontar
			o instalador à base de dados errada.
			*/
			$d['tem_tabelas'] = $inst->jaTemTabelas($r['pdo']);
			return 'conta';

		case 'conta':
			$d['nome']        = trim((string)($_POST['nome'] ?? ''));
			$d['url']         = trim((string)($_POST['url'] ?? ''));
			$d['conta_nome']  = trim((string)($_POST['conta_nome'] ?? ''));
			$d['conta_email'] = trim((string)($_POST['conta_email'] ?? ''));
			$d['conta_senha'] = (string)($_POST['conta_senha'] ?? '');
			$d['apagar']      = !empty($_POST['apagar']);

			if($d['nome'] === '' || $d['conta_nome'] === '' || $d['conta_email'] === ''){
				$erro = 'Faltam o nome do sistema, o seu nome e o seu e-mail.';
				return 'conta';
			}
			if(!filter_var($d['conta_email'], FILTER_VALIDATE_EMAIL)){
				$erro = 'Esse e-mail não parece um endereço a sério.';
				return 'conta';
			}
			if(strlen($d['conta_senha']) < 8){
				$erro = 'A palavra-passe tem de ter pelo menos 8 caracteres.';
				return 'conta';
			}

			return instalar($inst, $d, $erro);
	}

	return $passo;
}


//---------------------------------------------------------------------
// O passo que escreve mesmo
//---------------------------------------------------------------------
/*
A ORDEM AQUI NÃO É INDIFERENTE.

Liga-se primeiro, importa-se depois, e só no fim se escreve o
Conecta.php. Ao contrário, uma importação falhada deixava um Conecta.php
a apontar para uma base de dados meio feita -- e a aplicação a arrancar
em cima dela.
*/
function instalar(Instalador $inst, array &$d, &$erro) {

	$r = $inst->testarLigacao($d['host'], $d['base'], $d['us'], $d['ps'], $d['porta']);
	if(!$r['ok']){
		$erro = $r['erro'];
		return 'base';
	}
	$pdo = $r['pdo'];

	//as tabelas, se ainda não lá estão (ou se foi pedido para refazer)
	if(!$inst->jaTemTabelas($pdo) || !empty($d['apagar'])){
		$imp = $inst->importar($pdo);
		if(!$imp['ok']){
			$erro = $imp['erro'];
			return 'conta';
		}
		$d['instrucoes'] = $imp['instrucoes'];
	}

	$inst->gravarDefinicoes($pdo, $d);

	$conta = $inst->criarConta($pdo, $d);
	if(!$conta['ok']){
		$erro = $conta['erro'];
		return 'conta';
	}

	$cfg = $inst->escreverConfig($d['host'], $d['base'], $d['us'], $d['ps'], $d['porta']);
	if(!$cfg['ok']){
		$erro = $cfg['erro'];
		return 'conta';
	}

	$inst->trancar(['url' => $inst->normalizarUrl($d['url'])]);

	//a password não fica na sessão depois de servir para alguma coisa
	unset($_SESSION['inst_dados']['ps'], $_SESSION['inst_dados']['conta_senha']);

	return 'fim';
}


//---------------------------------------------------------------------
// O ecrã
//---------------------------------------------------------------------
$requisitos = $inst->requisitos();
$podeSeguir = $inst->requisitosOk($requisitos);

ob_start();
?>

<?php if($erro !== ''): ?>
<div class="aviso mau"><?=$erro?></div>
<?php endif; ?>

<?php if($passo === 'requisitos'): ?>

	<h2>1. O que o servidor precisa de ter</h2>
	<table>
		<?php foreach ($requisitos as $r): ?>
		<tr>
			<td><?=$r['ok'] ? '<span class="sim">OK</span>' : '<span class="nao">falta</span>'?></td>
			<td><?=htmlspecialchars($r['nome'])?></td>
			<td class="fraco"><?=htmlspecialchars($r['tem'])?></td>
		</tr>
		<?php endforeach; ?>
	</table>

	<?php if($inst->temConfig()): ?>
	<div class="aviso">
		Já existe um <code>app/Conecta.php</code>. Seguir em frente substitui-o.
	</div>
	<?php endif; ?>

	<?php /*
	Um LINK e não um formulário.

	Era um POST para ?passo=base -- e um POST para o passo da base de
	dados é tratado como a RESPOSTA desse passo: o instalador ia validar
	campos que ainda ninguém tinha visto, e o primeiro "Continuar"
	respondia "faltam o nome da base de dados e o utilizador".

	Este passo não responde nada: só mostra. Logo, GET.
	*/ ?>
	<?php if($podeSeguir): ?>
	<p><a class="btn" href="?passo=base">Continuar</a></p>
	<?php else: ?>
	<p class="fraco">Resolva o que está em falta e recarregue esta página.</p>
	<?php endif; ?>

<?php elseif($passo === 'base'): ?>

	<h2>2. A base de dados</h2>
	<p class="fraco">
		A base de dados tem de estar criada — o instalador não a cria. Num alojamento
		partilhado, os quatro valores estão no painel, em "Bases de dados MySQL".
	</p>

	<form method="post" action="?passo=base">
		<input type="hidden" name="token" value="<?=htmlspecialchars(token())?>">

		<label>Servidor</label>
		<input name="host" value="<?=htmlspecialchars($d['host'] ?? 'localhost')?>" required>

		<label>Porta <span class="fraco">(em branco = 3306)</span></label>
		<input name="porta" value="<?=htmlspecialchars($d['porta'] ?? '')?>">

		<label>Nome da base de dados</label>
		<input name="base" value="<?=htmlspecialchars($d['base'] ?? '')?>" required>

		<label>Utilizador</label>
		<input name="us" value="<?=htmlspecialchars($d['us'] ?? '')?>" required>

		<label>Palavra-passe</label>
		<input type="password" name="ps" value="">

		<button class="btn" type="submit">Testar e continuar</button>
	</form>

<?php elseif($passo === 'conta'): ?>

	<h2>3. O sistema e a sua conta</h2>

	<?php if(!empty($d['tem_tabelas'])): ?>
	<div class="aviso">
		<strong>Esta base de dados já tem tabelas da aplicação.</strong>
		Por omissão não se lhes toca — a conta abaixo é criada (ou actualizada) e
		mais nada. Para começar do zero, marque a caixa no fim.
	</div>
	<?php endif; ?>

	<form method="post" action="?passo=conta">
		<input type="hidden" name="token" value="<?=htmlspecialchars(token())?>">

		<label>Nome do sistema <span class="fraco">(aparece no topo e no separador do browser)</span></label>
		<input name="nome" value="<?=htmlspecialchars($d['nome'] ?? 'A Minha Aplicação')?>" required>

		<label>Endereço da aplicação</label>
		<input name="url" value="<?=htmlspecialchars($d['url'] ?? $inst->urlSugerido())?>" required>
		<p class="fraco">
			É daqui que saem todos os endereços da aplicação. Errado, nem o CSS carrega.
		</p>

		<hr>

		<label>O seu nome</label>
		<input name="conta_nome" value="<?=htmlspecialchars($d['conta_nome'] ?? '')?>" required>

		<label>O seu e-mail <span class="fraco">(é com ele que entra)</span></label>
		<input type="email" name="conta_email" value="<?=htmlspecialchars($d['conta_email'] ?? '')?>" required>

		<label>Palavra-passe <span class="fraco">(mínimo 8 caracteres)</span></label>
		<input type="password" name="conta_senha" required>

		<?php if(!empty($d['tem_tabelas'])): ?>
		<p class="perigo">
			<label class="linha">
				<input type="checkbox" name="apagar" value="1">
				Voltar a correr o ficheiro de instalação (refaz as tabelas)
			</label>
		</p>
		<?php endif; ?>

		<button class="btn" type="submit">Instalar</button>
	</form>

<?php elseif($passo === 'fim'): ?>

	<h2>Instalado</h2>
	<p>
		A base de dados está pronta<?=!empty($d['instrucoes']) ? ' ('.(int)$d['instrucoes'].' instruções)' : ''?>,
		o <code>app/Conecta.php</code> foi escrito e a sua conta foi criada.
	</p>

	<div class="aviso mau">
		<strong>Falta um passo, e é importante: apague a pasta <code>instalar/</code>.</strong><br>
		Enquanto ela existir, quem lhe chegue pode reescrever a ligação à base de dados.
	</div>

	<p><a class="btn" href="../">Entrar na aplicação</a></p>

<?php endif; ?>

<?php
ecra('Instalação', ob_get_clean(), $passo);


//---------------------------------------------------------------------
// A moldura. No fim, porque é uma função -- o PHP içou-a para o topo.
//---------------------------------------------------------------------
function ecra($titulo, $html, $passo = '') {
	$passos = ['requisitos' => 'Requisitos', 'base' => 'Base de dados', 'conta' => 'Conta', 'fim' => 'Concluído'];
	$actual = array_search($passo, array_keys($passos), true);
	?>
<!DOCTYPE html>
<html lang="pt">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?=htmlspecialchars($titulo)?></title>
	<style>
		* { box-sizing: border-box; }
		body { margin:0; background:#f6f7fb; color:#1a1d23;
		       font:15px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif; }
		.caixa { max-width:620px; margin:3rem auto; background:#fff; border:1px solid #e5e7eb;
		         border-radius:12px; padding:2rem; }
		h1 { font-size:20px; margin:0 0 1.25rem; }
		h2 { font-size:17px; margin:0 0 1rem; }
		.passos { display:flex; gap:.4rem; list-style:none; margin:0 0 1.5rem; padding:0; flex-wrap:wrap; }
		.passos li { font-size:12px; color:#9aa3b2; padding:.25rem .6rem; border-radius:20px; background:#f0f2f7; }
		.passos li.feito { background:#e8f7ee; color:#166534; }
		.passos li.aqui  { background:#3b5de7; color:#fff; }
		table { width:100%; border-collapse:collapse; font-size:14px; margin-bottom:1.25rem; }
		td { padding:.45rem .5rem; border-bottom:1px solid #eef0f4; }
		.sim { color:#166534; font-weight:600; } .nao { color:#991b1b; font-weight:600; }
		.fraco { color:#6b7280; font-size:13px; }
		label { display:block; margin:.9rem 0 .3rem; font-size:14px; font-weight:600; }
		label.linha { font-weight:400; }
		input[type=text], input[type=email], input[type=password], input:not([type]) {
			width:100%; padding:.55rem .7rem; border:1px solid #e5e7eb; border-radius:8px; font:inherit; font-size:14px; }
		.btn { display:inline-block; margin-top:1.25rem; padding:.6rem 1.2rem; border:0; border-radius:8px;
		       background:#3b5de7; color:#fff; font:inherit; font-size:14px; text-decoration:none; cursor:pointer; }
		.btn:hover { background:#2c49c4; }
		.aviso { background:#fff7e6; border:1px solid #ffe0a3; color:#92400e;
		         padding:.75rem 1rem; border-radius:8px; margin-bottom:1rem; font-size:14px; }
		.aviso.mau { background:#fdecee; border-color:#f5c2c7; color:#991b1b; }
		.perigo { background:#fdecee; border:1px solid #f5c2c7; border-radius:8px; padding:.6rem .8rem; }
		code { background:rgba(0,0,0,.06); padding:.12em .4em; border-radius:4px; font-size:.9em; }
		hr { border:0; border-top:1px solid #eef0f4; margin:1.5rem 0; }
	</style>
</head>
<body>
	<div class="caixa">
		<h1>Instalação</h1>

		<?php if($passo !== ''): ?>
		<ol class="passos">
			<?php $i = 0; foreach ($passos as $chave => $rotulo): ?>
			<li class="<?=($chave === $passo ? 'aqui' : ($actual !== false && $i < $actual ? 'feito' : ''))?>"><?=$rotulo?></li>
			<?php $i++; endforeach; ?>
		</ol>
		<?php endif; ?>

		<?=$html?>
	</div>
</body>
</html>
	<?php
	exit;
}
