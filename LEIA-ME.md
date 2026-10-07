# MVC

Esqueleto de aplicação PHP — **MVC simples, sem framework**. É o ponto de
partida de um projecto novo: traz o router, o controlador base, a camada
de acesso a dados, o instalador, um tema em branco e nada mais.

Não é uma aplicação. É o chão em que uma aplicação se põe de pé.

## Pôr a andar

1. Criar uma base de dados vazia em MySQL/MariaDB.
2. Abrir a pasta no browser. Como ainda não há `app/Conecta.php`, a raiz
   manda sozinha para o instalador.
3. Três passos — requisitos, base de dados, conta — e está.
4. **Apagar a pasta `instalar/`.**

Depois disso, entrar com a conta criada: o "Olá Mundo" é a primeira
página.

## O que há aqui

```
index.php              arranque: sessão, erros, autoload, router
.htaccess              endereços sem o /index.php

vdr/                   o núcleo
  App.php              o router: endereço -> controlador -> acção
  Acao.php             a classe base de todos os controladores
  DB_GLOBAL.php        a camada de acesso a dados (todos os modelos a estendem)

app/
  Conecta.exemplo.php  o modelo do ficheiro de ligação (o real não entra no git)
  app.ctrl/            os controladores, um por rota
  app.mdl/             os modelos, um por tabela

lib/
  define.php           as constantes
  erros.php            a rede que apanha avisos e excepções
  logs/                onde o registo de erros é escrito
  ajuda/               as funções globais (endereços, sessão, CSRF, escape)

tema/padrao/           o tema: a moldura, as vistas e o CSS
instalacao/            o SQL que monta uma base de dados de raiz
instalar/              o instalador (apagar depois de instalar)
migrations/            as alterações à base de dados, uma por ficheiro
```

## Como funciona um pedido

```
/clientes/editar/7
   |
   |  .htaccess  ->  index.php/clientes/editar/7
   |
   |  vdr/App.php lê o PATH_INFO e define três constantes:
   |      rota = clientes     acao = editar      id = 7
   |
   |  instancia ClientesControlo e chama ->editar()
   |
   |  o controlador junta o que precisa em $this->ver->*
   |  e chama $this->renderizar('editar')
   |
   `-> tema/padrao/clientes/editar.phtml, dentro da moldura do tema
