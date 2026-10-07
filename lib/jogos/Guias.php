<?php
/*
OS GUIAS DO "COMO USAR".

Um guia por cartão. Cada guia é uma lista de blocos, e é a vista
(tema/padrao/comousar/guia.phtml) que os desenha:

    ['h', 'Título de uma parte']
    ['p', 'Um parágrafo.']
    ['passos', ['Primeiro passo', 'Segundo passo', ...]]
    ['nota', 'Uma dica.']                       caixa azul
    ['alerta', 'Um cuidado.']                   caixa amarela
    ['consolas', 'Nintendo']                    a tabela das consolas dessa família,
                                                com formatos e BIOS -- sai de Consolas.php
    ['teclas', 'browser' | 'servidor']          a tabela dos controlos

No texto, **assim** fica a negrito e `assim` fica como código. Tudo o
resto é escapado (ver formatar()): o texto é nosso, mas a regra do
esc() vale na mesma.

PORQUE É QUE O TEXTO VIVE AQUI E NÃO NAS VISTAS: os formatos aceites e
o estado das BIOS mudam (uma consola nova, uma BIOS enviada) e o guia tem
de os mostrar como estão AGORA -- por isso as tabelas são geradas a
partir do Consolas.php. E escrever um guia novo fica a ser acrescentar um
array, sem mexer em HTML.

    'admin' => true   só o administrador vê (e o controlador recusa-o
                      aos outros, não basta esconder o cartão)
*/
class Guias {

	const GRUPOS = ['Começar', 'Consolas', 'Administração'];

