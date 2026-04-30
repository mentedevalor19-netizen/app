<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();
require_admin($current_admin);

if (!db_has_table('remarketing_webhooks')) {
    $page_title = 'Remarketing';
    $page_subtitle = 'Modulo indisponivel';
    $active_menu = 'remarketing';
    include '_layout.php';
    ?>
    <div class="alert alert-warning">A tabela <span class="mono">remarketing_webhooks</span> ainda nao existe no banco atual. Importe o SQL atualizado antes de usar esta tela.</div>
    <?php
    include '_footer.php';
    return;
}

$pdo = db();
$editingId = (int) ($_GET['editar'] ?? 0);
$msg = null;
$eventOptions = remarketing_event_options();
$remarketingScope = tenant_scope_condition('remarketing_webhooks');

$form = [
    'id' => 0,
    'nome' => '',
    'evento' => 'lead_start',
    'webhook_url' => '',
    'webhook_secret' => '',
    'ativo' => 1,
    'ordem' => 0,
];

if ($editingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM remarketing_webhooks WHERE id = ? AND ' . $remarketingScope . ' LIMIT 1');
    $stmt->execute([$editingId]);
    $editing = $stmt->fetch();

    if ($editing) {
        $form = [
            'id' => (int) $editing['id'],
            'nome' => (string) ($editing['nome'] ?? ''),
            'evento' => (string) ($editing['evento'] ?? 'lead_start'),
            'webhook_url' => (string) ($editing['webhook_url'] ?? ''),
            'webhook_secret' => (string) ($editing['webhook_secret'] ?? ''),
            'ativo' => (int) ($editing['ativo'] ?? 1),
            'ordem' => (int) ($editing['ordem'] ?? 0),
        ];
    } else {
        $msg = ['tipo' => 'warning', 'texto' => 'A regra de remarketing selecionada nao foi encontrada.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        $form = [
            'id' => (int) ($_POST['id'] ?? 0),
            'nome' => trim((string) ($_POST['nome'] ?? '')),
            'evento' => trim((string) ($_POST['evento'] ?? 'lead_start')),
            'webhook_url' => trim((string) ($_POST['webhook_url'] ?? '')),
            'webhook_secret' => trim((string) ($_POST['webhook_secret'] ?? '')),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'ordem' => max(0, (int) ($_POST['ordem'] ?? 0)),
        ];

        if ($form['nome'] === '') {
            $msg = ['tipo' => 'danger', 'texto' => 'Informe um nome para a regra de remarketing.'];
        } elseif (!isset($eventOptions[$form['evento']])) {
            $msg = ['tipo' => 'danger', 'texto' => 'Selecione um evento valido para o remarketing.'];
        } elseif ($form['webhook_url'] === '') {
            $msg = ['tipo' => 'danger', 'texto' => 'Informe a URL do webhook que vai receber o evento.'];
        } elseif (filter_var($form['webhook_url'], FILTER_VALIDATE_URL) === false) {
            $msg = ['tipo' => 'danger', 'texto' => 'A URL do webhook precisa ser valida e publica.'];
        } else {
            $webhookSecret = $form['webhook_secret'] !== '' ? $form['webhook_secret'] : null;

            if ($form['id'] > 0) {
                $pdo->prepare(
                    'UPDATE remarketing_webhooks
                     SET nome = ?, evento = ?, webhook_url = ?, webhook_secret = ?, ativo = ?, ordem = ?
                     WHERE id = ? AND ' . $remarketingScope
                )->execute([
                    $form['nome'],
                    $form['evento'],
                    $form['webhook_url'],
                    $webhookSecret,
                    $form['ativo'],
                    $form['ordem'],
                    $form['id'],
                ]);
            } else {
                $columns = ['nome', 'evento', 'webhook_url', 'webhook_secret', 'ativo', 'ordem'];
                $placeholders = ['?', '?', '?', '?', '?', '?'];
                $params = [
                    $form['nome'],
                    $form['evento'],
                    $form['webhook_url'],
                    $webhookSecret,
                    $form['ativo'],
                    $form['ordem'],
                ];
                tenant_insert_append('remarketing_webhooks', $columns, $placeholders, $params);
                $pdo->prepare(
                    'INSERT INTO remarketing_webhooks (' . implode(', ', $columns) . ')
                     VALUES (' . implode(', ', $placeholders) . ')'
                )->execute($params);
            }

            header('Location: ' . admin_url('remarketing.php?ok=salvo'));
            exit;
        }
    }

    if ($acao === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $ativo = (int) ($_POST['ativo'] ?? 0);

        if ($id > 0) {
            $pdo->prepare('UPDATE remarketing_webhooks SET ativo = ? WHERE id = ? AND ' . $remarketingScope)->execute([$ativo ? 0 : 1, $id]);
            header('Location: ' . admin_url('remarketing.php?ok=salvo'));
            exit;
        }
    }

    if ($acao === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM remarketing_webhooks WHERE id = ? AND ' . $remarketingScope)->execute([$id]);
            header('Location: ' . admin_url('remarketing.php?ok=excluido'));
            exit;
        }
    }
}

$webhooks = $pdo->query(
    'SELECT *
     FROM remarketing_webhooks
     WHERE ' . $remarketingScope . '
     ORDER BY ' . db_order_by_clause('remarketing_webhooks')
)->fetchAll();

