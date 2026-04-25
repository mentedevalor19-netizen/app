<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();
require_admin($current_admin);

if (!db_has_table('configuracoes')) {
    $page_title = 'Fluxo inicial';
    $page_subtitle = 'Modulo indisponivel';
    $active_menu = 'fluxos';
    include '_layout.php';
    ?>
    <div class="alert alert-warning">A tabela <span class="mono">configuracoes</span> ainda nao existe no banco atual. Execute o SQL de atualizacao antes de usar esta tela.</div>
    <?php
    include '_footer.php';
    return;
}

$fields = [
    'msg_start_intro' => [
        'label' => 'Mensagem inicial do /start',
        'help' => 'Essa e a primeira etapa do /start. Se voce preencher uma imagem ou video abaixo, este texto vira a legenda da midia.',
        'rows' => 6,
    ],
    'msg_start_media_tipo' => [
        'label' => 'Midia da primeira etapa',
        'help' => 'Escolha a imagem ou video que abre o /start. Se deixar em sem midia, o lead recebe apenas o texto.',
        'type' => 'select',
        'options' => [
            'none' => 'Sem midia',
            'photo' => 'Imagem',
            'video' => 'Video',
        ],
    ],
    'msg_start_media_url' => [
        'label' => 'URL da midia inicial',
        'help' => 'Link publico direto da imagem ou video. O texto da primeira etapa vira a legenda.',
        'type' => 'text',
    ],
    'msg_start_audio_caption' => [
        'label' => 'Legenda do audio separado',
        'help' => 'Texto opcional enviado junto com o audio da segunda etapa do /start.',
        'rows' => 3,
    ],
    'msg_start_audio_url' => [
        'label' => 'URL do audio separado',
        'help' => 'Link publico direto do audio enviado depois da imagem/video e antes da mensagem final.',
        'type' => 'text',
    ],
    'msg_start_cta' => [
        'label' => 'Mensagem final com botoes',
        'help' => 'Terceira etapa do /start. Essa mensagem sai com os botoes de Planos e, se houver packs ativos, Packs.',
        'rows' => 5,
    ],
    'msg_choose_plan' => [
        'label' => 'Mensagem ao abrir os planos',
        'help' => 'Esse texto aparece quando o cliente toca em /planos e antes de escolher o produto.',
        'rows' => 5,
    ],
    'msg_choose_pack' => [
        'label' => 'Mensagem do menu de packs',
        'help' => 'Esse texto aparece quando o cliente toca no botao Ver packs e abre o submenu de packs.',
        'rows' => 5,
    ],
    'msg_pix_generating' => [
        'label' => 'Mensagem gerando o Pix',
        'help' => 'Mensagem curta exibida enquanto o checkout e preparado.',
        'rows' => 4,
    ],
    'msg_pix_generated' => [
        'label' => 'Mensagem com o Pix gerado',
        'help' => 'Aqui entram os placeholders {produto}, {valor}, {txid} e {pix}.',
        'rows' => 8,
    ],
    'msg_pix_error' => [
        'label' => 'Mensagem de erro do Pix',
    'help' => 'Usada quando o checkout nao consegue gerar o Pix por falta de configuracao ou erro na PestoPay.',
        'rows' => 5,
    ],
    'msg_upsell_offer' => [
        'label' => 'Mensagem base de upsell',
        'help' => 'Mensagem que embrulha a oferta de upsell enviada pelo funil. Pode usar {mensagem}.',
        'rows' => 5,
    ],
    'msg_orderbump_delivered' => [
        'label' => 'Mensagem de entrega do order bump',
        'help' => 'Mensagem enviada depois da compra do order bump. Pode usar {conteudo}.',
        'rows' => 5,
    ],
    'msg_orderbump_missing_link' => [
        'label' => 'Order bump sem link configurado',
        'help' => 'Fallback quando a oferta extra for um pack, mas o link ainda nao foi cadastrado.',
        'rows' => 4,
    ],
];