	public static function todos() {
		return [

		//==================================================================
		// Começar
		//==================================================================

		'primeiros-passos' => [
			'grupo' => 'Começar', 'titulo' => 'Primeiros passos', 'icone' => 'comando', 'tom' => '#14b8a6',
			'resumo' => 'Entrar, escolher um jogo, jogar e usar os controlos.',
			'blocos' => [
				['h', 'Conta: só para guardar'],
				['p', 'Pode jogar **já, sem conta**: abra o catálogo e escolha um jogo. A conta serve para **guardar o progresso** e continuar em qualquer computador.'],
				['passos', [
					'Carregue em **Criar conta** (no topo da página), escreva o nome, o e-mail e uma palavra-passe, e entra logo.',
					'Ou carregue em **Continuar com o Google**, e entra com a sua conta Google, sem palavra-passe nova.',
				]],
				['nota', 'Jogou sem conta e só depois a criou? Não perde nada: na próxima vez que abrir o jogo, a gravação que ficou no browser passa para a sua conta.'],
				['h', 'Escolher e abrir um jogo'],
				['passos', [
					'Em **Jogos** (o ícone da casa) estão todos os jogos, arrumados por família: Nintendo, Sega e PlayStation. A barra de pesquisa, no topo, procura pelo nome.',
					'Clique num jogo para abrir a **tela do jogo**: a capa, a descrição, os controlos e as suas gravações.',
					'Carregue em **Jogar agora**. O jogo abre no ecrã do emulador.',
					'Nos jogos que correm no seu browser, carregue no botão de arranque (▶) que aparece no meio do ecrã. A primeira vez demora mais: o browser descarrega o emulador e o jogo, e da próxima vez já os tem guardados.',
				]],
				['h', 'Controlos'],
				['p', 'Pode jogar com o teclado ou com um comando ligado ao computador (USB ou Bluetooth): prima um botão do comando depois de abrir o jogo, e veja o guia **Comandos** se não responder. Estas são as teclas de origem:'],
				['teclas', 'browser'],
				['nota', 'Para mudar as teclas, use o ícone do comando na barra do emulador, dentro do jogo. No telemóvel aparece um comando no ecrã.'],
				['h', 'Ecrã inteiro e sair'],
				['p', 'A barra do emulador (passe o rato por baixo do jogo) tem o ecrã inteiro, o som, as definições e os botões de guardar e carregar estado. Para sair, use o botão **Jogos** no topo: o que gravou fica guardado na sua conta.'],
			],
		],

		'gravacoes' => [
			'grupo' => 'Começar', 'titulo' => 'Gravações', 'icone' => 'gravar', 'tom' => '#6366f1',
			'resumo' => 'Onde fica o seu progresso e como continuar noutro computador.',
			'blocos' => [
				['p', 'Com conta, o seu progresso fica guardado **na sua conta, no servidor**. Pode começar num computador e continuar noutro, ou no telemóvel.'],
				['alerta', '**Sem conta**, o jogo grava na mesma, mas **só neste browser**. Quando gravar, aparece um aviso para criar conta. Criar a conta é grátis, e o que já gravou passa para ela.'],
				['h', 'Dois tipos de gravação'],
				['passos', [
					'**A gravação do jogo:** é o que o próprio jogo grava, no "cartão de memória" ou na pilha do cartucho, quando usa a opção de gravar dentro do jogo. Vai para o servidor sozinha, de minuto a minuto e quando sai.',
					'**Os estados:** são uma fotografia do jogo naquele instante, e funcionam mesmo em jogos sem opção de gravar. Use os botões **Guardar estado** e **Carregar estado** da barra do emulador. Há 9 lugares; o lugar escolhe-se nas definições do emulador.',
				]],
				['nota', 'Na PS2 grava-se só dentro do jogo, no cartão de memória. O cartão é da sua conta e serve para todos os jogos de PS2.'],
				['h', 'Ver e apagar'],
				['p', 'Em **As minhas gravações** (o ícone da disquete) vê tudo o que tem guardado, de que jogo e de quando. Pode descarregar uma cópia ou apagar. Cada conta tem 1 GB.'],
				['alerta', 'Os jogos abertos em "Jogar do meu computador" não fazem parte do catálogo: as gravações deles ficam só no browser onde jogou.'],
			],
		],

		'comandos' => [
			'grupo' => 'Começar', 'titulo' => 'Comandos (PS4, PS5, Xbox, Bluetooth)', 'icone' => 'comando', 'tom' => '#0ea5e9',
			'resumo' => 'Ligar um comando por USB ou Bluetooth, e o que fazer se não responder.',
			'blocos' => [
				['p', 'Qualquer comando que o computador reconheça funciona: o da **PS4** (DualShock 4), o da **PS5** (DualSense), o da **Xbox**, o **Switch Pro** e comandos genéricos, **por cabo USB ou por Bluetooth**. Não precisa de instalar nada.'],
				['alerta', '**Depois de ligar o comando, prima um botão** com a página do site aberta. O browser só mostra o comando ao site depois disso. É a razão mais comum de "o comando não funciona".'],
				['h', 'Comando da PS4 ou PS5 por Bluetooth'],
				['passos', [
					'Com o comando desligado, mantenha premidos **Share + PS** (na PS5: **Create + PS**) até a luz começar a piscar depressa.',
					'No computador, abra as definições de **Bluetooth** e escolha **Wireless Controller** (ou DualSense).',
					'Abra a página **Testar o comando** (o ícone do comando, na barra) e prima um botão: o comando aparece e cada botão acende no desenho.',
				]],
				['h', 'Por cabo USB'],
				['p', 'Ligue o cabo e prima um botão. Use um cabo de dados: alguns cabos só carregam a bateria.'],
				['h', 'Comando da Xbox'],
				['p', 'Por Bluetooth: mantenha premido o botão de emparelhar (em cima, ao lado do cabo) até o logótipo piscar, e escolha-o no Bluetooth do computador. Por cabo, basta ligar.'],
				['h', 'No telemóvel'],
				['p', 'Em **Android** e no **iPhone/iPad**, emparelhe o comando nas definições de Bluetooth do telemóvel, da mesma maneira. Sem comando, o emulador mostra botões no ecrã.'],
				['h', 'Se continuar a não funcionar'],
				['passos', [
					'Veja o indicador do comando, no topo do ecrã de jogo. Diz se o comando foi reconhecido, e com que jogador ficou.',
					'Se disser **"Comandos precisam de HTTPS"**, o site está a abrir em `http://`. Os browsers só dão comandos a sites em HTTPS: fale com o administrador.',
					'Se os botões estiverem **trocados**, abra o **ícone do comando** na barra do emulador, escolha o botão e prima-o no comando.',
					'Use o **Chrome** ou o **Edge**: são os que melhor reconhecem comandos (e os únicos que os fazem vibrar).',
					'Feche programas que "apanham" o comando para si, como o **DS4Windows** ou o modo de comando do **Steam**: podem esconder o comando ou mostrá-lo a dobrar.',
				]],
				['nota', 'Na **PS2**, que corre no servidor, o comando funciona da mesma maneira: prima um botão depois de a imagem aparecer, com o rato dentro da imagem.'],
			],
		],

		'local' => [
			'grupo' => 'Começar', 'titulo' => 'Jogar do meu computador ou de um link', 'icone' => 'portatil', 'tom' => '#f59e0b',
			'resumo' => 'Abrir um jogo que tem no computador, ou de um link, sem passar pelo site.',
			'blocos' => [
				['p', 'Tem um jogo no computador que não está no catálogo? Pode abri-lo directamente. O ficheiro **não sai do seu computador**: o browser lê-o e entrega-o ao emulador.'],
				['passos', [
					'Abra **Jogar do meu computador** (o ícone do portátil).',
					'Escolha a consola. Por baixo aparecem os formatos que essa consola aceita.',
					'Escolha o ficheiro e carregue em **Jogar**.',
				]],
				['h', 'A partir de um link'],
				['p', 'Na mesma página, escolha **De um link** e cole o link de descarga directa do jogo. O seu browser descarrega-o desse site e abre-o logo, sem passar pelo nosso servidor.'],
				['alerta', 'Muitos sites não deixam outros sites abrirem os ficheiros deles (é uma regra dos browsers, chamada CORS), e com esses o link não abre. Nesse caso, descarregue o ficheiro e escolha **Do meu computador**, ou carregue em **Sugerir ao catálogo**.'],
				['alerta', 'Este modo serve só as consolas que correm no browser. A PS2 corre no nosso servidor, e para isso o jogo tem de estar no catálogo.'],
				['nota', 'As gravações destes jogos ficam só neste browser. Para as ter na sua conta, peça ao administrador que ponha o jogo no catálogo.'],
			],
		],

		'sugerir' => [
			'grupo' => 'Começar', 'titulo' => 'Sugerir um jogo', 'icone' => 'mais', 'tom' => '#22c55e',
			'resumo' => 'Encontrou um link de um jogo? Peça para o pôr no catálogo.',
			'blocos' => [
				['p', 'Se encontrar o link de descarga de um jogo que gostava de ver no catálogo, pode sugeri-lo. Precisa de uma conta (é grátis).'],
				['passos', [
					'Abra **Sugerir um jogo** (o ícone **+**).',
					'Escreva o nome do jogo, escolha a consola e cole o **link de descarga directa**.',
					'Carregue em **Enviar sugestão**.',
					'Na mesma página, em **As minhas sugestões**, vê se está à espera, se foi aceite (com o botão **Jogar**) ou recusada (com o motivo).',
				]],
				['nota', 'Num jogo aceite, as gravações ficam na sua conta, como em qualquer jogo do catálogo.'],
				['alerta', 'Sugira só jogos que podem ser partilhados: homebrew, jogos gratuitos, ou jogos cujo autor deixa distribuir. Cada conta pode ter até 5 sugestões à espera, e fazer até 10 por dia.'],
			],
		],

		'bios' => [
			'grupo' => 'Começar', 'titulo' => 'BIOS', 'icone' => 'chip', 'tom' => '#ec4899',
			'resumo' => 'O que é a BIOS, que consolas precisam dela e como obter a sua legalmente.',
			'blocos' => [
				['p', 'A BIOS é o programa interno de uma consola: o que corre quando a liga, antes do jogo. Algumas consolas não arrancam sem ela, e outras correm melhor com ela.'],
				['nota', '**Quem joga não precisa de descarregar BIOS nenhuma.** O site já a entrega ao emulador sozinho. Na página **BIOS** vê o que cada consola precisa e o que o site já tem instalado.'],
				['h', 'Porque é que o site não dá a BIOS a descarregar'],
				['p', 'As BIOS das consolas têm direitos de autor da Sony, da Sega e da Nintendo. Pô-las a descarregar é distribuí-las, e isso não é permitido. O administrador usa a BIOS da sua própria consola, e o site entrega-a ao emulador sem a pôr à disposição de ninguém.'],
				['h', 'Como obter a BIOS da sua consola'],
				['p', 'A forma legal é extraí-la ("fazer o dump") da consola que tem em casa:'],
				['passos', [
					'Procure o guia de extracção para o **seu modelo** de consola. Na PS2, o projecto PCSX2 explica no site dele (pcsx2.net) como correr o extractor de BIOS na própria consola.',
					'Copie o ficheiro para o computador, sem lhe mudar o nome. O nome conta: o emulador procura a BIOS por ele (por exemplo `scph5501.bin` na PS1, ou `bios_CD_U.bin` no Sega CD).',
					'Entregue o ficheiro ao administrador do site, que o envia em **Gerir jogos → Emulador e BIOS**.',
				]],
				['alerta', 'Não descarregue BIOS de sites que as oferecem: além de não ser legal, é uma forma comum de espalhar vírus.'],
			],
		],

		//==================================================================
		// Consolas
		//==================================================================

		'nintendo' => [
			'grupo' => 'Consolas', 'titulo' => 'Nintendo', 'icone' => 'comando', 'tom' => '#dc2626',
			'resumo' => 'NES, Super Nintendo, N64, Game Boy, GBA e DS: formatos, BIOS e dicas.',
			'blocos' => [
				['p', 'Todas as consolas da Nintendo correm **no seu browser**. NES, Super Nintendo e Game Boy correm em qualquer computador, e até no telemóvel. N64 e DS pedem um computador razoável.'],
				['consolas', 'Nintendo'],
				['h', 'Dicas'],
				['passos', [
					'**Nintendo DS:** o ecrã de baixo é táctil, e o rato faz de caneta. No telemóvel, toque no ecrã.',
					'**N64:** o primeiro arranque demora uns segundos a mais, porque o emulador é maior. Se o som falhar, feche outros separadores do browser.',
					'**GBA e DS** não precisam de BIOS: o emulador traz uma de substituição. Com a BIOS original, alguns jogos arrancam com o logótipo da consola.',
				]],
			],
		],

		'sega' => [
			'grupo' => 'Consolas', 'titulo' => 'Sega', 'icone' => 'comando', 'tom' => '#2563eb',
			'resumo' => 'Master System, Game Gear, Mega Drive, Sega CD, 32X e Saturn.',
			'blocos' => [
				['p', 'As consolas da Sega correm **no seu browser**. Master System, Game Gear e Mega Drive são muito leves. O Saturn é o mais exigente.'],
				['consolas', 'Sega'],
				['h', 'Dicas'],
				['passos', [
					'**Sega CD:** não arranca sem a BIOS. Se o jogo mostrar um ecrã preto, veja na página **BIOS** se já está instalada.',
					'**Jogos em CD** (Sega CD, Saturn): o melhor formato é `.chd`, um ficheiro só. Também serve o `.cue` junto com os `.bin` dentro de um `.zip`.',
					'**Saturn:** a compatibilidade não é total. Se um jogo não arrancar, experimente noutro browser (Chrome ou Edge).',
				]],
			],
		],

		'playstation' => [
			'grupo' => 'Consolas', 'titulo' => 'PlayStation 1 e PSP', 'icone' => 'comando', 'tom' => '#4f46e5',
			'resumo' => 'PS1 e PSP no browser: formatos, BIOS e o que o computador precisa.',
			'blocos' => [
				['p', 'A PS1 e a PSP correm **no seu browser**. A PS1 corre em quase tudo. A PSP pede um computador bom e um browser recente: Chrome, Edge ou Firefox (no Safari não arranca).'],
				['consolas', 'PlayStation'],
				['h', 'Dicas'],
				['passos', [
					'**PS1:** o melhor formato é `.chd`. Um `.bin` sozinho, sem o `.cue`, nem sempre arranca; ponha os dois juntos num `.zip`.',
					'**PS1:** a BIOS é opcional, mas alguns jogos precisam dela para o cartão de memória e para os vídeos.',
					'**Jogos com vários discos** (PS1): quando o jogo pedir o disco seguinte, troque-o no menu do emulador.',
					'**PSP:** se aparecer o aviso de que o browser não permite esta consola, actualize o browser ou use o Chrome.',
				]],
			],
		],

		'ps2' => [
			'grupo' => 'Consolas', 'titulo' => 'PlayStation 2', 'icone' => 'nuvem', 'tom' => '#7c3aed',
			'resumo' => 'A PS2 corre no nosso servidor e chega por streaming. Como funciona a fila.',
			'blocos' => [
				['p', 'Não há emulador de PS2 que corra num browser. Por isso a PS2 corre **no nosso servidor**, numa placa gráfica, e a imagem chega até si como um vídeo. O seu computador não precisa de ser bom. Precisa de **boa internet**.'],
				['h', 'Jogar'],
				['passos', [
					'Abra o jogo e carregue em **Jogar agora**.',
					'Se os lugares do servidor estiverem todos ocupados, fica **na fila**, e a página diz quantas pessoas estão à sua frente. Não feche a página.',
					'Na primeira vez que alguém joga um jogo, o servidor copia-o e prepara-o. Pode levar um minuto.',
					'Quando o jogo aparecer, clique dentro da imagem para o teclado e o comando irem para o jogo.',
					'Para sair, carregue em **Terminar**. O lugar fica livre para a pessoa seguinte.',
				]],
				['teclas', 'servidor'],
				['h', 'Para correr bem'],
				['passos', [
					'Uma ligação de pelo menos **15 Mbps**, de preferência por cabo. O Wi-Fi longe do router dá cortes.',
					'Feche downloads e vídeos noutros separadores enquanto joga.',
					'Se fechar a página sem terminar, o lugar liberta-se sozinho ao fim de 2 minutos.',
				]],
				['nota', 'As gravações da PS2 ficam no cartão de memória da sua conta, e serve o mesmo cartão em qualquer servidor do site.'],
			],
		],

		//==================================================================
		// Administração
		//==================================================================

		'activar' => [
			'grupo' => 'Administração', 'admin' => true, 'titulo' => 'Instalar e activar o site', 'icone' => 'chave', 'tom' => '#14b8a6',
			'resumo' => 'Do servidor vazio ao primeiro jogo: instalação, primeira conta e definições.',
			'blocos' => [
				['h', 'Instalar'],
				['passos', [
					'No painel do alojamento, crie uma **base de dados MySQL vazia** e um utilizador **atribuído** a ela (criar os dois não chega: tem de os ligar).',
					'Envie os ficheiros do site para o alojamento e abra o endereço no browser. Como ainda não está instalado, abre sozinho o **instalador**.',
					'O instalador verifica o servidor e pede os dados da base de dados e da primeira conta. Essa conta nasce **administrador**.',
					'No fim, **apague a pasta `instalar/`**. Enquanto existir, é uma porta aberta.',
				]],
				['alerta', 'Numa instalação que já existia, as tabelas novas entram pelos ficheiros da pasta `migrations/`, pela ordem das datas.'],
				['h', 'Pôr a funcionar'],
				['passos', [
					'Os jogadores criam as suas contas sozinhos. Se quiser o botão "Continuar com o Google", configure-o em **Contas → Entrar com o Google** (veja o guia "Contas de jogadores").',
					'Envie as BIOS em **Gerir jogos → Emulador e BIOS** (obrigatórias para o Sega CD e a PS2).',
					'Adicione os primeiros jogos em **Gerir jogos → Adicionar jogo**.',
					'Para a PS2, ligue um servidor de jogo. Veja o guia "Servidores de PS2".',
				]],
				['h', 'O que o servidor precisa'],
				['passos', [
					'**HTTPS:** a PSP só funciona em HTTPS, e a PS2 também (o jogo vem de outro endereço).',
					'**A extensão `curl` do PHP:** é precisa para adicionar jogos por link e para falar com o servidor de PS2.',
					'**Num servidor nginx** (que ignora os ficheiros `.htaccess`), bloqueie as pastas `armazem/` e `no-de-jogo/`. O LEIA-ME explica como.',
				]],
				['nota', 'O nome do site muda-se na tabela `app_config`, na definição `tit`.'],
			],
		],

		'contas' => [
			'grupo' => 'Administração', 'admin' => true, 'titulo' => 'Contas de jogadores', 'icone' => 'pessoas', 'tom' => '#0ea5e9',
			'resumo' => 'Contas criadas pelos jogadores, entrar com o Google, desactivar e dar acesso de administrador.',
			'blocos' => [
				['p', 'O site é **aberto**: qualquer pessoa joga sem conta, e cria a sua quando quiser guardar o progresso. Não precisa de criar contas para ninguém.'],
				['h', 'Entrar com o Google'],
				['passos', [
					'Abra **Contas → Entrar com o Google**.',
					'Siga os passos que lá estão para criar as credenciais na consola do Google (console.cloud.google.com), e cole o **URI de redireccionamento** que o ecrã mostra.',
					'Copie o **ID de cliente** e o **segredo** para o ecrã e grave. O botão "Continuar com o Google" aparece logo na entrada e na criação de conta.',
				]],
				['alerta', 'Quando uma conta criada com palavra-passe entra pela primeira vez com o Google, fica ligada a ele e a palavra-passe antiga deixa de valer. É uma protecção: as contas do site não confirmam o e-mail, e assim ninguém fica com uma conta criada com o e-mail de outra pessoa.'],
				['h', 'Criar uma conta à mão'],
				['passos', [
					'Abra **Contas** (o ícone das pessoas, na parte de administração da barra).',
					'Carregue em **Nova conta**, escreva o nome, o e-mail e uma palavra-passe de pelo menos 8 caracteres, e grave.',
					'Dê ao jogador o e-mail e a palavra-passe. Pode trocá-la quando quiser, na mesma página.',
				]],
				['h', 'Activar e desactivar'],
				['p', 'Uma conta **desactivada** deixa de conseguir entrar, e quem estiver dentro sai na página seguinte. As gravações dessa conta ficam guardadas: ao activá-la outra vez, está tudo lá.'],
				['h', 'Administradores'],
				['h', 'O que pede conta'],
				['p', 'Sem conta joga-se tudo o que corre no browser. Pedem conta: **gravar no servidor**, a **PS2** (cada jogador ocupa uma placa gráfica) e o **Sega CD** (precisa da BIOS, e a BIOS só vai para quem tem conta).'],
				['p', 'Um administrador vê **Gerir jogos**, **Servidores de jogo** e **Contas**. Dê este acesso só a quem precisa. O site não deixa que tire o acesso a si próprio, para nunca ficar sem nenhum administrador.'],
			],
		],

		'adicionar' => [
			'grupo' => 'Administração', 'admin' => true, 'titulo' => 'Adicionar jogos', 'icone' => 'mais', 'tom' => '#22c55e',
			'resumo' => 'Por ficheiro ou por link, formatos certos, capas e esconder jogos.',
			'blocos' => [
				['passos', [
					'Abra **Gerir jogos → Adicionar jogo**.',
					'Escreva o título e escolha a **consola**. Por baixo aparecem os formatos que essa consola aceita e o tamanho máximo.',
					'Escolha de onde vem o jogo: **um ficheiro do seu computador** (o envio começa logo e mostra a percentagem) ou **um link de descarga directa** (o servidor descarrega-o quando gravar).',
					'Opcional: uma **capa** (JPG, PNG, WEBP ou GIF) e uma descrição.',
					'Carregue em **Gravar**. O jogo aparece no catálogo logo a seguir.',
				]],
				['nota', 'Um ficheiro grande vai aos bocados. Por isso funciona mesmo que o alojamento só aceite envios pequenos, e uma falha de rede a meio não estraga nada.'],
				['h', 'Formatos'],
				['p', 'Para jogos em CD (PS1, Sega CD, Saturn, PS2) prefira `.chd`: é um ficheiro só e mais pequeno. Um `.cue` precisa dos seus `.bin`; nas consolas de browser, ponha-os todos num `.zip`. A PS2 não aceita `.zip`.'],
				['h', 'Links'],
				['p', 'O link tem de descarregar o ficheiro directamente. Páginas com um botão "Download" (Google Drive, Mega) normalmente não servem. Se o link não der o nome com a extensão certa, escreva-o no campo **Nome do ficheiro**.'],
				['h', 'Sugestões dos jogadores'],
				['p', 'Os jogadores com conta podem sugerir jogos por link. As sugestões à espera aparecem em **Sugestões dos jogadores** (o ícone do visto, com o número em cima).'],
				['passos', [
					'Abra o link para ver o que é, e confirme que o jogo pode ser distribuído.',
					'**Aceitar** abre o "Adicionar jogo" já preenchido com o link. Reveja, junte uma capa se quiser, e carregue em **Gravar**: o servidor descarrega o jogo, e a sugestão fica aceite.',
					'**Recusar** pede um motivo (opcional), que o jogador lê na página dele.',
				]],
				['h', 'Editar, esconder e apagar'],
				['p', 'Em **Gerir jogos**: **Editar** muda o título, a consola, a descrição e a capa. Tirar o visto de **Visível no catálogo** esconde o jogo sem o apagar (o administrador continua a poder abri-lo para testar). **Apagar** tira-o do catálogo e do disco.'],
				['alerta', 'Ponha no catálogo só jogos que tem o direito de distribuir: homebrew, jogos livres, ou cópias dos seus próprios discos num site privado.'],
			],
		],

		'enviar-bios' => [
			'grupo' => 'Administração', 'admin' => true, 'titulo' => 'Enviar as BIOS', 'icone' => 'chip', 'tom' => '#ec4899',
			'resumo' => 'Que BIOS enviar, com que nome, e como confirmar que ficaram activas.',
			'blocos' => [
				['passos', [
					'Abra **Gerir jogos → Emulador e BIOS**.',
					'Na linha da consola, escolha o ficheiro da BIOS e carregue em **Enviar**. A BIOS vai aos bocados, como os jogos, por isso não há limite de tamanho do alojamento.',
					'Confirme na página **BIOS** (o ícone do chip): a consola passa a mostrar "Instalada".',
				]],
				['alerta', '**Mantenha o nome original do ficheiro.** O emulador procura a BIOS pelo nome. Uma BIOS de PS1 com o nome mudado é como não ter BIOS nenhuma.'],
				['consolas', '*bios'],
				['p', 'Para trocar uma BIOS, envie a nova por cima. A antiga é apagada.'],
			],
		],

		'servidores' => [
			'grupo' => 'Administração', 'admin' => true, 'titulo' => 'Servidores de PS2', 'icone' => 'servidor', 'tom' => '#7c3aed',
			'resumo' => 'Ligar a máquina com placa gráfica que corre a PS2, e medir quantos jogadores aguenta.',
			'blocos' => [
				['p', 'A PS2 corre numa máquina à parte, com placa gráfica NVIDIA: o **nó de jogo**. A instalação dessa máquina (drivers, Docker, nginx, TURN) está passo a passo no ficheiro `no-de-jogo/LEIA-ME.md`.'],
				['h', 'Ligar o nó ao site'],
				['passos', [
					'Abra **Servidores de jogo → Acrescentar servidor**.',
					'Preencha: um nome; a **API** (por exemplo `https://no1.seusite.ao:7443`); o **endereço público** (`https://no1.seusite.ao`); a porta do primeiro lugar (8443); e quantos **jogadores ao mesmo tempo**.',
					'Copie o **segredo** que o formulário gerou para a configuração do agente no nó (`/etc/isuva/config.json`). Grave.',
					'A lista tem de dizer **Responde**. Se disser "Não responde", a mensagem por baixo diz porquê.',
				]],
				['h', 'Quantos jogadores'],
				['p', 'Comece com 1 jogador por máquina. Jogue um jogo pesado e veja o uso da placa e do processador (o guia do nó explica como). Se sobrar folga, suba para 2, e acerte o número no agente e aqui.'],
				['nota', 'Em **Servidores de jogo** vê também quem está a jogar e quem está na fila, e pode terminar uma sessão à força.'],
			],
		],

		'emulador' => [
			'grupo' => 'Administração', 'admin' => true, 'titulo' => 'O emulador (EmulatorJS)', 'icone' => 'relampago', 'tom' => '#f97316',
			'resumo' => 'De onde vem o emulador das consolas de browser, e como o ter no seu próprio servidor.',
			'blocos' => [
				['p', 'As consolas de browser usam o **EmulatorJS**. Por omissão, os browsers dos jogadores vão buscá-lo à CDN oficial. É o mais simples, mas o site fica dependente dela.'],
				['h', 'Ter uma cópia no seu servidor'],
				['passos', [
					'Descarregue a última versão em github.com/EmulatorJS/EmulatorJS/releases (traz os emuladores de todas as consolas).',
					'Copie a pasta `data/` para `emulatorjs/data/`, na raiz do site.',
					'Em **Gerir jogos → Emulador e BIOS**, escreva `emulatorjs/data/` e grave.',
					'Abra um jogo para confirmar que arranca.',
				]],
			],
		],

		];
	}

