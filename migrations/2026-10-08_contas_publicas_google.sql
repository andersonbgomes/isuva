-- ===========================================================================
-- Contas criadas pelos próprios jogadores, e a entrada com o Google.
--
-- Mesma estrutura acrescentada a instalacao/instalacao.sql.
-- ===========================================================================

SET NAMES utf8mb4;

-- O identificador da pessoa no Google (o "sub" do id_token): é por ele,
-- e não pelo e-mail, que uma conta se reconhece ao voltar -- o e-mail de
-- uma conta Google pode mudar, o "sub" não.
-- NULL nas contas que nunca entraram com o Google (e UNIQUE aceita vários NULL).
--
-- ip_us: de onde a conta foi criada, como RESUMO (sha256 com o segredo do
-- site) e nunca o IP em claro -- serve só para limitar quantas contas
-- nascem do mesmo sítio por hora (RegistoControlo), e não diz a ninguém
-- que lê a base de dados de onde é cada pessoa.
ALTER TABLE `app_utilizador`
  ADD COLUMN `google_us` varchar(64) DEFAULT NULL,
  ADD COLUMN `ip_us` varchar(64) DEFAULT NULL,
  ADD UNIQUE KEY `google_us_unico` (`google_us`),
  ADD KEY `ip_us` (`ip_us`, `dtc_us`);

-- As credenciais do "Entrar com o Google", preenchidas no painel (Contas >
-- Entrar com o Google). Vazias, o botão não aparece.
INSERT INTO `app_config` (`conf_chave`, `conf_valor`) VALUES
  ('google_id', ''),
  ('google_segredo', '')
ON DUPLICATE KEY UPDATE `conf_valor` = `conf_valor`;
