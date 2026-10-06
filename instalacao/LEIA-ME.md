# instalacao/

O `instalacao.sql` é o que monta uma base de dados **de raiz**. O
instalador (`instalar/`) corre-o instrução a instrução contra a base de
dados que lhe indicarem.

## À mão, sem o instalador

```bash
mysql -u <utilizador> -p <base_de_dados> < instalacao/instalacao.sql
cp app/Conecta.exemplo.php app/Conecta.php   # e preencher as quatro linhas
```

Falta ainda criar a primeira conta — o instalador fá-lo, à mão faz-se
com um `INSERT` na `app_utilizador` cuja `pss_us` tem de sair de
`password_hash()`:

```bash
php -r "echo password_hash('a-sua-palavra-passe', PASSWORD_DEFAULT), PHP_EOL;"
```

## A regra que não se quebra

Toda a alteração à base de dados entra **nos dois sítios**: num ficheiro
novo em `migrations/` (para quem já tem o sistema instalado) e aqui
(para quem o instalar de raiz amanhã).
