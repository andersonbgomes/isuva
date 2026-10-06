# migrations/

Cada alteração à base de dados é um ficheiro `.sql` nesta pasta, com a
data e o que faz no nome:

```
2026-10-06_clientes_coluna_telefone.sql
```

Serve para as instalações **que já existem** se porem em dia. Uma
instalação **nova** não corre nada disto: nasce do
`instalacao/instalacao.sql`.

É por isso que toda a alteração entra nos **dois sítios**:

1. um ficheiro aqui, com o `ALTER TABLE` / `CREATE TABLE`;
2. a mesma estrutura em `instalacao/instalacao.sql`.

Esquecer o ponto 2 não dói hoje — dói no dia em que alguém instalar de
raiz, num computador onde ninguém vai aplicar migrações nenhumas, e a
aplicação rebentar com *Unknown column*.

**Colunas novas sempre opcionais** (`NULL` ou com `DEFAULT`), para não
partirem as linhas que já lá estão.
