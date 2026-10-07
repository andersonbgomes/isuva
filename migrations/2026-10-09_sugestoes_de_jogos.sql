-- ===========================================================================
-- As sugestões de jogos: um jogador cola um link, o administrador decide.
--
-- Mesma estrutura acrescentada a instalacao/instalacao.sql.
-- ===========================================================================

SET NAMES utf8mb4;

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