	/*
	As teclas de origem, para a tela de um jogo e para os guias.

	    browser   as do EmulatorJS (defaultControllers no emulator.js deles)
	    servidor  as do PCSX2 no nó (no-de-jogo/imagem-ps2/PCSX2.ini.modelo)

	Mudar as teclas de um destes lados é mudar a lista aqui também --
	senão o guia ensina teclas que não fazem nada.
	*/
	public static function teclas($modo) {
		if($modo === 'servidor'){
			return [
				['Setas', 'Direcção'],
				['K', 'Cruz ✕'], ['L', 'Círculo ○'], ['J', 'Quadrado □'], ['I', 'Triângulo △'],
				['Q / E', 'L1 / R1'], ['1 / 3', 'L2 / R2'],
				['Enter', 'Start'], ['Backspace', 'Select'],
				['W A S D', 'Analógico esquerdo'],
			];
		}
		return [
			['Setas', 'Direcção'],
			['X', 'Botão de baixo (✕ / B)'], ['Z', 'Botão da direita (○ / A)'],
			['S', 'Botão da esquerda (□ / Y)'], ['A', 'Botão de cima (△ / X)'],
			['Q / E', 'L / R'], ['Tab / R', 'L2 / R2'],
			['Enter', 'Start'], ['V', 'Select'],
			['T F G H', 'Analógico esquerdo'],
		];
	}

	//os guias que este utilizador pode ver, por grupo
	public static function visiveis($admin) {
		$r = [];
		foreach (self::todos() as $chave => $g) {
			if(!empty($g['admin']) && !$admin){ continue; }
			$r[$g['grupo']][$chave] = $g;
		}
		return $r;
	}

	public static function pegar($chave, $admin) {
		$g = self::todos()[$chave] ?? null;
		if($g === null || (!empty($g['admin']) && !$admin)){ return null; }
		return $g;
	}

	/*
	O texto de um bloco, pronto para o HTML: primeiro escapa-se TUDO, e só
	depois se trocam as duas marcas (**negrito** e `código`) -- ao
	contrário, um < no texto passava para o HTML.
	*/
	public static function formatar($texto) {
		$t = esc($texto);
		$t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
		$t = preg_replace('/`(.+?)`/', '<code>$1</code>', $t);
		return $t;
	}
}
