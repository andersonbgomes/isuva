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

O `tema/padrao/` é propositadamente pobre: HTML simples e um CSS escrito
à mão, sem Bootstrap nem framework nenhuma, para não impor uma escolha ao
projecto que vier a seguir.

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
| `/jogos` | o catálogo, com filtro por consola e pesquisa |
| `/jogar/ver/7` | jogar o jogo 7 |
| `/jogar/local` | jogar um ficheiro do próprio computador (não é enviado ao servidor) |
| `/admin` | gerir jogos: só para administradores (`nivl_us = 1`) |
| `/admin/emulador` | de onde vem o EmulatorJS, e as BIOS |
| `/servidores` | os servidores de jogo da PS2 e quem está a jogar: só para administradores |

**Consolas:**

- no browser: NES, SNES, N64, Game Boy/Color, GBA, DS, Master System, Game
  Gear, Mega Drive, Sega CD, 32X, Saturn, PS1 e PSP;
- **no servidor:** PS2 (ver abaixo).

A lista, com os formatos aceites, está em `lib/jogos/Consolas.php`. A PS3
ainda não entra: precisaria de uma máquina inteira por jogador.

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

### BIOS

A PS1, o Saturn, o GBA e o DS correm sem BIOS, mas melhor com ela. O
**Sega CD não arranca sem ela**. As BIOS têm direitos de autor e não vêm
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

O progresso dos jogos fica guardado **no browser** de cada pessoa
(IndexedDB, pelo EmulatorJS). Outro computador ou outro browser não tem
as gravações. Guardá-las no servidor, por conta, é um passo seguinte
natural.

### Direitos de autor

Ponha no catálogo só jogos de que tem o direito de distribuir:
*homebrew*, jogos livres, ou cópias das suas consolas e discos num site
privado. Pôr jogos comerciais à disposição do público é pirataria.

