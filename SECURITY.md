# Segurança

Não publique no GitHub arquivos gerados em produção, principalmente o banco SQLite em `storage/rssmotor.sqlite`.

Esse banco pode conter chaves de API, tokens dos feeds, chave do cron e dados de login.

Antes de abrir um repositório público, rode:

```bash
git status --ignored
```

Se algum arquivo sensível aparecer como rastreado, remova do índice com:

```bash
git rm --cached caminho/do/arquivo
```
