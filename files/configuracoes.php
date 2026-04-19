<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();
require_admin($current_admin);

if (!db_has_table('configuracoes')) {
    $page_title = 'Configuracoes';
    $page_subtitle = 'Modulo indisponivel';
    $active_menu = 'configuracoes';
    include '_layout.php';
    ?>
    <div class="alert alert-warning">A tabela <span class="mono">configuracoes</span> ainda nao existe no banco atual. Execute o SQL de atualizacao antes de usar esta tela.</div>
    <?php
    include '_footer.php';
    return;
}

$integrationFields = [
    'site_url' => ['label' => 'Site URL', 'type' => 'text', 'placeholder' => 'https://seudominio.com'],
    'base_url' => ['label' => 'Base URL', 'type' => 'text', 'placeholder' => 'https://seudominio.com'],
    'webhook_secret' => ['label' => 'Webhook secret', 'type' => 'text', 'placeholder' => 'chave-secreta'],
    'telegram_bot_token' => ['label' => 'Token do bot', 'type' => 'text', 'placeholder' => '123456:ABC...'],
    'telegram_group_id' => ['label' => 'Telegram group ID', 'type' => 'text', 'placeholder' => '-1001234567890'],
    'checkout_fixed_name' => ['label' => 'Nome fixo do pagador', 'type' => 'text', 'placeholder' => 'Nome usado no checkout'],
    'checkout_fixed_cpf' => ['label' => 'CPF fixo do checkout', 'type' => 'text', 'placeholder' => '12345678901'],
    'ecompag_client_id' => ['label' => 'Ecompag client ID', 'type' => 'text', 'placeholder' => 'seu_client_id'],
    'ecompag_client_secret' => ['label' => 'Ecompag client secret', 'type' => 'text', 'placeholder' => 'seu_client_secret'],
    'n8n_webhook_url' => ['label' => 'n8n webhook URL', 'type' => 'text', 'placeholder' => 'https://n8n.seudominio.com/webhook/seu-endpoint'],
    'n8n_secret' => ['label' => 'n8n secret', 'type' => 'password', 'placeholder' => 'segredo-compartilhado'],
    'n8n_timeout' => ['label' => 'n8n timeout (segundos)', 'type' => 'number', 'step' => '1', 'placeholder' => '15'],
    'meta_pixel_id' => ['label' => 'Meta pixel ID', 'type' => 'text', 'placeholder' => '123456789012345'],
    'meta_access_token' => ['label' => 'Meta access token', 'type' => 'text', 'placeholder' => 'EAAB...'],
    'meta_test_event_code' => ['label' => 'Meta test event code', 'type' => 'text', 'placeholder' => 'TEST12345'],
    'meta_api_version' => ['label' => 'Meta API version', 'type' => 'text', 'placeholder' => 'v22.0'],
    'meta_action_source' => ['label' => 'Meta action source', 'type' => 'text', 'placeholder' => 'chat'],
    'default_product_name' => ['label' => 'Nome do plano padrao', 'type' => 'text', 'placeholder' => 'Acesso VIP - 30 dias'],
    'default_product_description' => ['label' => 'Descricao padrao', 'type' => 'text', 'placeholder' => 'Acesso completo ao grupo'],
    'default_product_price' => ['label' => 'Valor padrao', 'type' => 'number', 'step' => '0.01', 'placeholder' => '29.90'],
    'default_product_days' => ['label' => 'Dias do plano padrao', 'type' => 'number', 'step' => '1', 'placeholder' => '30'],
    'smtp_host' => ['label' => 'SMTP host', 'type' => 'text', 'placeholder' => 'smtp.hostinger.com'],
    'smtp_port' => ['label' => 'SMTP porta', 'type' => 'number', 'step' => '1', 'placeholder' => '465'],
    'smtp_user' => ['label' => 'SMTP usuario', 'type' => 'text', 'placeholder' => 'email@dominio.com'],
    'smtp_password' => ['label' => 'SMTP senha', 'type' => 'password', 'placeholder' => 'senha'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($integrationFields) as $field) {
        if (array_key_exists($field, $_POST)) {
            app_setting_save($field, trim((string) $_POST[$field]));
        }
    }

    header('Location: ' . admin_url('configuracoes.php?ok=salvo'));
    exit;
}

