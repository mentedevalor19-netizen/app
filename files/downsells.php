<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();
require_admin($current_admin);

if (!db_has_table('downsells') || !db_has_table('funis') || !db_has_table('produtos')) {
    $page_title = 'Downsell';
    $page_subtitle = 'Modulo indisponivel';
    $active_menu = 'downsells';
    include '_layout.php';
    ?>
    <div class="alert alert-warning">As tabelas necessarias para downsell, funis e produtos ainda nao existem no banco atual. Importe o SQL atualizado antes de usar esta tela.</div>
    <?php
    include '_footer.php';
    return;
}

if (!db_has_column('downsells', 'media_tipo') || !db_has_column('downsells', 'media_url')) {
    $page_title = 'Downsell';
    $page_subtitle = 'Modulo indisponivel';
    $active_menu = 'downsells';
    include '_layout.php';
    ?>
    <div class="alert alert-warning">A tabela <span class="mono">downsells</span> ainda nao tem as colunas de midia. Execute o SQL atualizado antes de usar este modulo.</div>
    <?php
    include '_footer.php';
    return;
}

$hasWebhookColumns = db_has_column('downsells', 'webhook_url') && db_has_column('downsells', 'webhook_secret');

$pdo = db();
$editingId = (int) ($_GET['editar'] ?? 0);
$msg = null;
$downsellScope = tenant_scope_condition('downsells');
$funilScope = tenant_scope_condition('funis');
$produtoScope = tenant_scope_condition('produtos');
$disparoScope = tenant_scope_condition('downsell_disparos');

$form = [
    'id' => 0,
    'nome' => '',
    'funil_id' => 0,
    'produto_id' => 0,
    'desconto_percentual' => '0.00',
    'delay_minutes' => 30,
    'mensagem' => '',
    'media_tipo' => 'none',
    'media_url' => '',
    'webhook_url' => '',
    'webhook_secret' => '',
    'ativo' => 1,
];