```

Sem rota, abre o `IndexControlo` (ver `rota_default`, em `lib/define.php`).
Rota ou acção que não existam dão um 404 a sério.

**Só métodos públicos são rotas.** Um método auxiliar declara-se `private`
ou `protected` e o router não lhe pega.

## Criar um módulo novo

Cinco ficheiros, sempre os mesmos:

| O quê | Onde |
|---|---|
| O controlador | `app/app.ctrl/ClientesControlo.php` |
| As vistas | `tema/padrao/clientes/*.phtml` |
| O modelo | `app/app.mdl/Cliente.php` (copiar o `Usuario.php`) |
| A tabela | um `.sql` em `migrations/` **e** em `instalacao/instalacao.sql` |
| O título e o menu | um `case` em `lib/ajuda/head.php`, uma linha em `tema/padrao/extras/leftsidebar.phtml` |

O `IndexControlo` e o `Usuario` estão comentados de ponta a ponta para
servirem de molde.

## As três regras que não se discutem

**Escapar tudo o que vem de fora.** Nenhum valor da base de dados ou do
`$_GET`/`$_POST` é impresso numa vista sem `esc()`. Sem excepção, e
incluindo as células de tabela construídas por concatenação em PHP.

**Validar no servidor.** O `required` do HTML trava quem usa o ecrã, não
quem manda um POST à mão. O controlador valida outra vez — obrigatórios,
tamanho máximo, tipo — antes de gravar.

**CSRF em tudo o que altera.** `<?=csrf_field()?>` dentro do `<form>`, e
`csrf_valido()` no início do bloco POST, antes de tocar em qualquer dado.

## Base de dados

Toda a alteração entra em **dois sítios**: um ficheiro novo em
`migrations/` (para as instalações que já existem) e a mesma estrutura em
`instalacao/instalacao.sql` (para as que nascerem amanhã). Esquecer o
segundo não dói hoje — dói no dia em que alguém instalar de raiz e a
aplicação rebentar com *Unknown column*.

## Tema

O `tema/padrao/` é escuro e feito para jogos, inspirado no TailGame
(taildashboards.com):

- fundo zinco, verde-água como cor de destaque;
- uma barra de ícones à esquerda (em baixo, no telemóvel);
- pesquisa em cápsula no topo;
- cartões de jogo com a capa a ocupar tudo.

O CSS (`ext/assets/css/estilo.css`) é **escrito à mão, sem Tailwind**.
O Tailwind sem passo de compilação compila no browser de cada visitante,
e é lento para os computadores fracos que são o público deste site. As
cores estão em variáveis no topo do ficheiro.

Os ícones são do Lucide (licença ISC), desenhados em linha por
`icone('nome')` (`lib/ajuda/icones.php`).

Um tema novo é uma pasta ao lado, com os mesmos ficheiros em `extras/`.
Troca-se na definição `tema`, na tabela `app_config`.

## Os jogos

O site é um catálogo de jogos retro. O emulador (o
[EmulatorJS](https://emulatorjs.org), núcleos do RetroArch compilados
para WebAssembly) corre **no browser de quem joga**. O servidor só
entrega a página e o ficheiro do jogo, por isso um alojamento PHP normal
aguenta muitos jogadores.

| Rota | O quê |
|---|---|
| `/jogos` | o catálogo: continuar a jogar, novidades e uma secção por família; filtros e pesquisa |
| `/jogos/ver/7` | a tela do jogo 7: capa, descrição, jogar, controlos, gravações |
| `/como-usar` | os guias, um por cartão (os de administração só para administradores) |
| `/bios` | de que BIOS cada consola precisa e se o site já a tem (sem ficheiros para descarregar) |
| `/registo` | criar conta (qualquer pessoa) |
| `/utilizadores` | as contas: activar e desactivar, dar acesso de administrador, entrar com o Google |
| `/jogar/ver/7` | jogar o jogo 7 |
| `/jogar/local` | jogar um ficheiro do próprio computador (não é enviado ao servidor) |
| `/gravacoes` | as gravações da conta: ver, descarregar, apagar |
| `/admin` | gerir jogos: só para administradores (`nivl_us = 1`) |
| `/admin/emulador` | de onde vem o EmulatorJS, e as BIOS |
| `/servidores` | os servidores de jogo da PS2 e quem está a jogar: só para administradores |

**Consolas:**

- no browser: NES, SNES, N64, Game Boy/Color, GBA, DS, Master System, Game
  Gear, Mega Drive, Sega CD, 32X, Saturn, PS1 e PSP;
- **no servidor:** PS2 (ver abaixo).

A lista, com os formatos aceites, está em `lib/jogos/Consolas.php`. A PS3
ainda não entra: precisaria de uma máquina inteira por jogador.

### Um site aberto: conta só para guardar

Qualquer pessoa entra e joga **sem conta**: o catálogo, a tela dos jogos,
as consolas de browser, o "Como usar" e a página BIOS são públicos
(`Acao::SEM_SESSAO`). A conta serve para **guardar**:

- **Sem conta**, o jogo grava no browser, como sempre. Quando grava (ou
  quando se carrega em "Guardar estado"), aparece um convite para criar
  conta. Quem a cria depois não perde nada: a gravação do browser passa
  para a conta da próxima vez que abrir o jogo.
- **Criar conta** (`/registo`): nome, e-mail e palavra-passe, e entra logo.
  Contra robôs há um campo-armadilha e um limite de 3 contas por hora por
  origem (o IP fica só como resumo, nunca em claro).
- **Entrar com o Google:** o administrador põe o ID de cliente e o segredo
  em **Contas → Entrar com o Google** (o ecrã explica onde os obter, e
  mostra o endereço de volta a registar no Google). Sem eles, o botão não
  aparece.
- **Pedem conta:** gravar no servidor, a PS2 (cada jogador ocupa uma placa
  gráfica) e o Sega CD (precisa da BIOS). As **BIOS só vão para quem tem
  conta**: num site aberto, o ficheiro que o browser recebe ficava ao
  alcance de qualquer visitante. Sem conta, o emulador usa a BIOS de
  substituição que já traz.

### Adicionar jogos

- **Por ficheiro:** o browser envia-o aos pedaços (até 8 MB cada), por
  isso o `upload_max_filesize` e o `post_max_size` do servidor não limitam
  o tamanho do jogo. O limite é o `TAMANHO_MAX_JOGO` (2 GB), em
  `lib/define.php`, e é do browser: o emulador carrega o jogo inteiro para
  a memória.
- **Por link:** o servidor descarrega o ficheiro e guarda-o (precisa da
  extensão `curl` do PHP). Tem de ser um link de descarga directa. Links
  para a rede interna são recusados.
- **Formatos de CD** (PS1, Sega CD, Saturn): o melhor é um `.chd`, ou o
  `.cue` e os `.bin` juntos num `.zip`. Um `.bin` sozinho nem sempre arranca.

### Comandos

Qualquer comando que o computador reconheça funciona, por USB ou Bluetooth: o da PS4, o da PS5, o da Xbox, o Switch Pro e comandos genéricos. Chegam ao browser pela Gamepad API, e não é preciso instalar nada.

- **`/comandos`:** mostra o comando desenhado, com cada botão a acender, os analógicos e os gatilhos a mexer, e testa a vibração.
- **O ecrã de jogo** tem um indicador. Diz se o comando foi reconhecido e com que jogador ficou, ou o que falta.
- **Os dois motivos de "não funciona":**
  - o browser só mostra o comando **depois de se premir um botão** com a página aberta;
  - os browsers só dão comandos a sites em **HTTPS**.
- **No EmulatorJS:** o `comandos.js` põe qualquer comando sem jogador no primeiro lugar livre. O EmulatorJS só o faz no instante em que o comando aparece.

### BIOS

A PS1, o Saturn, o GBA e o DS correm sem BIOS, mas melhor com ela. O
**Sega CD não arranca sem ela**. Pode enviar-se o **.zip** tal como se descarregou: na PS2 o site tira de lá a BIOS principal; nas outras o .zip fica inteiro, porque o EmulatorJS abre-o sozinho. As BIOS têm direitos de autor e não vêm
com o site: o administrador envia as suas em `/admin/emulador`, **com o
nome original** (`scph5501.bin`, `bios_CD_U.bin`, ...), porque é pelo
nome que o emulador as procura.

### EmulatorJS: CDN ou cópia própria

Por omissão o browser carrega o EmulatorJS da CDN oficial
(`https://cdn.emulatorjs.org/stable/data/`). Para não depender dela:

1. descarregar a última versão em
   <https://github.com/EmulatorJS/EmulatorJS/releases> (traz os núcleos);
2. copiar a pasta `data/` para `emulatorjs/data/`, na raiz do site (está
   no `.gitignore`: são centenas de MB);
3. em `/admin/emulador`, escrever `emulatorjs/data/`.

### O que o servidor precisa

- **HTTPS.** O PSP usa threads (`SharedArrayBuffer`), e os browsers só as
  dão a páginas em HTTPS com os cabeçalhos COOP/COEP. O
  `JogarControlo::isolar()` manda os cabeçalhos, e o HTTPS fica do lado do
  alojamento. Sem HTTPS, as outras consolas funcionam na mesma. No Safari
  o PSP não arranca.
- **`armazem/` e `no-de-jogo/` fechados ao exterior.** No Apache tratam
  disso os `.htaccess` de cada pasta. No **nginx**, que não lê `.htaccess`, é preciso um
  `location ~ ^/(armazem|no-de-jogo)/ { deny all; }`, ou então apontar o `PASTA_ARMAZEM`
  (`lib/define.php`) para uma pasta fora do site.
- **Tempo para descargas por link.** A descarga corre durante o pedido. Um
  jogo grande num alojamento com `max_execution_time` curto (ou um proxy
  com tempo limite) pode ser cortado a meio. Aí, o melhor é enviar o ficheiro.

### PS2: jogos que correm no servidor

Não há emulador de PS2 que corra num browser, por isso a PS2 corre num
**nó de jogo**: uma máquina com placa gráfica NVIDIA, onde cada jogador
tem o seu PCSX2 num contentor. A imagem chega ao browser por streaming
(WebRTC, com o Selkies). O computador do jogador só recebe vídeo; o que
conta é a ligação à internet.

- **Cada jogador ocupa um lugar** do nó enquanto joga. Quando os lugares
  estão todos ocupados, quem chega fica numa **fila** e entra por ordem de
  chegada (`lib/jogos/Fila.php`). Não precisa de tarefa agendada: a fila
  anda com as perguntas das páginas de quem espera.
- **Um lugar abandonado** (separador fechado) liberta-se ao fim de 2
  minutos sem sinal.
- **O nó copia o jogo do site** na primeira vez, por um endereço assinado
  (`NoControlo`), e guarda-o em cache.
- **Os cartões de memória** ficam guardados por jogador, no nó.
- **A BIOS da PS2 é obrigatória** (*Gerir jogos → Emulador e BIOS*). Vai
  aos pedaços como os jogos, porque tem 4 MB e muitos servidores só aceitam
  2 MB por envio.

A instalação do nó (a máquina, o Docker, o nginx, o TURN e como medir
quantos jogadores aguenta) está em **`no-de-jogo/LEIA-ME.md`**. Depois de
instalado, o nó acrescenta-se em `/servidores`.

### Gravações

O progresso de cada jogo fica **na conta da pessoa, no servidor**.
Continua noutro computador ou noutro browser. Está em
`lib/jogos/Gravacoes.php`.

| O quê | Quando sobe | Quando desce |
|---|---|---|
| A gravação do próprio jogo (cartão de memória da PS1, pilha do cartucho...) | De minuto a minuto, só se mudou, e ao sair | Ao abrir o jogo (e a consola reinicia, para jogos que a lêem no arranque) |
| Os estados (botões "Guardar/Carregar estado" do emulador) | Ao carregar no botão, no lugar 1 a 9 das definições | Ao carregar em "Carregar estado" |
| O cartão de memória da PS2 (um por conta, para todos os jogos) | O nó devolve-o ao terminar e de 5 em 5 minutos | O nó lê-o ao arrancar a sessão |

- Cada conta tem 1 GB (`Gravacoes::QUOTA`). Vê e apaga o que tem em
  **As minhas gravações** (`/gravacoes`), onde também pode descarregar uma
  cópia.
- Quem já tinha gravações só no browser não as perde: na primeira vez que
  abre o jogo, sem nada no servidor, a do browser sobe.
- **Ficam de fora:**
  - os jogos de **"Jogar do meu computador"**, porque não estão no
    catálogo e não há a que os prender. Esses continuam a gravar só no
    browser.
  - as teclas de atalho de estado rápido do EmulatorJS: só os **botões**
    passam pelo servidor.
- Se o site estiver em baixo quando uma sessão de PS2 acaba, o nó guarda o
  cartão e marca-o como pendente. Na sessão seguinte dessa conta nesse
  nó, sobe antes de se ler o do site, e nada se perde.

### Direitos de autor

Ponha no catálogo só jogos de que tem o direito de distribuir:
*homebrew*, jogos livres, ou cópias das suas consolas e discos num site
privado. Pôr jogos comerciais à disposição do público é pirataria.

