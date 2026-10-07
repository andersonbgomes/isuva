-- ===========================================================================
-- Fase 2: os nós de jogo (PS2 por streaming) e as sessões de jogo.
--
-- Mesma estrutura acrescentada a instalacao/instalacao.sql.
-- ===========================================================================

SET NAMES utf8mb4;

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
