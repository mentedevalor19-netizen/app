<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();
require_admin($current_admin);

if (!db_has_table('orderbumps') || !db_has_table('produtos')) {
    $page_title = 'Order Bump';
    $page_subtitle = 'Modulo indisponivel';
    $active_menu = 'orderbumps';
    include '_layout.php';
    ?>
    <div class="alert alert-warning">As tabelas necessarias para order bump e produtos ainda nao existem no banco atual. Importe o SQL atualizado antes de usar esta tela.</div>
    <?php
    include '_footer.php';
    return;
}

if (!db_has_column('orderbumps', 'media_tipo') || !db_has_column('orderbumps', 'media_url')) {
    $page_title = 'Order Bump';
    $page_subtitle = 'Modulo indisponivel';
    $active_menu = 'orderbumps';
    include '_layout.php';
    ?>
    <div class="alert alert-warning">A tabela <span class="mono">orderbumps</span> ainda nao tem as colunas de midia. Execute o SQL atualizado antes de usar este modulo.</div>
    <?php
    include '_footer.php';
    return;
}

$hasWebhookColumns = db_has_column('orderbumps', 'webhook_url') && db_has_column('orderbumps', 'webhook_secret');

$pdo = db();
$editingId = (int) ($_GET['editar'] ?? 0);
$msg = null;
$orderbumpScope = tenant_scope_condition('orderbumps');
$produtoScope = tenant_scope_condition('produtos');
$pagamentoScope = tenant_scope_condition('pagamentos');
$orderbumpListScope = tenant_scope_condition('orderbumps', 'o');
$produtoPrincipalJoinScope = tenant_scope_condition('produtos', 'pm');
$produtoOfertaJoinScope = tenant_scope_condition('produtos', 'pb');
$orderbumpSettingFields = [
    'orderbump_accept_button_text' => ['label' => 'Botao de aceitar', 'type' => 'text', 'default' => runtime_orderbump_accept_button_text()],
    'orderbump_skip_button_text' => ['label' => 'Botao de recusar', 'type' => 'text', 'default' => runtime_orderbump_skip_button_text()],
    'msg_orderbump_delivered' => ['label' => 'Mensagem de entrega do order bump', 'type' => 'textarea', 'rows' => 5],
    'msg_orderbump_missing_link' => ['label' => 'Mensagem quando faltar link do pack', 'type' => 'textarea', 'rows' => 4],
];
$orderbumpSettingValues = [
    'orderbump_accept_button_text' => app_setting('orderbump_accept_button_text', runtime_orderbump_accept_button_text()),
    'orderbump_skip_button_text' => app_setting('orderbump_skip_button_text', runtime_orderbump_skip_button_text()),
    'msg_orderbump_delivered' => message_template('msg_orderbump_delivered'),
    'msg_orderbump_missing_link' => message_template('msg_orderbump_missing_link'),
];

$form = [
    'id' => 0,
    'nome' => '',
    'produto_principal_id' => 0,
    'produto_id' => 0,
    'desconto_percentual' => '0.00',
    'mensagem' => '',
    'media_tipo' => 'none',
    'media_url' => '',
    'webhook_url' => '',
    'webhook_secret' => '',
    'ativo' => 1,
    'ordem' => 0,
];

