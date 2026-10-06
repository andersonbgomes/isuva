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

O `IndexControlo`, o `Usuario` e a vista `index/inicio.phtml` estão
comentados de ponta a ponta para servirem de molde.

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
