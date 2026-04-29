# RSS Motor Penedo, versão 1

Sistema em PHP para criar feeds RSS full-text a partir de fontes externas, com seleção visual de conteúdo, extração automática, tradução por IA e reescrita jornalística original com SEO.

Criador: **Kim Emmanuel**  
Contato: **kimzaidem@gmail.com**

## Recursos principais

- Cadastro de múltiplos feeds.
- Geração de RSS com token de proteção opcional.
- Extração automática de conteúdo completo.
- Seletor visual para definir título, texto, imagem e data.
- Tradução por IA usando Gemini ou Ollama.
- Reescrita original com SEO por IA.
- Execução manual ou via cron.
- Banco local em SQLite.
- Interface administrativa com login.

## Segurança do repositório

Este pacote foi preparado para GitHub sem incluir banco de dados, chaves Gemini, tokens reais, logs ou arquivos temporários.

O arquivo `storage/rssmotor.sqlite` **não deve ser enviado para o GitHub**. Ele é criado localmente na instalação e pode conter:

- usuários e senha criptografada,
- feeds cadastrados,
- tokens privados dos feeds,
- chave do cron,
- chaves da API Gemini,
- histórico/cache de itens processados.

O `.gitignore` já está configurado para impedir o envio desses arquivos.

## Requisitos

- PHP 8.1 ou superior.
- Extensão PDO SQLite ativa.
- Extensão cURL ativa.
- Servidor Apache com suporte a `.htaccess`, recomendado em hospedagem cPanel.

## Instalação

1. Envie os arquivos para a pasta do domínio ou subdomínio.
2. Confirme que a pasta `storage` tem permissão de escrita pelo PHP.
3. Acesse o sistema pelo navegador.
4. Crie o primeiro usuário na tela de instalação.
5. Entre no painel e configure os feeds.

## Configuração da IA

No painel, acesse **Configurações** e informe a chave global da Gemini API, se for usar Gemini.

Também é possível configurar uma chave específica em cada feed. Essas chaves ficam salvas apenas no banco SQLite local e não entram no GitHub.

Modelo padrão sugerido:

```txt
gemini-2.5-flash-lite
```

## Cron no cPanel

Depois de configurar a chave secreta do cron no painel, use uma chamada parecida com esta no cPanel:

```bash
wget -q -O - "https://seudominio.com.br/rss/cron.php?key=SUA_CHAVE_DO_CRON" >/dev/null 2>&1
```

Você também pode usar `curl`:

```bash
curl -s "https://seudominio.com.br/rss/cron.php?key=SUA_CHAVE_DO_CRON" >/dev/null 2>&1
```

## Uso responsável da reescrita por IA

A função de reescrita foi criada para gerar um texto original, com linguagem própria, preservando a informação principal e melhorando título/conteúdo para SEO.

Mesmo assim, recomenda-se revisão humana antes de publicar, principalmente em matérias jornalísticas, para checar dados, nomes, datas, contexto e evitar reprodução indevida de trechos protegidos por direitos autorais.

## Estrutura do projeto

```txt
app/                 Classes principais do sistema
assets/              CSS e JavaScript da interface
storage/             Banco SQLite e arquivos locais, não versionar dados reais
api.php              API interna para ações AJAX
cron.php             Execução automatizada
feed.php             Saída RSS
index.php            Painel administrativo
install.php          Instalação inicial
selector-live.php    Seletor visual de conteúdo
```

## Antes de publicar no GitHub

Confira se estes arquivos **não aparecem** no commit:

```txt
storage/rssmotor.sqlite
storage/cron.lock
.env
*.sql
*.zip
*.log
```

Comando útil:

```bash
git status --ignored
```

## Licença

Este projeto está licenciado sob a licença MIT. Veja o arquivo `LICENSE`.
