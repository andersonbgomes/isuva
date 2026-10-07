-- ===========================================================================
-- As gravações no servidor, por conta.
--
-- Mesma estrutura acrescentada a instalacao/instalacao.sql.
-- ===========================================================================

SET NAMES utf8mb4;

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