if ($editingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM downsells WHERE id = ? AND ' . $downsellScope . ' LIMIT 1');
    $stmt->execute([$editingId]);
    $editing = $stmt->fetch();

    if ($editing) {
        $form = [
            'id' => (int) $editing['id'],
            'nome' => (string) ($editing['nome'] ?? ''),
            'funil_id' => (int) ($editing['funil_id'] ?? 0),
            'produto_id' => (int) ($editing['produto_id'] ?? 0),
            'desconto_percentual' => number_format((float) ($editing['desconto_percentual'] ?? 0), 2, '.', ''),
            'delay_minutes' => max(0, (int) ($editing['delay_minutes'] ?? 30)),
            'mensagem' => (string) ($editing['mensagem'] ?? ''),
            'media_tipo' => normalizar_media_tipo((string) ($editing['media_tipo'] ?? 'none')),
            'media_url' => (string) ($editing['media_url'] ?? ''),
            'webhook_url' => (string) ($editing['webhook_url'] ?? ''),
            'webhook_secret' => (string) ($editing['webhook_secret'] ?? ''),
            'ativo' => (int) ($editing['ativo'] ?? 1),
        ];
    } else {
        $msg = ['tipo' => 'warning', 'texto' => 'O downsell selecionado nao foi encontrado.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        $form = [
            'id' => (int) ($_POST['id'] ?? 0),
            'nome' => trim((string) ($_POST['nome'] ?? '')),
            'funil_id' => (int) ($_POST['funil_id'] ?? 0),
            'produto_id' => (int) ($_POST['produto_id'] ?? 0),
            'desconto_percentual' => str_replace(',', '.', trim((string) ($_POST['desconto_percentual'] ?? '0'))),
            'delay_minutes' => max(0, (int) ($_POST['delay_minutes'] ?? 30)),
            'mensagem' => trim((string) ($_POST['mensagem'] ?? '')),
            'media_tipo' => normalizar_media_tipo((string) ($_POST['media_tipo'] ?? 'none')),
            'media_url' => trim((string) ($_POST['media_url'] ?? '')),
            'webhook_url' => trim((string) ($_POST['webhook_url'] ?? '')),
            'webhook_secret' => trim((string) ($_POST['webhook_secret'] ?? '')),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
        ];

        $form['desconto_percentual'] = (float) $form['desconto_percentual'];
        $form['desconto_percentual'] = max(0, min(100, (float) $form['desconto_percentual']));
        $form['desconto_percentual'] = number_format((float) $form['desconto_percentual'], 2, '.', '');

        if ($form['nome'] === '') {
            $msg = ['tipo' => 'danger', 'texto' => 'Informe um nome para o downsell.'];
        } elseif ($form['funil_id'] <= 0) {
            $msg = ['tipo' => 'danger', 'texto' => 'Selecione o upsell de origem deste downsell.'];
        } elseif ($form['produto_id'] <= 0) {
            $msg = ['tipo' => 'danger', 'texto' => 'Selecione o produto ofertado no downsell.'];
        } elseif (!get_funil_por_id($form['funil_id']) || !get_produto_por_id($form['produto_id'])) {
            $msg = ['tipo' => 'danger', 'texto' => 'Funil e produto precisam pertencer ao workspace atual.'];
        } elseif ($form['media_tipo'] !== 'none' && $form['media_url'] === '') {
            $msg = ['tipo' => 'danger', 'texto' => 'Se escolher uma midia para o downsell, informe a URL publica dela.'];
        } elseif ($form['media_tipo'] !== 'none' && filter_var($form['media_url'], FILTER_VALIDATE_URL) === false) {
            $msg = ['tipo' => 'danger', 'texto' => 'A URL da midia do downsell precisa ser valida e publica.'];
        } elseif ($form['webhook_url'] !== '' && filter_var($form['webhook_url'], FILTER_VALIDATE_URL) === false) {
            $msg = ['tipo' => 'danger', 'texto' => 'A URL do webhook do downsell precisa ser valida e publica.'];
        } else {
            $mensagem = $form['mensagem'] !== '' ? $form['mensagem'] : null;
            $mediaUrl = $form['media_tipo'] !== 'none' ? $form['media_url'] : null;
            $webhookUrl = $form['webhook_url'] !== '' ? $form['webhook_url'] : null;
            $webhookSecret = $form['webhook_secret'] !== '' ? $form['webhook_secret'] : null;

            if ($form['id'] > 0) {
                $sql = 'UPDATE downsells
                        SET nome = ?, funil_id = ?, produto_id = ?, desconto_percentual = ?, delay_minutes = ?, mensagem = ?, media_tipo = ?, media_url = ?';
                $params = [
                    $form['nome'],
                    $form['funil_id'],
                    $form['produto_id'],
                    (float) $form['desconto_percentual'],
                    $form['delay_minutes'],
                    $mensagem,
                    $form['media_tipo'],
                    $mediaUrl,
                ];

                if ($hasWebhookColumns) {
                    $sql .= ', webhook_url = ?, webhook_secret = ?';
                    $params[] = $webhookUrl;
                    $params[] = $webhookSecret;
                }

                $sql .= ', ativo = ? WHERE id = ? AND ' . $downsellScope;
                $params[] = $form['ativo'];
                $params[] = $form['id'];

                $pdo->prepare($sql)->execute($params);
            } else {
                $columns = [
                    'nome',
                    'funil_id',
                    'produto_id',
                    'desconto_percentual',
                    'delay_minutes',
                    'mensagem',
                    'media_tipo',
                    'media_url',
                ];
                $params = [
                    $form['nome'],
                    $form['funil_id'],
                    $form['produto_id'],
                    (float) $form['desconto_percentual'],
                    $form['delay_minutes'],
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
                $params[] = $form['ativo'];

                $placeholders = array_fill(0, count($columns), '?');
                tenant_insert_append('downsells', $columns, $placeholders, $params);
                $pdo->prepare(
                    'INSERT INTO downsells (' . implode(', ', $columns) . ')
                     VALUES (' . implode(', ', $placeholders) . ')'
                )->execute($params);
            }

            header('Location: ' . admin_url('downsells.php?ok=salvo'));
            exit;
        }
    }

    if ($acao === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $ativo = (int) ($_POST['ativo'] ?? 0);

        if ($id > 0) {
            $pdo->prepare('UPDATE downsells SET ativo = ? WHERE id = ? AND ' . $downsellScope)->execute([$ativo ? 0 : 1, $id]);
            header('Location: ' . admin_url('downsells.php?ok=salvo'));
            exit;
        }
    }

    if ($acao === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        $emUso = 0;

        if (db_has_table('downsell_disparos')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM downsell_disparos WHERE downsell_id = ? AND ' . $disparoScope);
            $stmt->execute([$id]);
            $emUso += (int) $stmt->fetchColumn();
        }

        if ($emUso > 0) {
            $msg = ['tipo' => 'warning', 'texto' => 'Esse downsell ja possui disparos registrados. Desative em vez de excluir.'];
        } else {
            $pdo->prepare('DELETE FROM downsells WHERE id = ? AND ' . $downsellScope)->execute([$id]);
            header('Location: ' . admin_url('downsells.php?ok=excluido'));
            exit;
        }
    }
}

$funis = $pdo->query(
    'SELECT f.*, p.nome AS produto_principal_nome
     FROM funis f
     LEFT JOIN produtos p ON p.id = f.produto_principal_id AND ' . $produtoScope . '
     WHERE ' . $funilScope . '
     ORDER BY ' . db_order_by_clause('funis', 'f')
)->fetchAll();
$produtos = $pdo->query('SELECT * FROM produtos WHERE ' . $produtoScope . ' ORDER BY ' . db_order_by_clause('produtos'))->fetchAll();
$downsells = $pdo->query(
    'SELECT d.*, f.nome AS funil_nome, p.nome AS produto_nome, p.valor AS produto_valor
     FROM downsells d
     LEFT JOIN funis f ON f.id = d.funil_id AND ' . $funilScope . '
     LEFT JOIN produtos p ON p.id = d.produto_id AND ' . $produtoScope . '
     WHERE ' . $downsellScope . '
     ORDER BY ' . db_order_by_clause('downsells', 'd')
)->fetchAll();

$page_title = 'Downsell';
$page_subtitle = 'Oferta alternativa com atraso opcional, midia e webhook por disparo';
$active_menu = 'downsells';
include '_layout.php';
?>

<?php if ($msg): ?>
  <div class="alert alert-<?= htmlspecialchars($msg['tipo']) ?>"><?= htmlspecialchars($msg['texto']) ?></div>
<?php endif; ?>

<?php if (!$funis): ?>
  <div class="alert alert-warning">Nenhum upsell foi encontrado. Cadastre pelo menos um funil em <b>Upsell</b> antes de criar downsells.</div>
<?php endif; ?>

<?php if (!$produtos): ?>
  <div class="alert alert-warning">Nenhum produto ativo foi encontrado. Cadastre planos ou packs em <b>Produtos / Planos</b> antes de criar um downsell.</div>
<?php endif; ?>

<?php if (!$hasWebhookColumns): ?>
  <div class="alert alert-warning">A tabela <span class="mono">downsells</span> ainda nao tem as colunas de webhook. Importe o SQL novo para liberar disparos externos por downsell.</div>
<?php endif; ?>

<div class="content-grid content-grid--sidebar">
  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title"><?= $form['id'] > 0 ? 'Editar downsell' : 'Novo downsell' ?></h2>
        <p class="card-copy">Configure a oferta alternativa que entra depois do upsell principal. O atraso e em minutos e o webhook e opcional.</p>
      </div>
      <?php if ($form['id'] > 0): ?>
        <a href="<?= admin_url('downsells.php') ?>" class="btn btn-ghost btn-sm">Cancelar edicao</a>
      <?php endif; ?>
    </div>

    <div class="card-body">
      <form method="POST" class="stack">
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="nome">Nome do downsell</label>
            <input class="form-control" id="nome" type="text" name="nome" value="<?= htmlspecialchars((string) $form['nome']) ?>" placeholder="Ex: Oferta de recuperacao" required>
          </div>

          <div class="form-group">
            <label class="form-label" for="delay_minutes">Atraso do disparo (minutos)</label>
            <input class="form-control" id="delay_minutes" type="number" min="0" name="delay_minutes" value="<?= (int) $form['delay_minutes'] ?>">
            <span class="form-help">Ex: 30 para esperar meia hora antes de ofertar.</span>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="funil_id">Upsell de origem</label>
            <select class="form-control" id="funil_id" name="funil_id" required>
              <option value="0">Selecione o upsell de origem</option>
              <?php foreach ($funis as $funil): ?>
                <option value="<?= (int) $funil['id'] ?>" <?= (int) $form['funil_id'] === (int) $funil['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) ($funil['nome'] ?? 'Funil')) ?>
                  <?php if (!empty($funil['produto_principal_nome'])): ?>
                    - <?= htmlspecialchars((string) $funil['produto_principal_nome']) ?>
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label" for="produto_id">Produto do downsell</label>
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
            <label class="form-label" for="desconto_percentual">Desconto do downsell (%)</label>
            <input class="form-control" id="desconto_percentual" type="number" step="0.01" min="0" max="100" name="desconto_percentual" value="<?= htmlspecialchars((string) $form['desconto_percentual']) ?>" placeholder="15.00">
            <span class="form-help">O desconto e aplicado em cima do produto selecionado.</span>
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
          </div>
        </div>

        <div class="form-group" id="media_url_group">
          <label class="form-label" for="media_url">URL da midia</label>
          <input class="form-control" id="media_url" type="url" name="media_url" value="<?= htmlspecialchars((string) $form['media_url']) ?>" placeholder="https://...">
          <span class="form-help">Use uma URL publica e direta da imagem, video, audio ou arquivo.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="mensagem">Mensagem do downsell</label>
          <textarea class="form-control" id="mensagem" name="mensagem" rows="5" placeholder="Ex: Se preferir, ainda temos {produto} por {valor}."><?= htmlspecialchars((string) $form['mensagem']) ?></textarea>
          <span class="form-help">Use {produto}, {valor}, {valor_original} e {desconto}.</span>
        </div>

        <?php if ($hasWebhookColumns): ?>
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label" for="webhook_url">Webhook do downsell</label>
              <input class="form-control" id="webhook_url" type="url" name="webhook_url" value="<?= htmlspecialchars((string) $form['webhook_url']) ?>" placeholder="https://n8n.seudominio.com/webhook/downsell">
              <span class="form-help">Se preencher, o sistema dispara este webhook quando o downsell for enviado ao lead.</span>
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
            <span>Downsell ativo para disparo</span>
          </label>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><?= $form['id'] > 0 ? 'Salvar alteracoes' : 'Criar downsell' ?></button>
          <?php if ($form['id'] > 0): ?>
            <a href="<?= admin_url('downsells.php') ?>" class="btn btn-ghost">Limpar formulario</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Downsells cadastrados</h2>
        <p class="card-copy">Use editar para carregar a oferta no formulario ao lado. Aqui tambem aparece o atraso e o webhook configurado.</p>
      </div>
    </div>
    <div class="card-body" style="padding-top: 0;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Oferta</th>
              <th>Upsell</th>
              <th>Produto</th>
              <th>Delay</th>
              <th>Desconto</th>
              <th>Webhook</th>
              <th>Status</th>
              <th>Acoes</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$downsells): ?>
              <tr>
                <td colspan="9" class="empty-state">Nenhum downsell cadastrado ainda.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($downsells as $downsell): ?>
              <?php
                $webhookPreview = trim((string) ($downsell['webhook_url'] ?? ''));
                if ($webhookPreview !== '' && strlen($webhookPreview) > 42) {
                    $webhookPreview = substr($webhookPreview, 0, 39) . '...';
                }
              ?>
              <tr>
                <td class="mono"><?= (int) $downsell['id'] ?></td>
                <td>
                  <strong><?= htmlspecialchars((string) $downsell['nome']) ?></strong>
                  <?php if (!empty($downsell['mensagem'])): ?>
                    <div class="text-muted"><?= htmlspecialchars(substr((string) $downsell['mensagem'], 0, 80)) ?><?= strlen((string) $downsell['mensagem']) > 80 ? '...' : '' ?></div>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string) ($downsell['funil_nome'] ?? 'Sem upsell')) ?></td>
                <td>
                  <?= htmlspecialchars((string) ($downsell['produto_nome'] ?? 'Sem produto')) ?>
                  <?php if (!empty($downsell['produto_valor'])): ?>
                    <div class="text-muted mono"><?= formatar_valor((float) $downsell['produto_valor']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="mono"><?= (int) ($downsell['delay_minutes'] ?? 0) ?> min</td>
                <td class="mono"><?= number_format((float) ($downsell['desconto_percentual'] ?? 0), 2, ',', '.') ?>%</td>
                <td>
                  <?php if ($webhookPreview !== ''): ?>
                    Sim
                    <div class="text-muted mono"><?= htmlspecialchars($webhookPreview) ?></div>
                  <?php else: ?>
                    <span class="text-muted">Nao</span>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="POST" class="inline-form">
                    <input type="hidden" name="acao" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $downsell['id'] ?>">
                    <input type="hidden" name="ativo" value="<?= (int) $downsell['ativo'] ?>">
                    <button type="submit" class="btn btn-sm <?= (int) $downsell['ativo'] === 1 ? 'btn-secondary' : 'btn-ghost' ?>">
                      <?= (int) $downsell['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                    </button>
                  </form>
                </td>
                <td>
                  <div class="actions">
                    <a href="<?= admin_url('downsells.php?editar=' . (int) $downsell['id']) ?>" class="btn btn-ghost btn-sm">Editar</a>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Excluir este downsell?');">
                      <input type="hidden" name="acao" value="excluir">
                      <input type="hidden" name="id" value="<?= (int) $downsell['id'] ?>">
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
