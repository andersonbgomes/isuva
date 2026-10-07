# Este projecto

Um site de jogos retro: catálogo gerido por um administrador. A maior
parte das consolas (Nintendo, Sega, PS1, PSP) corre **no browser de quem
joga**, pelo EmulatorJS. A PS2 corre **num nó de jogo** (máquina com GPU)
e chega por streaming. PHP, MVC simples, **sem framework**; ver o
`LEIA-ME.md`.

O domínio dos jogos vive em `lib/jogos/`:

- `Consolas.php`: que consolas, que núcleo, que extensões, e o `modo`
  (`browser` ou `servidor`);
- `Armazem.php`: os ficheiros no disco, os envios aos pedaços, e servir
  com Range;
- `Descarga.php`: importar por link, com protecção SSRF;
- `Bios.php`;
- `Fila.php`: as sessões nos nós, a fila, os lugares abandonados;
- `Agente.php`: os pedidos assinados ao nó, e os endereços assinados que
  o nó usa para descarregar daqui;
- `Guias.php`: o texto do "Como usar", um guia por cartão. As tabelas
  de formatos e de BIOS saem do `Consolas.php`, e as teclas de
  `Guias::teclas()`. Mudar as teclas no EmulatorJS ou no PCSX2 obriga a
  mudar essa lista também;
- `Gravacoes.php`: as gravações de cada conta (sram, estados, cartão da
  PS2). Do lado do browser, `tema/padrao/ext/assets/js/gravacoes.js`.
  Do lado do nó, `trazer_cartao`/`devolver_cartao` no `agente.py`.

`no-de-jogo/` é o programa da máquina com GPU (Python, Docker, nginx).
Não corre no site, e o site não o serve. A assinatura HMAC dos pedidos
tem de ser igual nos dois lados (`Agente::assinar()` e `assinar()` no
`agente.py`): mudar uma é mudar as duas.

## Estrutura

- `index.php` — arranque: sessão, erros, autoload, router.
- `vdr/` — o núcleo: `App.php` (router), `Acao.php` (classe base dos
  controladores), `DB_GLOBAL.php` (acesso a dados).
- `app/app.ctrl/*Controlo.php` — um controlador por rota. Todos estendem
  `Acao`.
- `app/app.mdl/` — um modelo por tabela. Todos estendem `DB_GLOBAL`.
- `lib/ajuda/_values_.php` — as funções globais (`url_base()`, `esc()`,
  `csrf_*()`, `nlog()`).
- `lib/ajuda/head.php` — o `<head>` e o título de cada rota.
- `lib/ajuda/icones.php` — `icone('nome')`, os ícones do tema (Lucide, em linha).
- `tema/` — os temas. O activo vem da definição `tema` (`app_config`).
- `instalacao/instalacao.sql` — o que monta uma base de dados de raiz.
- `migrations/` — as alterações à base de dados, uma por ficheiro.
- `armazem/` — os jogos, capas e BIOS enviados (fora do git, fechado ao
  exterior). Nada daqui é servido directamente: sai tudo pelo
  `JogarControlo` (depois de verificar a sessão) ou pelo `NoControlo`
  (endereço assinado, para o nó de jogo).
- `no-de-jogo/` — o agente do nó de jogo e a imagem Docker da PS2. Tem o
  seu `LEIA-ME.md`; validar com `python3 -m py_compile` antes de commitar.

O construtor de `Acao` exige sessão iniciada em todos os controladores
excepto os da lista `Acao::SEM_SESSAO`.

## Como se cria um módulo

1. `app/app.ctrl/<Rota>Controlo.php`, a estender `Acao`. Um método
   público por acção; o `index()` é a acção por omissão. Métodos
   auxiliares são `private`/`protected` — **só os públicos são rotas**.
2. `tema/<tema>/<rota>/`, com um `.phtml` por vista.
3. `app/app.mdl/<Modelo>.php` por tabela — copiar o `Usuario.php`, que
   tem as instruções de uso todas.