$values = [];
foreach (array_keys($fields) as $field) {
    if ($field === 'msg_start_media_tipo') {
        $values[$field] = app_setting($field, 'none');
        continue;
    }

    if ($field === 'msg_start_media_url' || $field === 'msg_start_audio_url') {
        $values[$field] = app_setting($field, '');
        continue;
    }

    $values[$field] = message_template($field);
}

$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'salvar_fluxo') {
        foreach (array_keys($fields) as $field) {
            $value = trim((string) ($_POST[$field] ?? ''));
            if ($field === 'msg_start_media_tipo') {
                $value = normalizar_media_tipo($value);
            }

            app_setting_save($field, $value);
        }

        header('Location: ' . admin_url('fluxos.php?ok=salvo'));
        exit;
    }

    if ($acao === 'sincronizar_comandos') {
        $resultado = telegram_sync_commands();
        $msg = [
            'tipo' => !empty($resultado['ok']) ? 'success' : 'warning',
            'texto' => !empty($resultado['ok'])
                ? 'Comandos padrao sincronizados com o Telegram.'
                : 'Nao foi possivel sincronizar os comandos agora.',
        ];
    }

    if ($acao === 'limpar_fluxos_antigos') {
        $tabelas = ['mailing_envios', 'mailings', 'downsell_disparos', 'downsells', 'fluxo_execucoes', 'fluxo_etapas', 'fluxos', 'funis', 'mailing'];
        foreach ($tabelas as $tabela) {
            if (db_has_table($tabela)) {
                try {
                    $sql = 'DELETE FROM ' . db_quote_identifier($tabela);
                    if (tenant_table_supports_scope($tabela)) {
                        $sql .= ' WHERE ' . tenant_scope_condition($tabela);
                    }
                    db()->exec($sql);
                } catch (Throwable $e) {
                    log_evento('cleanup_fluxos_fail', 'Falha ao limpar tabela antiga.', [
                        'tabela' => $tabela,
                        'erro' => $e->getMessage(),
                    ]);
                }
            }
        }

        app_setting_save('system_start_flow_id', '0');

        header('Location: ' . admin_url('fluxos.php?ok=limpo'));
        exit;
    }
}

$page_title = 'Fluxo inicial';
$page_subtitle = 'Mensagem de boas-vindas -> planos -> Pix sem pedir CPF';
$active_menu = 'fluxos';
include '_layout.php';
?>

<?php if ($msg): ?>
  <div class="alert alert-<?= htmlspecialchars($msg['tipo']) ?>"><?= htmlspecialchars($msg['texto']) ?></div>
<?php endif; ?>

