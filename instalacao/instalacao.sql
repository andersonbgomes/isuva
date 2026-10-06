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
  ('url',       '')
ON DUPLICATE KEY UPDATE `conf_valor` = `conf_valor`;


-- ---------------------------------------------------------------------------
-- app_utilizador — quem entra
-- ---------------------------------------------------------------------------
-- A palavra-passe fica em `pss_us`, sempre por password_hash() e nunca em
-- texto. São 255 caracteres porque o algoritmo por omissão do PHP muda com
-- as versões e os hashes futuros são mais compridos do que os de hoje —
-- uma coluna curta corta o hash a meio e ninguém mais entra.
--
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
  PRIMARY KEY (`id_us`),
  UNIQUE KEY `email_us_unico` (`email_us`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ---------------------------------------------------------------------------
-- As tabelas do projecto entram abaixo desta linha.
-- ---------------------------------------------------------------------------


SET FOREIGN_KEY_CHECKS = 1;
