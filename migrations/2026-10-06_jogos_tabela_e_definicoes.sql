-- ===========================================================================
-- O catálogo de jogos, e as definições do emulador.
--
-- Mesma estrutura acrescentada a instalacao/instalacao.sql.
-- ===========================================================================

SET NAMES utf8mb4;

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

-- De onde o browser carrega o EmulatorJS. Muda-se no painel (Admin > Emulador).
INSERT INTO `app_config` (`conf_chave`, `conf_valor`) VALUES
  ('emu_dados', 'https://cdn.emulatorjs.org/stable/data/')
ON DUPLICATE KEY UPDATE `conf_valor` = `conf_valor`;
