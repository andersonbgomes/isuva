# Este projecto

Um site de jogos retro: catálogo gerido por um administrador, e as
consolas (Nintendo, Sega, PS1, PSP) emuladas **no browser de quem joga**
pelo EmulatorJS. PHP, MVC simples, **sem framework** — ver o `LEIA-ME.md`.

O domínio dos jogos vive em `lib/jogos/`: `Consolas.php` (que consolas,
que núcleo, que extensões), `Armazem.php` (ficheiros no disco, envios aos
pedaços, servir com Range), `Descarga.php` (importar por link, com
protecção SSRF) e `Bios.php`. PS2/PS3 não cabem no browser: são a Fase 2,
com streaming a partir de um servidor com GPU — não se acrescentam à
`Consolas::LISTA`.

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
- `tema/` — os temas. O activo vem da definição `tema` (`app_config`).
- `instalacao/instalacao.sql` — o que monta uma base de dados de raiz.
- `migrations/` — as alterações à base de dados, uma por ficheiro.
- `armazem/` — os jogos, capas e BIOS enviados (fora do git, fechado ao
  exterior). Nada daqui é servido directamente: sai tudo pelo
  `JogarControlo`, depois de verificar a sessão.

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
