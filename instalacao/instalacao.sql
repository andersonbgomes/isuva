-- ===========================================================================
-- O QUE A BASE DE DADOS PRECISA DE TER PARA A APLICAÇÃO ARRANCAR.
--
-- É este ficheiro, e só este, que monta uma instalação de raiz: o
-- instalador (instalar/) corre-o instrução a instrução contra a base de
-- dados vazia que lhe indicarem.
--
-- REGRA QUE NÃO SE QUEBRA
--
-- Sempre que uma alteração ao código precisar de uma tabela, de uma coluna
-- ou de um índice novo:
--
--   1. escreve-se um ficheiro .sql em migrations/ (data + descrição), para
--      as instalações QUE JÁ EXISTEM se porem em dia;
--   2. acrescenta-se a MESMA estrutura aqui, para as instalações NOVAS
--      nascerem já com ela.
--
-- O passo 2 é o que toda a gente esquece, e o preço só aparece meses
-- depois: uma instalação de raiz que rebenta com "Unknown column", num
-- computador onde ninguém vai aplicar migrações nenhumas.
--
-- Colunas novas sempre opcionais (NULL ou com DEFAULT), para não partirem
-- as linhas que já existem.
-- ===========================================================================

SET NAMES utf8mb4;
SET time_zone = '+01:00';
SET FOREIGN_KEY_CHECKS = 0;


-- ---------------------------------------------------------------------------
-- app_config — o que se muda sem tocar no código
-- ---------------------------------------------------------------------------
-- Lê-se com configura('<chave>'). A chave é UNIQUE: é isso que faz o
-- "INSERT ... ON DUPLICATE KEY UPDATE" funcionar como "gravar a definição".
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_config` (
  `conf_id`    int NOT NULL AUTO_INCREMENT,
  `conf_chave` varchar(190) NOT NULL,
  `conf_valor` text NOT NULL,
  PRIMARY KEY (`conf_id`),
  UNIQUE KEY `conf_chave_unica` (`conf_chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- As definições de que a aplicação não passa sem elas.
-- O `url` é escrito pelo instalador a partir do endereço por onde ele
-- próprio foi aberto: errado, não há uma única página que funcione.
INSERT INTO `app_config` (`conf_chave`, `conf_valor`) VALUES
  ('tit',       'A Minha Aplicação'),
  ('descricao', 'Aplicação criada a partir do esqueleto MVC.'),
  ('tema',      'padrao'),
  ('url',       ''),
  -- de onde o browser carrega o EmulatorJS (Admin > Emulador)
  ('emu_dados', 'https://cdn.emulatorjs.org/stable/data/'),
  -- o "Entrar com o Google" (Contas > Entrar com o Google); vazias, não há botão
  ('google_id', ''),
  ('google_segredo', '')
ON DUPLICATE KEY UPDATE `conf_valor` = `conf_valor`;