$values = [
    'site_url' => app_setting('site_url', SITE_URL),
    'base_url' => app_setting('base_url', BASE_URL),
    'webhook_secret' => app_setting('webhook_secret', WEBHOOK_SECRET),
    'telegram_bot_token' => app_setting('telegram_bot_token', TELEGRAM_BOT_TOKEN),
    'telegram_group_id' => app_setting('telegram_group_id', TELEGRAM_GROUP_ID),
    'checkout_fixed_name' => app_setting('checkout_fixed_name', ''),
    'checkout_fixed_cpf' => app_setting('checkout_fixed_cpf', ''),
    'ecompag_client_id' => app_setting('ecompag_client_id', ECOMPAG_CLIENT_ID),
    'ecompag_client_secret' => app_setting('ecompag_client_secret', ECOMPAG_CLIENT_SECRET),
    'n8n_webhook_url' => app_setting('n8n_webhook_url', N8N_WEBHOOK_URL),
    'n8n_secret' => app_setting('n8n_secret', N8N_SECRET),
    'n8n_timeout' => app_setting('n8n_timeout', (string) N8N_TIMEOUT),
    'meta_pixel_id' => app_setting('meta_pixel_id', ''),
    'meta_access_token' => app_setting('meta_access_token', ''),
    'meta_test_event_code' => app_setting('meta_test_event_code', ''),
    'meta_api_version' => app_setting('meta_api_version', 'v22.0'),
    'meta_action_source' => app_setting('meta_action_source', 'chat'),
    'default_product_name' => app_setting('default_product_name', DEFAULT_PRODUCT_NAME),
    'default_product_description' => app_setting('default_product_description', DEFAULT_PRODUCT_DESCRIPTION),
    'default_product_price' => app_setting('default_product_price', (string) DEFAULT_PRODUCT_PRICE),
    'default_product_days' => app_setting('default_product_days', (string) DEFAULT_PRODUCT_DAYS),
    'smtp_host' => app_setting('smtp_host', SMTP_HOST),
    'smtp_port' => app_setting('smtp_port', (string) SMTP_PORT),
    'smtp_user' => app_setting('smtp_user', SMTP_USER),
    'smtp_password' => app_setting('smtp_password', SMTP_PASSWORD),
];

$page_title = 'Configuracoes';
$page_subtitle = 'Integre Telegram, Ecompag, n8n e checkout sem editar arquivos';
$active_menu = 'configuracoes';
include '_layout.php';
?>

