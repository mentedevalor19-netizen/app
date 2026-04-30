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
        'label' => 'Mensagem depois do audio',
        'help' => 'Texto opcional enviado logo depois do audio da segunda etapa do /start.',
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
    'start_button_planos_text' => [
        'label' => 'Texto do botao de planos',
        'help' => 'Texto do botao principal exibido no /start.',
        'type' => 'text',
    ],
    'start_button_packs_text' => [
        'label' => 'Texto do botao de packs',
        'help' => 'So aparece quando houver packs ativos.',
        'type' => 'text',
    ],
    'packs_back_button_text' => [
        'label' => 'Texto do botao de voltar nos packs',
        'help' => 'Botao exibido dentro do submenu de packs.',
        'type' => 'text',
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

    if (in_array($field, ['start_button_planos_text', 'start_button_packs_text', 'packs_back_button_text'], true)) {
        $defaults = [
            'start_button_planos_text' => runtime_start_plan_button_text(),
            'start_button_packs_text' => runtime_start_pack_button_text(),
            'packs_back_button_text' => runtime_pack_back_button_text(),
        ];
        $values[$field] = app_setting($field, $defaults[$field]);
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

<section class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Fluxo do /start</h2>
      <p class="card-copy">Aqui ficam apenas as etapas do inicio do bot: midia inicial, audio separado, mensagem final com botoes, menu de planos/packs e mensagens do Pix.</p>
    </div>
    <div class="actions">
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
    <div class="alert alert-info" style="margin-bottom: 20px;">As configuracoes de <b>Upsell</b>, <b>Downsell</b>, <b>Order Bump</b> e <b>Remarketing</b> agora ficam apenas nas paginas proprias de cada modulo.</div>

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

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salvar fluxo</button>
      </div>
    </form>
  </div>
</section>

<?php include '_footer.php'; ?>
