# Deploy na Hostinger

## Estrutura recomendada

- `public_html/`
  - arquivos do projeto raiz
- `public_html/admin/`
  - conteudo da pasta `files/`

## Banco de dados

1. Crie um banco MySQL no painel da Hostinger.
2. Importe `database.sql`.
3. Se ja tiver banco antigo, faca backup antes.
4. Se a instalacao ja estiver rodando e voce quiser ativar configuracoes, funis, downsells, fluxos e mailing, execute `database_update_funis.sql`.

## Variaveis / configuracao inicial

Preencha em `config.php` ou com variaveis de ambiente:

- `SITE_URL`
- `BASE_URL`
- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `ECOMPAG_CLIENT_ID`
- `ECOMPAG_CLIENT_SECRET`
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_GROUP_ID`
- `WEBHOOK_SECRET`
- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_USER`
- `SMTP_PASSWORD`

Depois do primeiro login no admin, voce pode ajustar Telegram, Ecompag, URLs, SMTP, Meta e segredo do webhook pela tela `Configuracoes`.

## Telegram

1. Adicione o bot no grupo.
2. Promova o bot como administrador.
3. Permita criar links de convite e banir usuarios.
4. Descubra o ID do grupo e salve em `TELEGRAM_GROUP_ID`.

## Webhooks

- Telegram: `https://seu-dominio.com/setup_webhook.php?token=SUA_CHAVE`
- Ecompag notificacao: `https://seu-dominio.com/webhook_pix.php?token=SUA_CHAVE`

## Cron

Configure estes workers:

- `cron_expiracao.php` a cada 5 minutos
- `mailing_worker.php` a cada 1 ou 2 minutos
- `downsell_worker.php` a cada 1 ou 2 minutos
- `flow_worker.php` a cada 1 ou 2 minutos

Se a Hostinger liberar PHP CLI:

```bash
php /home/SEU_USUARIO/public_html/cron_expiracao.php
```

Em hospedagem compartilhada, normalmente o mais seguro e usar URL com token:

```bash
wget -q -O /dev/null "https://seu-dominio.com/cron_expiracao.php?token=SUA_CHAVE"
wget -q -O /dev/null "https://seu-dominio.com/mailing_worker.php?token=SUA_CHAVE"
wget -q -O /dev/null "https://seu-dominio.com/downsell_worker.php?token=SUA_CHAVE"
wget -q -O /dev/null "https://seu-dominio.com/flow_worker.php?token=SUA_CHAVE"
```

## Login inicial do painel

- URL: `https://seu-dominio.com/admin/login.php`
- Email: `admin@admin.com`
- Senha: `Admin@1234`

Troque a senha imediatamente apos o primeiro acesso.

## Ordem pratica recomendada

1. Suba os arquivos.
2. Importe o banco.
3. Configure apenas banco e uma base minima no `config.php`.
4. Entre no painel.
5. Abra `Configuracoes` e salve token do bot, group id, Ecompag e webhook secret.
6. Ajuste as mensagens do fluxo, cadastre produtos, funis, downsells e fluxos no painel, e sincronize os comandos personalizados do bot se usar gatilho por comando.
7. Rode `setup_webhook.php` com o token salvo.
8. Configure o webhook da Ecompag e os workers.