<div class="content-grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));">
  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Integracoes e credenciais</h2>
        <p class="card-copy">Ajuste URLs, credenciais e parametros padrao do sistema.</p>
      </div>
    </div>
    <div class="card-body">
      <form method="POST" class="stack">
        <div class="form-grid">
          <?php foreach (['site_url', 'base_url'] as $field): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($integrationFields[$field]['label']) ?></label>
              <input
                class="form-control"
                id="<?= htmlspecialchars($field) ?>"
                type="<?= htmlspecialchars($integrationFields[$field]['type']) ?>"
                name="<?= htmlspecialchars($field) ?>"
                value="<?= htmlspecialchars((string) $values[$field]) ?>"
                placeholder="<?= htmlspecialchars($integrationFields[$field]['placeholder']) ?>"
              >
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-grid">
          <?php foreach (['webhook_secret', 'telegram_group_id'] as $field): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($integrationFields[$field]['label']) ?></label>
              <input
                class="form-control"
                id="<?= htmlspecialchars($field) ?>"
                type="<?= htmlspecialchars($integrationFields[$field]['type']) ?>"
                name="<?= htmlspecialchars($field) ?>"
                value="<?= htmlspecialchars((string) $values[$field]) ?>"
                placeholder="<?= htmlspecialchars($integrationFields[$field]['placeholder']) ?>"
              >
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-grid">
          <?php foreach (['checkout_fixed_name', 'checkout_fixed_cpf'] as $field): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($integrationFields[$field]['label']) ?></label>
              <input
                class="form-control"
                id="<?= htmlspecialchars($field) ?>"
                type="<?= htmlspecialchars($integrationFields[$field]['type']) ?>"
                name="<?= htmlspecialchars($field) ?>"
                value="<?= htmlspecialchars((string) $values[$field]) ?>"
                placeholder="<?= htmlspecialchars($integrationFields[$field]['placeholder']) ?>"
              >
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-help">Preencha o CPF fixo com 11 numeros para o bot gerar o Pix direto, sem pedir CPF ao cliente. O nome fixo do pagador e opcional, mas recomendado.</div>

        <?php if (!runtime_checkout_uses_backend_payer()): ?>
          <div class="alert alert-warning">O CPF fixo do checkout ainda nao esta pronto. Preencha um CPF com 11 numeros para o Pix gerar sem pedir CPF ao cliente.</div>
        <?php endif; ?>

        <?php if (trim((string) $values['ecompag_client_id']) === '' || trim((string) $values['ecompag_client_secret']) === ''): ?>
          <div class="alert alert-warning">As credenciais da Ecompag ainda nao estao prontas. Preencha o client ID e o client secret antes de testar o Pix.</div>
        <?php endif; ?>

        <div class="form-group">
          <label class="form-label" for="telegram_bot_token">Token do bot</label>
          <input class="form-control" id="telegram_bot_token" type="text" name="telegram_bot_token" value="<?= htmlspecialchars((string) $values['telegram_bot_token']) ?>" placeholder="<?= htmlspecialchars($integrationFields['telegram_bot_token']['placeholder']) ?>">
        </div>

        <div class="form-grid">
          <?php foreach (['ecompag_client_id', 'ecompag_client_secret'] as $field): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($integrationFields[$field]['label']) ?></label>
              <input
                class="form-control"
                id="<?= htmlspecialchars($field) ?>"
                type="text"
                name="<?= htmlspecialchars($field) ?>"
                value="<?= htmlspecialchars((string) $values[$field]) ?>"
                placeholder="<?= htmlspecialchars($integrationFields[$field]['placeholder']) ?>"
              >
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-grid">
          <?php foreach (['n8n_webhook_url', 'n8n_secret', 'n8n_timeout'] as $field): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($integrationFields[$field]['label']) ?></label>
              <input
                class="form-control"
                id="<?= htmlspecialchars($field) ?>"
                type="<?= htmlspecialchars($integrationFields[$field]['type']) ?>"
                step="<?= htmlspecialchars((string) ($integrationFields[$field]['step'] ?? '')) ?>"
                name="<?= htmlspecialchars($field) ?>"
                value="<?= htmlspecialchars((string) $values[$field]) ?>"
                placeholder="<?= htmlspecialchars($integrationFields[$field]['placeholder']) ?>"
              >
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-help">Se quiser automacoes externas, configure um webhook do n8n. O app envia eventos com os headers <span class="mono">X-App-Event</span> e <span class="mono">X-App-Secret</span>.</div>

        <div class="form-grid">
          <?php foreach (['meta_pixel_id', 'meta_access_token'] as $field): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($integrationFields[$field]['label']) ?></label>
              <input
                class="form-control"
                id="<?= htmlspecialchars($field) ?>"
                type="text"
                name="<?= htmlspecialchars($field) ?>"
                value="<?= htmlspecialchars((string) $values[$field]) ?>"
                placeholder="<?= htmlspecialchars($integrationFields[$field]['placeholder']) ?>"
              >
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-grid">
          <?php foreach (['meta_test_event_code', 'meta_api_version', 'meta_action_source'] as $field): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($integrationFields[$field]['label']) ?></label>
              <input
                class="form-control"
                id="<?= htmlspecialchars($field) ?>"
                type="text"
                name="<?= htmlspecialchars($field) ?>"
                value="<?= htmlspecialchars((string) $values[$field]) ?>"
                placeholder="<?= htmlspecialchars($integrationFields[$field]['placeholder']) ?>"
              >
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-grid">
          <?php foreach (['default_product_name', 'default_product_description'] as $field): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($integrationFields[$field]['label']) ?></label>
              <input
                class="form-control"
                id="<?= htmlspecialchars($field) ?>"
                type="text"
                name="<?= htmlspecialchars($field) ?>"
                value="<?= htmlspecialchars((string) $values[$field]) ?>"
                placeholder="<?= htmlspecialchars($integrationFields[$field]['placeholder']) ?>"
              >
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-grid">
          <?php foreach (['default_product_price', 'default_product_days'] as $field): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($integrationFields[$field]['label']) ?></label>
              <input
                class="form-control"
                id="<?= htmlspecialchars($field) ?>"
                type="<?= htmlspecialchars($integrationFields[$field]['type']) ?>"
                step="<?= htmlspecialchars((string) ($integrationFields[$field]['step'] ?? '')) ?>"
                name="<?= htmlspecialchars($field) ?>"
                value="<?= htmlspecialchars((string) $values[$field]) ?>"
                placeholder="<?= htmlspecialchars($integrationFields[$field]['placeholder']) ?>"
              >
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-grid">
          <?php foreach (['smtp_host', 'smtp_port'] as $field): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($integrationFields[$field]['label']) ?></label>
              <input
                class="form-control"
                id="<?= htmlspecialchars($field) ?>"
                type="<?= htmlspecialchars($integrationFields[$field]['type']) ?>"
                step="<?= htmlspecialchars((string) ($integrationFields[$field]['step'] ?? '')) ?>"
                name="<?= htmlspecialchars($field) ?>"
                value="<?= htmlspecialchars((string) $values[$field]) ?>"
                placeholder="<?= htmlspecialchars($integrationFields[$field]['placeholder']) ?>"
              >
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-grid">
          <?php foreach (['smtp_user', 'smtp_password'] as $field): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($integrationFields[$field]['label']) ?></label>
              <input
                class="form-control"
                id="<?= htmlspecialchars($field) ?>"
                type="<?= htmlspecialchars($integrationFields[$field]['type']) ?>"
                name="<?= htmlspecialchars($field) ?>"
                value="<?= htmlspecialchars((string) $values[$field]) ?>"
                placeholder="<?= htmlspecialchars($integrationFields[$field]['placeholder']) ?>"
              >
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Salvar configuracoes</button>
        </div>
      </form>
    </div>
  </section>

  <section class="stack">
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">URLs uteis</h2>
          <p class="card-copy">Use estes enderecos para integrar bot, webhook e cron.</p>
        </div>
      </div>
      <div class="card-body stack-sm">
        <div>
          <div class="form-label">Webhook Telegram</div>
          <div class="code-box"><?= htmlspecialchars(runtime_telegram_webhook_url()) ?></div>
        </div>
        <div>
          <div class="form-label">Setup do webhook</div>
          <div class="code-box"><?= htmlspecialchars(runtime_base_url() . '/setup_webhook.php?token=' . runtime_webhook_secret()) ?></div>
        </div>
        <div>
          <div class="form-label">Webhook Pix</div>
          <div class="code-box"><?= htmlspecialchars(runtime_ecompag_notify_url()) ?></div>
        </div>
        <div>
          <div class="form-label">Cron por URL</div>
          <div class="code-box"><?= htmlspecialchars(runtime_base_url() . '/cron_expiracao.php?token=' . runtime_webhook_secret()) ?></div>
        </div>
      </div>
    </article>

    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Meta tracking</h2>
          <p class="card-copy">Como o checkout acontece no Telegram, o rastreamento roda no servidor usando Conversions API.</p>
        </div>
      </div>
      <div class="card-body stack-sm">
        <div class="code-box">InitiateCheckout e enviado quando o Pix e gerado.</div>
        <div class="code-box">Purchase e enviado quando o webhook confirma o pagamento.</div>
        <div class="code-box">Para fluxo de Telegram, o recomendado e usar meta_action_source = chat.</div>
        <div class="code-box">Use o campo "Meta test event code" para validar no Events Manager antes de subir para producao.</div>
      </div>
    </article>

    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">n8n e automacoes</h2>
          <p class="card-copy">Use um webhook POST no n8n para orquestrar CRM, planilhas, WhatsApp, e-mails e alertas internos.</p>
        </div>
      </div>
      <div class="card-body stack-sm">
        <div class="code-box">Eventos enviados: <span class="mono">lead_start</span>, <span class="mono">pix_gerado</span>, <span class="mono">pagamento_aprovado</span>, <span class="mono">pack_entregue</span>, <span class="mono">acesso_expirado</span>, <span class="mono">orderbump_ofertado</span>, <span class="mono">upsell_ofertado</span> e <span class="mono">downsell_ofertado</span>.</div>
        <div class="code-box">Payload base: <span class="mono">{ event, sent_at, source, site_url, base_url, payload }</span>.</div>
        <div class="code-box">Proteja o webhook validando o header <span class="mono">X-App-Secret</span> com o mesmo segredo salvo aqui.</div>
        <div class="code-box">Para webhooks por etapa, use as telas de <span class="mono">Order Bump</span>, <span class="mono">Upsell</span>, <span class="mono">Downsell</span> e <span class="mono">Remarketing</span>.</div>
      </div>
    </article>

  </section>
</div>

<?php include '_footer.php'; ?>