$page_title = 'Remarketing';
$page_subtitle = 'Webhooks por evento para recuperar leads e sincronizar automacoes externas';
$active_menu = 'remarketing';
include '_layout.php';
?>

<?php if ($msg): ?>
  <div class="alert alert-<?= htmlspecialchars($msg['tipo']) ?>"><?= htmlspecialchars($msg['texto']) ?></div>
<?php endif; ?>

<div class="content-grid content-grid--sidebar">
  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title"><?= $form['id'] > 0 ? 'Editar regra' : 'Nova regra' ?></h2>
        <p class="card-copy">Cada regra escuta um evento do bot e faz um POST para o webhook que voce escolher. O evento <b>lead_start</b> agora dispara apenas para quem ainda nao virou cliente.</p>
      </div>
      <?php if ($form['id'] > 0): ?>
        <a href="<?= admin_url('remarketing.php') ?>" class="btn btn-ghost btn-sm">Cancelar edicao</a>
      <?php endif; ?>
    </div>

    <div class="card-body">
      <form method="POST" class="stack">
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="nome">Nome da regra</label>
            <input class="form-control" id="nome" type="text" name="nome" value="<?= htmlspecialchars((string) $form['nome']) ?>" placeholder="Ex: Recuperacao apos Pix gerado" required>
          </div>

          <div class="form-group">
            <label class="form-label" for="ordem">Ordem</label>
            <input class="form-control" id="ordem" type="number" min="0" name="ordem" value="<?= (int) $form['ordem'] ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="evento">Evento</label>
          <select class="form-control" id="evento" name="evento" required>
            <?php foreach ($eventOptions as $eventKey => $eventLabel): ?>
              <option value="<?= htmlspecialchars($eventKey) ?>" <?= (string) $form['evento'] === (string) $eventKey ? 'selected' : '' ?>>
                <?= htmlspecialchars($eventLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="webhook_url">Webhook URL</label>
          <input class="form-control" id="webhook_url" type="url" name="webhook_url" value="<?= htmlspecialchars((string) $form['webhook_url']) ?>" placeholder="https://n8n.seudominio.com/webhook/remarketing" required>
          <span class="form-help">O sistema envia <span class="mono">POST</span> com payload JSON e o evento no header <span class="mono">X-App-Event</span>.</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="webhook_secret">Secret do webhook</label>
          <input class="form-control" id="webhook_secret" type="text" name="webhook_secret" value="<?= htmlspecialchars((string) $form['webhook_secret']) ?>" placeholder="segredo-opcional">
          <span class="form-help">Vai no header <span class="mono">X-App-Secret</span>. Pode deixar vazio se nao quiser autenticar.</span>
        </div>

        <div class="form-group">
          <label class="form-label">Status</label>
          <label class="checkbox-row">
            <input type="checkbox" name="ativo" <?= (int) $form['ativo'] === 1 ? 'checked' : '' ?>>
            <span>Regra ativa</span>
          </label>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><?= $form['id'] > 0 ? 'Salvar alteracoes' : 'Criar regra' ?></button>
          <?php if ($form['id'] > 0): ?>
            <a href="<?= admin_url('remarketing.php') ?>" class="btn btn-ghost">Limpar formulario</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Regras cadastradas</h2>
        <p class="card-copy">Aqui ficam as saidas de webhook para lead_start, Pix, aprovacao e ofertas. Cada evento pode ter uma ou varias regras.</p>
      </div>
    </div>
    <div class="card-body" style="padding-top: 0;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Nome</th>
              <th>Evento</th>
              <th>Webhook</th>
              <th>Ordem</th>
              <th>Status</th>
              <th>Acoes</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$webhooks): ?>
              <tr>
                <td colspan="7" class="empty-state">Nenhuma regra de remarketing cadastrada ainda.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($webhooks as $webhook): ?>
              <?php
                $webhookPreview = trim((string) ($webhook['webhook_url'] ?? ''));
                if ($webhookPreview !== '' && strlen($webhookPreview) > 48) {
                    $webhookPreview = substr($webhookPreview, 0, 45) . '...';
                }
              ?>
              <tr>
                <td class="mono"><?= (int) $webhook['id'] ?></td>
                <td><strong><?= htmlspecialchars((string) $webhook['nome']) ?></strong></td>
                <td><?= htmlspecialchars((string) ($eventOptions[$webhook['evento']] ?? $webhook['evento'])) ?></td>
                <td class="mono"><?= htmlspecialchars($webhookPreview) ?></td>
                <td class="mono"><?= (int) ($webhook['ordem'] ?? 0) ?></td>
                <td>
                  <form method="POST" class="inline-form">
                    <input type="hidden" name="acao" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $webhook['id'] ?>">
                    <input type="hidden" name="ativo" value="<?= (int) $webhook['ativo'] ?>">
                    <button type="submit" class="btn btn-sm <?= (int) $webhook['ativo'] === 1 ? 'btn-secondary' : 'btn-ghost' ?>">
                      <?= (int) $webhook['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                    </button>
                  </form>
                </td>
                <td>
                  <div class="actions">
                    <a href="<?= admin_url('remarketing.php?editar=' . (int) $webhook['id']) ?>" class="btn btn-ghost btn-sm">Editar</a>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Excluir esta regra de remarketing?');">
                      <input type="hidden" name="acao" value="excluir">
                      <input type="hidden" name="id" value="<?= (int) $webhook['id'] ?>">
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

<?php include '_footer.php'; ?>