<div class="content-grid" style="grid-template-columns: minmax(0, 1.2fr) minmax(0, 0.8fr);">
  <section class="card">
      <div class="card-header">
      <div>
        <p class="card-kicker">Fluxo unico</p>
        <h2 class="card-title">Roteiro do bot</h2>
        <p class="card-copy">Aqui fica o caminho principal: mensagem inicial com botao de planos, escolha do plano, Pix direto e os textos de entrega. O upsell visual fica em <b>Funis / Upsell</b> e a mensagem real do order bump fica em <b>Order Bump</b>.</p>
      </div>
      <div class="actions">
        <a href="<?= admin_url('funis.php') ?>" class="btn btn-ghost btn-sm">Abrir upsell</a>
        <a href="<?= admin_url('downsells.php') ?>" class="btn btn-ghost btn-sm">Abrir downsell</a>
        <a href="<?= admin_url('orderbumps.php') ?>" class="btn btn-ghost btn-sm">Abrir order bump</a>
        <a href="<?= admin_url('remarketing.php') ?>" class="btn btn-ghost btn-sm">Abrir remarketing</a>
        <form method="POST" class="inline-form">
          <input type="hidden" name="acao" value="sincronizar_comandos">
          <button type="submit" class="btn btn-ghost btn-sm">Sincronizar comandos</button>
        </form>
        <form method="POST" class="inline-form" onsubmit="return confirm('Apagar os fluxos antigos do banco? Esta acao remove automacoes legadas e filas antigas.');">
          <input type="hidden" name="acao" value="limpar_fluxos_antigos">
          <button type="submit" class="btn btn-danger btn-sm">Apagar fluxos antigos</button>
        </form>
      </div>
    </div>
    <div class="card-body">
      <div class="journey-map">
        <article class="journey-step">
          <div class="journey-step-index">01</div>
          <div class="journey-step-body">
            <h3 class="journey-step-title">Imagem ou video</h3>
            <p class="journey-step-copy">O lead recebe a primeira midia do <span class="mono">/start</span> com a legenda configurada no painel.</p>
          </div>
        </article>

        <article class="journey-step">
          <div class="journey-step-index">02</div>
          <div class="journey-step-body">
            <h3 class="journey-step-title">Audio separado</h3>
            <p class="journey-step-copy">Depois da primeira midia, o bot pode enviar um audio separado para aquecer o lead. Links .ogg e links com redirecionamento agora tem fallback automatico.</p>
          </div>
        </article>

        <article class="journey-step">
          <div class="journey-step-index">03</div>
          <div class="journey-step-body">
            <h3 class="journey-step-title">Mensagem com botoes</h3>
            <p class="journey-step-copy">Na terceira etapa o lead recebe os botoes para abrir os planos e, se houver packs ativos, os packs.</p>
          </div>
        </article>

        <article class="journey-step">
          <div class="journey-step-index">04</div>
          <div class="journey-step-body">
            <h3 class="journey-step-title">Pix sem CPF</h3>
            <p class="journey-step-copy">Depois da escolha, o checkout usa o CPF interno do backend e gera o Pix sem pedir CPF ao cliente.</p>
          </div>
        </article>
      </div>

      <div class="alert alert-info" style="margin-top: 20px;">Se precisar trocar o CPF interno do checkout, use a area de <b>Configuracoes</b>. Este painel de fluxo ficou so para o roteiro principal. A mensagem do order bump agora e configurada apenas na propria tela de <b>Order Bump</b>.</div>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <p class="card-kicker">Editor visual</p>
        <h2 class="card-title">Mensagens editaveis</h2>
        <p class="card-copy">Os textos abaixo alimentam exatamente o bot. O /start agora pode seguir a ordem: midia, audio e mensagem final com botoes.</p>
      </div>
    </div>
    <div class="card-body">
      <form method="POST" class="stack">
        <input type="hidden" name="acao" value="salvar_fluxo">

        <?php foreach ($fields as $field => $meta): ?>
          <div class="form-group">
            <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($meta['label']) ?></label>
            <?php if (($meta['type'] ?? 'textarea') === 'select'): ?>
              <select class="form-control" id="<?= htmlspecialchars($field) ?>" name="<?= htmlspecialchars($field) ?>">
                <?php foreach (($meta['options'] ?? []) as $optionValue => $optionLabel): ?>
                  <option value="<?= htmlspecialchars((string) $optionValue) ?>" <?= (string) $values[$field] === (string) $optionValue ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) $optionLabel) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php elseif (($meta['type'] ?? 'textarea') === 'text'): ?>
              <input class="form-control" type="text" id="<?= htmlspecialchars($field) ?>" name="<?= htmlspecialchars($field) ?>" value="<?= htmlspecialchars((string) $values[$field]) ?>">
            <?php else: ?>
              <textarea class="form-control" id="<?= htmlspecialchars($field) ?>" name="<?= htmlspecialchars($field) ?>" rows="<?= (int) $meta['rows'] ?>"><?= htmlspecialchars((string) $values[$field]) ?></textarea>
            <?php endif; ?>
            <span class="form-help"><?= htmlspecialchars($meta['help']) ?></span>
          </div>
        <?php endforeach; ?>

        <div class="form-group">
          <label class="form-label">Botoes do /start</label>
          <input class="form-control" type="text" value="Ver planos + Ver packs (quando houver packs ativos)" disabled>
          <span class="form-help">Os botoes finais ficam padronizados para manter o fluxo previsivel e facilitar a automacao externa.</span>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Salvar fluxo</button>
        </div>
      </form>
    </div>
  </section>
</div>

<?php include '_footer.php'; ?>