4. A tabela em `migrations/` **e** em `instalacao/instalacao.sql`.
5. Um `case` em `lib/ajuda/head.php` (título) e uma linha em
   `tema/<tema>/extras/leftsidebar.phtml` (menu).

## BIOS: nunca para descarregar

As BIOS têm direitos de autor. O site entrega-as **ao emulador**
(`JogarControlo::bios`, `NoControlo::bios`), e a página `/bios` só diz o
estado de cada consola. Não se acrescenta um botão de descarregar BIOS,
nem uma lista de links para sites que as oferecem.

## Segurança — aplicar sempre

### Escapar tudo o que vem de dados ou do utilizador

Nenhum valor vindo da base de dados ou de `$_GET`/`$_POST` é impresso
numa vista sem passar por `esc()`. Isto inclui nomes, descrições,
observações e **qualquer célula de tabela construída por concatenação em
PHP** — `echo '<td>'.$valor.'</td>'` continua a precisar de `esc($valor)`.

### Validação no servidor

Os formulários têm `required` no HTML, mas isso não chega: o controlador
valida outra vez antes de gravar — campos obrigatórios, tamanho máximo,
tipos. Voltar com a primeira queixa, em vez de gravar meio registo.

### CSRF

Todo o formulário que altera dados (criar/editar/eliminar) leva
`<?=csrf_field()?>` dentro do `<form>`, e o controlador valida com
`csrf_valido()` logo no início do bloco `POST`, **antes de tocar em
qualquer dado**.

### Palavras-passe

Sempre `password_hash()` / `password_verify()`. Nunca `md5()` nem
`sha1()` — não são encriptação, são resumos, e quebram-se em segundos.

## Base de dados

### A regra dos dois sítios

Sempre que uma alteração ao código precisar de uma coluna, tabela ou
índice novo:

1. um ficheiro `.sql` em `migrations/` (nome com data + descrição), para
   as instalações que já existem;
2. a **mesma** estrutura em `instalacao/instalacao.sql`, para as
   instalações novas nascerem já com ela.

Colunas novas sempre opcionais (`NULL` ou com `DEFAULT`), para não
partirem os registos que já existem.

O ponto 2 é o que toda a gente esquece, e o preço só aparece meses
depois: uma instalação de raiz que rebenta com *Unknown column*.

### Ordenar: nunca colar `ORDER BY` a um valor

O `DB_GLOBAL` liga cada valor por `PDO::bindValue()`. Texto colado ao
**valor** de uma condição fica preso dentro de um parâmetro e nunca chega
a ser SQL — a ordenação simplesmente não acontece, sem erro nenhum:

```php
// ERRADO — não ordena nada
$m->__add('dados', ['stto_x =' => 1 . ' ORDER BY `nome_x` ASC']);

// CERTO
$m->__add('dados', ['stto_x =' => 1]);
$m->__add('ordem', '`nome_x` ASC');
```

Uma ordenação escolhida pelo utilizador escolhe-se **em PHP**, de entre
valores fixos (`'ASC'`/`'DESC'`), nunca a partir de `$_GET`/`$_POST`.

Para consultas com `JOIN` ou cálculos, escrever a query com
`Con::ecta()->prepare()` directamente — o `DB_GLOBAL` lê de uma tabela só.

## Tratamento de erros

`lib/erros.php`, incluído logo no arranque, apanha avisos, erros e
excepções e regista-os em `lib/logs/erros.log` (fora do git) em vez de os
deixar aparecer em bruto no ecrã.

É uma rede de segurança para o utilizador nunca ver um erro em bruto —
**não substitui corrigir o problema na origem**. Qualquer aviso estranho
(undefined index, foreach sobre null) corrige-se onde nasce.

## Trabalho

- Validar sempre a sintaxe PHP alterada com `php -l <ficheiro>` antes de
  commitar.
- Mensagens de commit em português, a explicar o **porquê** e não só o
  quê.
- Comentários no código em português, a explicar as decisões — sobretudo
  as que não são óbvias e as que já custaram caro uma vez.