-- ---------------------------------------------------------------------------
-- app_utilizador — quem entra
-- ---------------------------------------------------------------------------
-- A palavra-passe fica em `pss_us`, sempre por password_hash() e nunca em
-- texto. São 255 caracteres porque o algoritmo por omissão do PHP muda com
-- as versões e os hashes futuros são mais compridos do que os de hoje —
-- uma coluna curta corta o hash a meio e ninguém mais entra.
--
--   google_us o "sub" da conta Google, quando entra com o Google (ver
--             lib/contas/Conta.php); NULL nas outras
--   ip_us     o resumo do IP de onde a conta foi criada (só para limitar
--             contas por hora, ver RegistoControlo); nunca o IP em claro
--   nivl_us   1 administrador, 2 utilizador. É o gancho por onde um dia
--             entram as permissões a sério.
--   stto_us   1 activo, 0 desligado. Desligar não apaga: o histórico de
--             quem fez o quê continua a fazer sentido.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_utilizador` (
  `id_us`    int NOT NULL AUTO_INCREMENT,
  `nome_us`  varchar(150) NOT NULL,
  `email_us` varchar(190) NOT NULL,
  `pss_us`   varchar(255) NOT NULL,
  `nivl_us`  int NOT NULL DEFAULT 2,
  `stto_us`  int NOT NULL DEFAULT 1,
  `dtc_us`   datetime DEFAULT NULL,
  `google_us` varchar(64) DEFAULT NULL,
  `ip_us`     varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id_us`),
  UNIQUE KEY `email_us_unico` (`email_us`),
  UNIQUE KEY `google_us_unico` (`google_us`),
  KEY `ip_us` (`ip_us`, `dtc_us`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------------
-- As tabelas do projecto entram abaixo desta linha.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- app_jogo — um jogo do catálogo
-- ---------------------------------------------------------------------------
--   consola_jg   a chave da consola em lib/jogos/Consolas.php (psx, snes, ...)
--   ficheiro_jg  o nome NO DISCO, aleatório, dentro de armazem/jogos/
--   nome_jg      o nome original, com a extensão — vai no fim do endereço,
--                porque é por ele que o EmulatorJS escolhe o que arrancar
--   origem_jg    o link de onde foi descarregado, quando veio por link
--   capa_jg      o nome no disco da capa, dentro de armazem/capas/
--   stto_jg      1 visível no catálogo, 0 escondido
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_jogo` (
  `id_jg`        int NOT NULL AUTO_INCREMENT,
  `titulo_jg`    varchar(150) NOT NULL,
  `consola_jg`   varchar(20) NOT NULL,
  `ficheiro_jg`  varchar(64) NOT NULL,
  `nome_jg`      varchar(190) NOT NULL,
  `tamanho_jg`   bigint NOT NULL DEFAULT 0,
  `origem_jg`    varchar(500) DEFAULT NULL,
  `capa_jg`      varchar(64) DEFAULT NULL,
  `descricao_jg` text DEFAULT NULL,
  `stto_jg`      int NOT NULL DEFAULT 1,
  `us_jg`        int DEFAULT NULL,
  `dtc_jg`       datetime DEFAULT NULL,
  PRIMARY KEY (`id_jg`),
  KEY `consola_jg` (`consola_jg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



-- ---------------------------------------------------------------------------
-- app_no — um nó de jogo: uma máquina com placa gráfica que corre os
-- emuladores de 'servidor' (PS2) e envia a imagem por streaming
-- ---------------------------------------------------------------------------
--   api_no         o endereço da API do agente (ex.: https://no1.site.ao:7443)
--   publico_no     o endereço por onde os jogadores lá chegam, sem porta
--                  (ex.: https://no1.site.ao)
--   porta_no       a porta do lugar 0; o lugar N fica em porta_no + N
--   capacidade_no  quantos jogadores ao mesmo tempo
--   segredo_no     o segredo partilhado com o agente: assina os pedidos
--                  do site ao nó e os endereços dos ficheiros que o nó
--                  descarrega daqui
--   stto_no        1 activo, 0 desligado (não recebe sessões novas)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_no` (
  `id_no`         int NOT NULL AUTO_INCREMENT,
  `nome_no`       varchar(100) NOT NULL,
  `api_no`        varchar(255) NOT NULL,
  `publico_no`    varchar(255) NOT NULL,
  `porta_no`      int NOT NULL DEFAULT 8443,
  `capacidade_no` int NOT NULL DEFAULT 1,
  `segredo_no`    varchar(128) NOT NULL,
  `stto_no`       int NOT NULL DEFAULT 1,
  `dtc_no`        datetime DEFAULT NULL,
  PRIMARY KEY (`id_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------------
-- app_sessao — um jogador a jogar (ou à espera de jogar) num nó
-- ---------------------------------------------------------------------------
--   estado_ss   fila | a_preparar | a_jogar | terminada | erro
--   no_ss       o nó que a recebeu (NULL enquanto está na fila)
--   slot_ss     o lugar dentro do nó (define a porta)
--   token_ss    a chave de entrada no nó, só desta sessão
--   vivo_ss     a última vez que a página do jogador deu sinal: sem sinal
--               durante uns minutos, a sessão acaba e o lugar liberta-se
--   vivo_no_ss  a última vez que esse sinal foi passado ao nó
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_sessao` (
  `id_ss`       int NOT NULL AUTO_INCREMENT,
  `us_ss`       int NOT NULL,
  `jogo_ss`     int NOT NULL,
  `no_ss`       int DEFAULT NULL,
  `slot_ss`     int DEFAULT NULL,
  `estado_ss`   varchar(16) NOT NULL DEFAULT 'fila',
  `token_ss`    varchar(64) NOT NULL,
  `msg_ss`      varchar(255) DEFAULT NULL,
  `vivo_ss`     datetime DEFAULT NULL,
  `vivo_no_ss`  datetime DEFAULT NULL,
  `dtc_ss`      datetime DEFAULT NULL,
  `fim_ss`      datetime DEFAULT NULL,
  PRIMARY KEY (`id_ss`),
  KEY `estado_ss` (`estado_ss`),
  KEY `us_ss` (`us_ss`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



-- ---------------------------------------------------------------------------
-- app_gravacao — as gravações de cada conta, guardadas no servidor
-- ---------------------------------------------------------------------------
--   tipo_gv     sram    a gravação do próprio jogo (o "cartão de memória"
--                       das consolas de browser), uma por jogo
--               estado  um estado guardado pelo botão do emulador, até 9
--                       por jogo (slot_gv 1..9)
--               cartao  o cartão de memória da PS2, um por consola (jogo_gv 0):
--                       na PS2 um cartão serve para todos os jogos
--   jogo_gv     o jogo, ou 0 quando a gravação é da consola (cartao)
--   ficheiro_gv o nome no disco, aleatório, dentro de armazem/gravacoes/
--
-- A chave única é o que faz "gravar" substituir a anterior em vez de
-- acumular cópias: há uma linha por conta + tipo + consola + jogo + lugar.
-- jogo_gv é 0 e não NULL por causa disto -- no MySQL dois NULL nunca são
-- iguais, e a chave única deixava passar cartões repetidos.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_gravacao` (
  `id_gv`          int NOT NULL AUTO_INCREMENT,
  `us_gv`          int NOT NULL,
  `tipo_gv`        varchar(10) NOT NULL,
  `consola_gv`     varchar(20) NOT NULL,
  `jogo_gv`        int NOT NULL DEFAULT 0,
  `slot_gv`        int NOT NULL DEFAULT 0,
  `ficheiro_gv`    varchar(64) NOT NULL,
  `tamanho_gv`     bigint NOT NULL DEFAULT 0,
  `actualizado_gv` datetime DEFAULT NULL,
  PRIMARY KEY (`id_gv`),
  UNIQUE KEY `gravacao_unica` (`us_gv`, `tipo_gv`, `consola_gv`, `jogo_gv`, `slot_gv`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------------
-- app_sugestao — os links que os jogadores sugerem para o catálogo
-- ---------------------------------------------------------------------------
--   estado_sg   0 à espera, 1 aceite (jogo_sg é o jogo que nasceu dela),
--               2 recusada (motivo_sg, opcional, é o que o jogador lê)
--   link_sg     NUNCA é visitado pelo servidor enquanto é só sugestão: só
--               quando o administrador aceita, e aí pelo mesmo caminho do
--               "Importar por link" (Descarga.php, com a protecção SSRF)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_sugestao` (
  `id_sg`      int NOT NULL AUTO_INCREMENT,
  `us_sg`      int NOT NULL,
  `titulo_sg`  varchar(150) NOT NULL,
  `consola_sg` varchar(20) NOT NULL,
  `link_sg`    varchar(500) NOT NULL,
  `nome_sg`    varchar(180) DEFAULT NULL,
  `nota_sg`    varchar(500) DEFAULT NULL,
  `estado_sg`  tinyint NOT NULL DEFAULT 0,
  `motivo_sg`  varchar(300) DEFAULT NULL,
  `jogo_sg`    int DEFAULT NULL,
  `dtc_sg`     datetime DEFAULT NULL,
  `dtr_sg`     datetime DEFAULT NULL,
  PRIMARY KEY (`id_sg`),
  KEY `sugestao_estado` (`estado_sg`),
  KEY `sugestao_conta` (`us_sg`, `dtc_sg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


SET FOREIGN_KEY_CHECKS = 1;