if ($editingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM orderbumps WHERE id = ? AND ' . $orderbumpScope . ' LIMIT 1');
    $stmt->execute([$editingId]);
    $editing = $stmt->fetch();

    if ($editing) {
        $form = [
            'id' => (int) $editing['id'],
            'nome' => (string) ($editing['nome'] ?? ''),
            'produto_principal_id' => (int) ($editing['produto_principal_id'] ?? 0),
            'produto_id' => (int) ($editing['produto_id'] ?? 0),
            'desconto_percentual' => number_format((float) ($editing['desconto_percentual'] ?? 0), 2, '.', ''),
            'mensagem' => (string) ($editing['mensagem'] ?? ''),
            'media_tipo' => normalizar_media_tipo((string) ($editing['media_tipo'] ?? 'none')),
            'media_url' => (string) ($editing['media_url'] ?? ''),
            'webhook_url' => (string) ($editing['webhook_url'] ?? ''),
            'webhook_secret' => (string) ($editing['webhook_secret'] ?? ''),
            'ativo' => (int) ($editing['ativo'] ?? 1),
            'ordem' => (int) ($editing['ordem'] ?? 0),
        ];
    } else {
        $msg = ['tipo' => 'warning', 'texto' => 'O order bump selecionado nao foi encontrado.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'salvar_textos_orderbump') {
        foreach ($orderbumpSettingFields as $field => $meta) {
            app_setting_save($field, trim((string) ($_POST[$field] ?? '')));
        }

        header('Location: ' . admin_url('orderbumps.php?ok=textos'));
        exit;
    }

    if ($acao === 'salvar') {
        $form = [
            'id' => (int) ($_POST['id'] ?? 0),
            'nome' => trim((string) ($_POST['nome'] ?? '')),
            'produto_principal_id' => (int) ($_POST['produto_principal_id'] ?? 0),
            'produto_id' => (int) ($_POST['produto_id'] ?? 0),
            'desconto_percentual' => str_replace(',', '.', trim((string) ($_POST['desconto_percentual'] ?? '0'))),
            'mensagem' => trim((string) ($_POST['mensagem'] ?? '')),
            'media_tipo' => normalizar_media_tipo((string) ($_POST['media_tipo'] ?? 'none')),
            'media_url' => trim((string) ($_POST['media_url'] ?? '')),
            'webhook_url' => trim((string) ($_POST['webhook_url'] ?? '')),
            'webhook_secret' => trim((string) ($_POST['webhook_secret'] ?? '')),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'ordem' => max(0, (int) ($_POST['ordem'] ?? 0)),
        ];

        $form['desconto_percentual'] = (float) $form['desconto_percentual'];
        $form['desconto_percentual'] = max(0, min(100, (float) $form['desconto_percentual']));
        $form['desconto_percentual'] = number_format((float) $form['desconto_percentual'], 2, '.', '');

        if ($form['nome'] === '') {
            $msg = ['tipo' => 'danger', 'texto' => 'Informe um nome para o order bump.'];
        } elseif ($form['produto_principal_id'] <= 0) {
            $msg = ['tipo' => 'danger', 'texto' => 'Selecione o produto principal.'];
        } elseif ($form['produto_id'] <= 0) {
            $msg = ['tipo' => 'danger', 'texto' => 'Selecione o produto ofertado no order bump.'];
        } elseif ($form['produto_id'] === $form['produto_principal_id']) {
            $msg = ['tipo' => 'danger', 'texto' => 'O produto principal e o produto do order bump precisam ser diferentes.'];
        } elseif (!get_produto_por_id($form['produto_principal_id']) || !get_produto_por_id($form['produto_id'])) {
            $msg = ['tipo' => 'danger', 'texto' => 'Os produtos escolhidos precisam pertencer ao workspace atual.'];
        } elseif ($form['media_tipo'] !== 'none' && $form['media_url'] === '') {
            $msg = ['tipo' => 'danger', 'texto' => 'Se escolher uma midia para o order bump, informe a URL publica dela.'];
        } elseif ($form['media_tipo'] !== 'none' && filter_var($form['media_url'], FILTER_VALIDATE_URL) === false) {
            $msg = ['tipo' => 'danger', 'texto' => 'A URL da midia do order bump precisa ser valida e publica.'];
        } elseif ($form['webhook_url'] !== '' && filter_var($form['webhook_url'], FILTER_VALIDATE_URL) === false) {
            $msg = ['tipo' => 'danger', 'texto' => 'A URL do webhook do order bump precisa ser valida e publica.'];
        } else {
            $mediaUrl = $form['media_tipo'] !== 'none' ? $form['media_url'] : null;
            $mensagem = $form['mensagem'] !== '' ? $form['mensagem'] : null;
            $produtoId = $form['produto_id'] > 0 ? $form['produto_id'] : null;
            $webhookUrl = $form['webhook_url'] !== '' ? $form['webhook_url'] : null;
            $webhookSecret = $form['webhook_secret'] !== '' ? $form['webhook_secret'] : null;

            if ($form['id'] > 0) {
                $sql = 'UPDATE orderbumps
                        SET nome = ?, produto_principal_id = ?, produto_id = ?, desconto_percentual = ?, mensagem = ?, media_tipo = ?, media_url = ?';
                $params = [
                    $form['nome'],
                    $form['produto_principal_id'],
                    $produtoId,
                    (float) $form['desconto_percentual'],
                    $mensagem,
                    $form['media_tipo'],
                    $mediaUrl,
                ];

                if ($hasWebhookColumns) {
                    $sql .= ', webhook_url = ?, webhook_secret = ?';
                    $params[] = $webhookUrl;
                    $params[] = $webhookSecret;
                }

                $sql .= ', ativo = ?, ordem = ? WHERE id = ? AND ' . $orderbumpScope;
                $params[] = $form['ativo'];
                $params[] = $form['ordem'];
                $params[] = $form['id'];

                $pdo->prepare($sql)->execute($params);
            } else {
                $columns = [
                    'nome',
                    'produto_principal_id',
                    'produto_id',
                    'desconto_percentual',
                    'mensagem',
                    'media_tipo',
                    'media_url',
                ];
                $params = [
                    $form['nome'],
                    $form['produto_principal_id'],
                    $produtoId,
                    (float) $form['desconto_percentual'],
                    $mensagem,
                    $form['media_tipo'],
                    $mediaUrl,
                ];

                if ($hasWebhookColumns) {
                    $columns[] = 'webhook_url';
                    $columns[] = 'webhook_secret';
                    $params[] = $webhookUrl;
                    $params[] = $webhookSecret;
                }

                $columns[] = 'ativo';
                $columns[] = 'ordem';
                $params[] = $form['ativo'];
                $params[] = $form['ordem'];

                $placeholderList = array_fill(0, count($columns), '?');
                tenant_insert_append('orderbumps', $columns, $placeholderList, $params);
                $pdo->prepare(
                    'INSERT INTO orderbumps (' . implode(', ', $columns) . ')
                     VALUES (' . implode(', ', $placeholderList) . ')'
                )->execute($params);
            }

            header('Location: ' . admin_url('orderbumps.php?ok=salvo'));
            exit;
        }
    }

    if ($acao === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $ativo = (int) ($_POST['ativo'] ?? 0);

        if ($id > 0) {
            $pdo->prepare('UPDATE orderbumps SET ativo = ? WHERE id = ? AND ' . $orderbumpScope)->execute([$ativo ? 0 : 1, $id]);
            header('Location: ' . admin_url('orderbumps.php?ok=salvo'));
            exit;
        }
    }

    if ($acao === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        $emUso = 0;

        if (db_has_table('pagamentos') && db_has_column('pagamentos', 'orderbump_id')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM pagamentos WHERE orderbump_id = ? AND ' . $pagamentoScope);
            $stmt->execute([$id]);
            $emUso += (int) $stmt->fetchColumn();
        }

        if ($emUso > 0) {
            $msg = ['tipo' => 'warning', 'texto' => 'Esse order bump possui pagamentos vinculados. Desative em vez de excluir.'];
        } else {
            $pdo->prepare('DELETE FROM orderbumps WHERE id = ? AND ' . $orderbumpScope)->execute([$id]);
            header('Location: ' . admin_url('orderbumps.php?ok=excluido'));
            exit;
        }
    }
}

$produtos = $pdo->query('SELECT * FROM produtos WHERE ' . $produtoScope . ' ORDER BY ' . db_order_by_clause('produtos'))->fetchAll();
$hasTipoProduto = db_has_column('produtos', 'tipo');
$hasPackLink = db_has_column('produtos', 'pack_link');
$orderbumps = $pdo->query(
    'SELECT o.*, 
            pm.nome AS produto_principal_nome, pm.valor AS produto_principal_valor' .
            ($hasTipoProduto ? ', pm.tipo AS produto_principal_tipo' : ", 'grupo' AS produto_principal_tipo") . ',
            pb.nome AS produto_nome, pb.valor AS produto_valor' .
            ($hasTipoProduto ? ', pb.tipo AS produto_tipo' : ", 'grupo' AS produto_tipo") . ',
            ' . ($hasPackLink ? 'pb.pack_link' : 'NULL') . ' AS produto_pack_link
     FROM orderbumps o
     LEFT JOIN produtos pm ON pm.id = o.produto_principal_id AND ' . $produtoPrincipalJoinScope . '
     LEFT JOIN produtos pb ON pb.id = o.produto_id AND ' . $produtoOfertaJoinScope . '
     WHERE ' . $orderbumpListScope . '
     ORDER BY ' . db_order_by_clause('orderbumps', 'o')
)->fetchAll();

$page_title = 'Order Bump';
$page_subtitle = 'Oferta extra antes do Pix, com desconto, midia opcional e webhook por oferta';
$active_menu = 'orderbumps';
include '_layout.php';
?>

<?php if ($msg): ?>
  <div class="alert alert-<?= htmlspecialchars($msg['tipo']) ?>"><?= htmlspecialchars($msg['texto']) ?></div>
<?php endif; ?>

<?php if (!$produtos): ?>
  <div class="alert alert-warning">Nenhum produto ativo foi encontrado. Cadastre planos ou packs em <b>Produtos / Planos</b> antes de criar um order bump.</div>
<?php endif; ?>

<?php if (!$hasWebhookColumns): ?>
  <div class="alert alert-warning">A tabela <span class="mono">orderbumps</span> ainda nao tem as colunas de webhook. Importe o SQL novo para liberar disparos externos por order bump.</div>
<?php endif; ?>

<div class="content-grid content-grid--sidebar">
  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title"><?= $form['id'] > 0 ? 'Editar order bump' : 'Novo order bump' ?></h2>
        <p class="card-copy">Escolha o plano principal, o produto do order bump, o desconto e a midia da oferta. Se o cliente aceitar, o bot soma tudo antes de gerar o Pix. A mensagem configurada aqui e a mensagem real que o lead recebe.</p>
      </div>
      <?php if ($form['id'] > 0): ?>
        <a href="<?= admin_url('orderbumps.php') ?>" class="btn btn-ghost btn-sm">Cancelar edicao</a>
      <?php endif; ?>
    </div>

    <div class="card-body">
      <form method="POST" class="stack">
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="nome">Nome do order bump</label>
            <input class="form-control" id="nome" type="text" name="nome" value="<?= htmlspecialchars((string) $form['nome']) ?>" placeholder="Ex: Oferta de upgrade rapido" required>
          </div>

          <div class="form-group">
            <label class="form-label" for="ordem">Ordem de exibicao</label>
            <input class="form-control" id="ordem" type="number" min="0" name="ordem" value="<?= (int) $form['ordem'] ?>">
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="produto_principal_id">Produto principal</label>
            <select class="form-control" id="produto_principal_id" name="produto_principal_id" required>
              <option value="0">Selecione o produto principal</option>
              <?php foreach ($produtos as $produto): ?>
                <option value="<?= (int) $produto['id'] ?>" <?= (int) $form['produto_principal_id'] === (int) $produto['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars(produto_rotulo_bot($produto)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label" for="produto_id">Produto do order bump</label>
            <select class="form-control" id="produto_id" name="produto_id" required>
              <option value="0">Selecione o produto ofertado</option>
              <?php foreach ($produtos as $produto): ?>
                <option value="<?= (int) $produto['id'] ?>" <?= (int) $form['produto_id'] === (int) $produto['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars(produto_rotulo_bot($produto)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="desconto_percentual">Desconto do order bump (%)</label>
            <input class="form-control" id="desconto_percentual" type="number" step="0.01" min="0" max="100" name="desconto_percentual" value="<?= htmlspecialchars((string) $form['desconto_percentual']) ?>" placeholder="10.00">
            <span class="form-help">O desconto e aplicado em cima do produto ofertado no order bump.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="media_tipo">Midia da oferta</label>
            <select class="form-control" id="media_tipo" name="media_tipo">
              <option value="none" <?= (string) $form['media_tipo'] === 'none' ? 'selected' : '' ?>>Sem midia</option>
              <option value="photo" <?= (string) $form['media_tipo'] === 'photo' ? 'selected' : '' ?>>Imagem</option>
              <option value="video" <?= (string) $form['media_tipo'] === 'video' ? 'selected' : '' ?>>Video</option>
              <option value="audio" <?= (string) $form['media_tipo'] === 'audio' ? 'selected' : '' ?>>Audio</option>
              <option value="document" <?= (string) $form['media_tipo'] === 'document' ? 'selected' : '' ?>>Arquivo</option>
            </select>
            <span class="form-help">A midia aparece no Telegram com a mensagem do order bump como legenda.</span>
          </div>
        </div>

        <div class="form-group" id="media_url_group">
          <label class="form-label" for="media_url">URL da midia da oferta</label>
          <input class="form-control" id="media_url" type="url" name="media_url" value="<?= htmlspecialchars((string) $form['media_url']) ?>" placeholder="https://...">
          <span class="form-help">Use uma URL publica e direta da imagem, video, audio ou arquivo.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="mensagem">Mensagem do order bump</label>
          <textarea class="form-control" id="mensagem" name="mensagem" rows="5" placeholder="Ex: Adicione tambem {produto} por {valor}."><?= htmlspecialchars((string) $form['mensagem']) ?></textarea>
          <span class="form-help">Use {produto}, {valor}, {valor_original}, {desconto} e {produto_principal}. Nao existe mais um segundo campo dessa mensagem em Fluxo/start.</span>
        </div>

        <?php if ($hasWebhookColumns): ?>
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label" for="webhook_url">Webhook do order bump</label>
              <input class="form-control" id="webhook_url" type="url" name="webhook_url" value="<?= htmlspecialchars((string) $form['webhook_url']) ?>" placeholder="https://n8n.seudominio.com/webhook/orderbump">
              <span class="form-help">Se preencher, o sistema dispara este webhook assim que a oferta for enviada ao lead.</span>
            </div>

            <div class="form-group">
              <label class="form-label" for="webhook_secret">Secret do webhook</label>
              <input class="form-control" id="webhook_secret" type="text" name="webhook_secret" value="<?= htmlspecialchars((string) $form['webhook_secret']) ?>" placeholder="segredo-opcional">
              <span class="form-help">Vai no header <span class="mono">X-App-Secret</span>.</span>
            </div>
          </div>
        <?php endif; ?>

        <div class="form-group">
          <label class="form-label">Status</label>
          <label class="checkbox-row">
            <input type="checkbox" name="ativo" <?= (int) $form['ativo'] === 1 ? 'checked' : '' ?>>
            <span>Order bump ativo</span>
          </label>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><?= $form['id'] > 0 ? 'Salvar alteracoes' : 'Criar order bump' ?></button>
          <?php if ($form['id'] > 0): ?>
            <a href="<?= admin_url('orderbumps.php') ?>" class="btn btn-ghost">Limpar formulario</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </section>

  <section class="stack">
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Textos e botoes do modulo</h2>
          <p class="card-copy">Os textos abaixo valem para todos os order bumps deste workspace. A mensagem principal da oferta continua dentro de cada order bump cadastrado.</p>
        </div>
      </div>
      <div class="card-body">
        <form method="POST" class="stack">
          <input type="hidden" name="acao" value="salvar_textos_orderbump">

          <?php foreach ($orderbumpSettingFields as $field => $meta): ?>
            <div class="form-group">
              <label class="form-label" for="<?= htmlspecialchars($field) ?>"><?= htmlspecialchars($meta['label']) ?></label>
              <?php if (($meta['type'] ?? 'textarea') === 'text'): ?>
                <input class="form-control" id="<?= htmlspecialchars($field) ?>" type="text" name="<?= htmlspecialchars($field) ?>" value="<?= htmlspecialchars((string) $orderbumpSettingValues[$field]) ?>">
              <?php else: ?>
                <textarea class="form-control" id="<?= htmlspecialchars($field) ?>" name="<?= htmlspecialchars($field) ?>" rows="<?= (int) ($meta['rows'] ?? 4) ?>"><?= htmlspecialchars((string) $orderbumpSettingValues[$field]) ?></textarea>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Salvar textos do order bump</button>
          </div>
        </form>
      </div>
    </article>

    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Order bumps cadastrados</h2>
          <p class="card-copy">Use editar para carregar a oferta no formulario ao lado. Aqui tambem aparece a midia e o desconto aplicado.</p>
        </div>
      </div>
      <div class="card-body" style="padding-top: 0;">
        <div class="table-wrap">
          <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Oferta</th>
              <th>Principal</th>
              <th>Produto</th>
              <th>Desconto</th>
              <th>Midia</th>
              <th>Webhook</th>
              <th>Ordem</th>
              <th>Status</th>
              <th>Acoes</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$orderbumps): ?>
              <tr>
                <td colspan="10" class="empty-state">Nenhum order bump cadastrado ainda.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($orderbumps as $orderbump): ?>
              <?php
                $principal = trim((string) ($orderbump['produto_principal_nome'] ?? ''));
                $produto = trim((string) ($orderbump['produto_nome'] ?? ''));
                $mediaTipo = normalizar_media_tipo((string) ($orderbump['media_tipo'] ?? 'none'));
                $mediaTipoLabel = [
                    'none' => 'Sem midia',
                    'photo' => 'Imagem',
                    'video' => 'Video',
                    'audio' => 'Audio',
                    'document' => 'Arquivo',
                ][$mediaTipo] ?? 'Sem midia';
                $mediaPreview = trim((string) ($orderbump['media_url'] ?? ''));
                if ($mediaPreview !== '' && strlen($mediaPreview) > 42) {
                    $mediaPreview = substr($mediaPreview, 0, 39) . '...';
                }
                $webhookPreview = trim((string) ($orderbump['webhook_url'] ?? ''));
                if ($webhookPreview !== '' && strlen($webhookPreview) > 42) {
                    $webhookPreview = substr($webhookPreview, 0, 39) . '...';
                }
              ?>
              <tr>
                <td class="mono"><?= (int) $orderbump['id'] ?></td>
                <td><strong><?= htmlspecialchars((string) $orderbump['nome']) ?></strong></td>
                <td>
                  <?= $principal !== '' ? htmlspecialchars($principal) : 'Sem produto' ?>
                  <?php if (!empty($orderbump['produto_principal_valor'])): ?>
                    <div class="text-muted mono"><?= formatar_valor((float) $orderbump['produto_principal_valor']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?= $produto !== '' ? htmlspecialchars($produto) : 'Sem produto' ?>
                  <?php if (!empty($orderbump['produto_valor'])): ?>
                    <div class="text-muted mono"><?= formatar_valor((float) $orderbump['produto_valor']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="mono"><?= number_format((float) ($orderbump['desconto_percentual'] ?? 0), 2, ',', '.') ?>%</td>
                <td>
                  <?= htmlspecialchars($mediaTipoLabel) ?>
                  <?php if ($mediaPreview !== ''): ?>
                    <div class="text-muted mono"><?= htmlspecialchars($mediaPreview) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($webhookPreview !== ''): ?>
                    Sim
                    <div class="text-muted mono"><?= htmlspecialchars($webhookPreview) ?></div>
                  <?php else: ?>
                    <span class="text-muted">Nao</span>
                  <?php endif; ?>
                </td>
                <td class="mono"><?= (int) ($orderbump['ordem'] ?? 0) ?></td>
                <td>
                  <form method="POST" class="inline-form">
                    <input type="hidden" name="acao" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $orderbump['id'] ?>">
                    <input type="hidden" name="ativo" value="<?= (int) $orderbump['ativo'] ?>">
                    <button type="submit" class="btn btn-sm <?= (int) $orderbump['ativo'] === 1 ? 'btn-secondary' : 'btn-ghost' ?>">
                      <?= (int) $orderbump['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                    </button>
                  </form>
                </td>
                <td>
                  <div class="actions">
                    <a href="<?= admin_url('orderbumps.php?editar=' . (int) $orderbump['id']) ?>" class="btn btn-ghost btn-sm">Editar</a>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Excluir este order bump?');">
                      <input type="hidden" name="acao" value="excluir">
                      <input type="hidden" name="id" value="<?= (int) $orderbump['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          </table>
        </div>
      </div>
    </article>
  </section>
</div>

<script>
(() => {
  const tipoSelect = document.getElementById('media_tipo');
  const mediaGroup = document.getElementById('media_url_group');
  const mediaInput = document.getElementById('media_url');

  if (!tipoSelect || !mediaGroup || !mediaInput) {
    return;
  }

  const sync = () => {
    const isNone = tipoSelect.value === 'none';
    mediaGroup.style.display = isNone ? 'none' : 'block';
    mediaInput.required = !isNone;
  };

  tipoSelect.addEventListener('change', sync);
  sync();
})();
</script>

<?php include '_footer.php'; ?>
