<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();
require_admin($current_admin);

if (!db_has_table('funis') || !db_has_table('produtos')) {
    $page_title = 'Funis / Upsell';
    $page_subtitle = 'Modulo indisponivel';
    $active_menu = 'funis';
    include '_layout.php';
    ?>
    <div class="alert alert-warning">As tabelas necessarias para funis e produtos ainda nao existem no banco atual. Importe o SQL atualizado antes de usar esta tela.</div>
    <?php
    include '_footer.php';
    return;
}

$hasMediaColumns = db_has_column('funis', 'upsell_media_tipo') && db_has_column('funis', 'upsell_media_url');
$hasWebhookColumns = db_has_column('funis', 'upsell_webhook_url') && db_has_column('funis', 'upsell_webhook_secret');
if (!$hasMediaColumns) {
    $page_title = 'Funis / Upsell';
    $page_subtitle = 'Modulo indisponivel';
    $active_menu = 'funis';
    include '_layout.php';
    ?>
    <div class="alert alert-warning">A tabela <span class="mono">funis</span> ainda nao tem as colunas de midia do upsell. Execute o SQL atualizado antes de usar este modulo.</div>
    <?php
    include '_footer.php';
    return;
}

$pdo = db();
$editingId = (int) ($_GET['editar'] ?? 0);
$msg = null;

$form = [
    'id' => 0,
    'nome' => '',
    'headline' => '',
    'descricao' => '',
    'produto_principal_id' => 0,
    'upsell_produto_id' => 0,
    'upsell_desconto_percentual' => '0.00',
    'mensagem_upsell' => '',
    'upsell_media_tipo' => 'none',
    'upsell_media_url' => '',
    'upsell_webhook_url' => '',
    'upsell_webhook_secret' => '',
    'ativo' => 1,
    'ordem' => 0,
];

if ($editingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM funis WHERE id = ? LIMIT 1');
    $stmt->execute([$editingId]);
    $editing = $stmt->fetch();

    if ($editing) {
        $form = [
            'id' => (int) $editing['id'],
            'nome' => (string) ($editing['nome'] ?? ''),
            'headline' => (string) ($editing['headline'] ?? ''),
            'descricao' => (string) ($editing['descricao'] ?? ''),
            'produto_principal_id' => (int) ($editing['produto_principal_id'] ?? 0),
            'upsell_produto_id' => (int) ($editing['upsell_produto_id'] ?? 0),
            'upsell_desconto_percentual' => number_format((float) ($editing['upsell_desconto_percentual'] ?? 0), 2, '.', ''),
            'mensagem_upsell' => (string) ($editing['mensagem_upsell'] ?? ''),
            'upsell_media_tipo' => normalizar_media_tipo((string) ($editing['upsell_media_tipo'] ?? 'none')),
            'upsell_media_url' => (string) ($editing['upsell_media_url'] ?? ''),
            'upsell_webhook_url' => (string) ($editing['upsell_webhook_url'] ?? ''),
            'upsell_webhook_secret' => (string) ($editing['upsell_webhook_secret'] ?? ''),
            'ativo' => (int) ($editing['ativo'] ?? 1),
            'ordem' => (int) ($editing['ordem'] ?? 0),
        ];
    } else {
        $msg = ['tipo' => 'warning', 'texto' => 'O funil selecionado nao foi encontrado.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        $form = [
            'id' => (int) ($_POST['id'] ?? 0),
            'nome' => trim((string) ($_POST['nome'] ?? '')),
            'headline' => trim((string) ($_POST['headline'] ?? '')),
            'descricao' => trim((string) ($_POST['descricao'] ?? '')),
            'produto_principal_id' => (int) ($_POST['produto_principal_id'] ?? 0),
            'upsell_produto_id' => (int) ($_POST['upsell_produto_id'] ?? 0),
            'upsell_desconto_percentual' => str_replace(',', '.', trim((string) ($_POST['upsell_desconto_percentual'] ?? '0'))),
            'mensagem_upsell' => trim((string) ($_POST['mensagem_upsell'] ?? '')),
            'upsell_media_tipo' => normalizar_media_tipo((string) ($_POST['upsell_media_tipo'] ?? 'none')),
            'upsell_media_url' => trim((string) ($_POST['upsell_media_url'] ?? '')),
            'upsell_webhook_url' => trim((string) ($_POST['upsell_webhook_url'] ?? '')),
            'upsell_webhook_secret' => trim((string) ($_POST['upsell_webhook_secret'] ?? '')),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'ordem' => max(0, (int) ($_POST['ordem'] ?? 0)),
        ];

        $form['upsell_desconto_percentual'] = (float) $form['upsell_desconto_percentual'];
        $form['upsell_desconto_percentual'] = max(0, min(100, (float) $form['upsell_desconto_percentual']));
        $form['upsell_desconto_percentual'] = number_format((float) $form['upsell_desconto_percentual'], 2, '.', '');

        if ($form['nome'] === '') {
            $msg = ['tipo' => 'danger', 'texto' => 'Informe um nome para o funil.'];
        } elseif ($form['produto_principal_id'] <= 0) {
            $msg = ['tipo' => 'danger', 'texto' => 'Selecione o produto principal do funil.'];
        } elseif ($form['upsell_produto_id'] <= 0) {
            $msg = ['tipo' => 'danger', 'texto' => 'Selecione o produto de upsell.'];
        } elseif ($form['upsell_produto_id'] === $form['produto_principal_id']) {
            $msg = ['tipo' => 'danger', 'texto' => 'O produto principal e o upsell precisam ser diferentes.'];
        } elseif ($form['upsell_media_tipo'] !== 'none' && $form['upsell_media_url'] === '') {
            $msg = ['tipo' => 'danger', 'texto' => 'Se escolher uma midia para o upsell, informe a URL publica dela.'];
        } elseif ($form['upsell_media_tipo'] !== 'none' && filter_var($form['upsell_media_url'], FILTER_VALIDATE_URL) === false) {
            $msg = ['tipo' => 'danger', 'texto' => 'A URL da midia do upsell precisa ser valida e publica.'];
        } elseif ($form['upsell_webhook_url'] !== '' && filter_var($form['upsell_webhook_url'], FILTER_VALIDATE_URL) === false) {
            $msg = ['tipo' => 'danger', 'texto' => 'A URL do webhook do upsell precisa ser valida e publica.'];
        } else {
            $headline = $form['headline'] !== '' ? $form['headline'] : null;
            $descricao = $form['descricao'] !== '' ? $form['descricao'] : null;
            $mensagemUpsell = $form['mensagem_upsell'] !== '' ? $form['mensagem_upsell'] : null;
            $mediaUrl = $form['upsell_media_tipo'] !== 'none' ? $form['upsell_media_url'] : null;
            $upsellProdutoId = $form['upsell_produto_id'] > 0 ? $form['upsell_produto_id'] : null;
            $webhookUrl = $form['upsell_webhook_url'] !== '' ? $form['upsell_webhook_url'] : null;
            $webhookSecret = $form['upsell_webhook_secret'] !== '' ? $form['upsell_webhook_secret'] : null;

            if ($form['id'] > 0) {
                $sql = 'UPDATE funis
                        SET nome = ?, descricao = ?, headline = ?, mensagem_upsell = ?, upsell_desconto_percentual = ?,
                            upsell_media_tipo = ?, upsell_media_url = ?, produto_principal_id = ?, upsell_produto_id = ?';
                $params = [
                    $form['nome'],
                    $descricao,
                    $headline,
                    $mensagemUpsell,
                    (float) $form['upsell_desconto_percentual'],
                    $form['upsell_media_tipo'],
                    $mediaUrl,
                    $form['produto_principal_id'],
                    $upsellProdutoId,
                ];

                if ($hasWebhookColumns) {
                    $sql .= ', upsell_webhook_url = ?, upsell_webhook_secret = ?';
                    $params[] = $webhookUrl;
                    $params[] = $webhookSecret;
                }

                $sql .= ', ativo = ?, ordem = ? WHERE id = ?';
                $params[] = $form['ativo'];
                $params[] = $form['ordem'];
                $params[] = $form['id'];

                $pdo->prepare($sql)->execute($params);
            } else {
                $columns = [
                    'nome',
                    'descricao',
                    'headline',
                    'mensagem_upsell',
                    'upsell_desconto_percentual',
                    'upsell_media_tipo',
                    'upsell_media_url',
                    'produto_principal_id',
                    'upsell_produto_id',
                ];
                $params = [
                    $form['nome'],
                    $descricao,
                    $headline,
                    $mensagemUpsell,
                    (float) $form['upsell_desconto_percentual'],
                    $form['upsell_media_tipo'],
                    $mediaUrl,
                    $form['produto_principal_id'],
                    $upsellProdutoId,
                ];

                if ($hasWebhookColumns) {
                    $columns[] = 'upsell_webhook_url';
                    $columns[] = 'upsell_webhook_secret';
                    $params[] = $webhookUrl;
                    $params[] = $webhookSecret;
                }

                $columns[] = 'ativo';
                $columns[] = 'ordem';
                $params[] = $form['ativo'];
                $params[] = $form['ordem'];

                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $pdo->prepare(
                    'INSERT INTO funis (' . implode(', ', $columns) . ')
                     VALUES (' . $placeholders . ')'
                )->execute($params);
            }

            header('Location: ' . admin_url('funis.php?ok=salvo'));
            exit;
        }
    }

    if ($acao === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $ativo = (int) ($_POST['ativo'] ?? 0);

        if ($id > 0) {
            $pdo->prepare('UPDATE funis SET ativo = ? WHERE id = ?')->execute([$ativo ? 0 : 1, $id]);
            header('Location: ' . admin_url('funis.php?ok=salvo'));
            exit;
        }
    }

    if ($acao === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        $emUso = 0;

        if (db_has_table('downsells')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM downsells WHERE funil_id = ?');
            $stmt->execute([$id]);
            $emUso += (int) $stmt->fetchColumn();
        }

        if (db_has_table('fluxos') && db_has_column('fluxos', 'funil_id')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM fluxos WHERE funil_id = ?');
            $stmt->execute([$id]);
            $emUso += (int) $stmt->fetchColumn();
        }

        if (db_has_table('pagamentos') && db_has_column('pagamentos', 'funil_id')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM pagamentos WHERE funil_id = ?');
            $stmt->execute([$id]);
            $emUso += (int) $stmt->fetchColumn();
        }

        if ($emUso > 0) {
            $msg = ['tipo' => 'warning', 'texto' => 'Esse funil possui registros vinculados. Desative em vez de excluir.'];
        } else {
            $pdo->prepare('DELETE FROM funis WHERE id = ?')->execute([$id]);
            header('Location: ' . admin_url('funis.php?ok=excluido'));
            exit;
        }
    }
}

$produtos = $pdo->query('SELECT * FROM produtos ORDER BY ' . db_order_by_clause('produtos'))->fetchAll();
$funis = $pdo->query(
    'SELECT f.*,
            p.nome AS produto_principal_nome, p.valor AS produto_principal_valor, p.tipo AS produto_principal_tipo,
            u.nome AS upsell_produto_nome, u.valor AS upsell_produto_valor, u.tipo AS upsell_produto_tipo
     FROM funis f
     LEFT JOIN produtos p ON p.id = f.produto_principal_id
     LEFT JOIN produtos u ON u.id = f.upsell_produto_id
     ORDER BY ' . db_order_by_clause('funis', 'f')
)->fetchAll();

$page_title = 'Funis / Upsell';
$page_subtitle = 'Upsell com desconto, produto existente, midia opcional e webhook por oferta';
$active_menu = 'funis';
include '_layout.php';
?>

<?php if ($msg): ?>
  <div class="alert alert-<?= htmlspecialchars($msg['tipo']) ?>"><?= htmlspecialchars($msg['texto']) ?></div>
<?php endif; ?>

<?php if (!$produtos): ?>
  <div class="alert alert-warning">Nenhum produto ativo foi encontrado. Cadastre planos ou packs em <b>Produtos / Planos</b> antes de criar um funil.</div>
<?php endif; ?>

<?php if (!$hasWebhookColumns): ?>
  <div class="alert alert-warning">A tabela <span class="mono">funis</span> ainda nao tem as colunas de webhook do upsell. Importe o SQL novo para liberar o disparo externo por oferta.</div>
<?php endif; ?>

<div class="content-grid content-grid--sidebar">
  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title"><?= $form['id'] > 0 ? 'Editar funil' : 'Novo funil' ?></h2>
        <p class="card-copy">Configure o produto principal, o upsell com desconto e a midia da oferta. O bot usa essa configuracao depois do Pix principal.</p>
      </div>
      <?php if ($form['id'] > 0): ?>
        <a href="<?= admin_url('funis.php') ?>" class="btn btn-ghost btn-sm">Cancelar edicao</a>
      <?php endif; ?>
    </div>

    <div class="card-body">
      <form method="POST" class="stack">
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="nome">Nome do funil</label>
            <input class="form-control" id="nome" type="text" name="nome" value="<?= htmlspecialchars((string) $form['nome']) ?>" placeholder="Ex: Oferta VIP principal" required>
          </div>

          <div class="form-group">
            <label class="form-label" for="headline">Headline da vitrine</label>
            <input class="form-control" id="headline" type="text" name="headline" value="<?= htmlspecialchars((string) $form['headline']) ?>" placeholder="Ex: Acesso principal com oferta especial">
            <span class="form-help">Se vazio, o bot usa o nome do funil no botao.</span>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="descricao">Descricao</label>
          <textarea class="form-control" id="descricao" name="descricao" rows="3" placeholder="Texto curto para organizar o funil."><?= htmlspecialchars((string) $form['descricao']) ?></textarea>
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
            <label class="form-label" for="upsell_produto_id">Produto de upsell</label>
            <select class="form-control" id="upsell_produto_id" name="upsell_produto_id" required>
              <option value="0">Selecione o produto de upsell</option>
              <?php foreach ($produtos as $produto): ?>
                <option value="<?= (int) $produto['id'] ?>" <?= (int) $form['upsell_produto_id'] === (int) $produto['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars(produto_rotulo_bot($produto)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="upsell_desconto_percentual">Desconto do upsell (%)</label>
            <input class="form-control" id="upsell_desconto_percentual" type="number" step="0.01" min="0" max="100" name="upsell_desconto_percentual" value="<?= htmlspecialchars((string) $form['upsell_desconto_percentual']) ?>" placeholder="10.00">
            <span class="form-help">O desconto e aplicado em cima do produto ja existente.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="ordem">Ordem de exibicao</label>
            <input class="form-control" id="ordem" type="number" min="0" name="ordem" value="<?= (int) $form['ordem'] ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="mensagem_upsell">Mensagem do upsell</label>
          <textarea class="form-control" id="mensagem_upsell" name="mensagem_upsell" rows="5" placeholder="Ex: Adicione tambem {produto} por {valor}."><?= htmlspecialchars((string) $form['mensagem_upsell']) ?></textarea>
          <span class="form-help">Use {produto}, {valor}, {valor_original} e {desconto}. Se esta mensagem ficar vazia, o bot usa um texto padrao.</span>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="upsell_media_tipo">Midia do upsell</label>
            <select class="form-control" id="upsell_media_tipo" name="upsell_media_tipo">
              <option value="none" <?= (string) $form['upsell_media_tipo'] === 'none' ? 'selected' : '' ?>>Sem midia</option>
              <option value="photo" <?= (string) $form['upsell_media_tipo'] === 'photo' ? 'selected' : '' ?>>Imagem</option>
              <option value="video" <?= (string) $form['upsell_media_tipo'] === 'video' ? 'selected' : '' ?>>Video</option>
              <option value="audio" <?= (string) $form['upsell_media_tipo'] === 'audio' ? 'selected' : '' ?>>Audio</option>
              <option value="document" <?= (string) $form['upsell_media_tipo'] === 'document' ? 'selected' : '' ?>>Arquivo</option>
            </select>
            <span class="form-help">A midia aparece no Telegram com a mensagem do upsell como legenda.</span>
          </div>

          <div class="form-group" id="upsell_media_url_group">
            <label class="form-label" for="upsell_media_url">URL da midia do upsell</label>
            <input class="form-control" id="upsell_media_url" type="url" name="upsell_media_url" value="<?= htmlspecialchars((string) $form['upsell_media_url']) ?>" placeholder="https://...">
            <span class="form-help">Use uma URL publica e direta da imagem, video, audio ou arquivo.</span>
          </div>
        </div>

        <?php if ($hasWebhookColumns): ?>
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label" for="upsell_webhook_url">Webhook do upsell</label>
              <input class="form-control" id="upsell_webhook_url" type="url" name="upsell_webhook_url" value="<?= htmlspecialchars((string) $form['upsell_webhook_url']) ?>" placeholder="https://n8n.seudominio.com/webhook/upsell">
              <span class="form-help">Se preencher, o sistema dispara este webhook quando a oferta de upsell for enviada no Telegram.</span>
            </div>

            <div class="form-group">
              <label class="form-label" for="upsell_webhook_secret">Secret do webhook</label>
              <input class="form-control" id="upsell_webhook_secret" type="text" name="upsell_webhook_secret" value="<?= htmlspecialchars((string) $form['upsell_webhook_secret']) ?>" placeholder="segredo-opcional">
              <span class="form-help">Vai no header <span class="mono">X-App-Secret</span>. Deixe vazio se nao quiser autenticar esta oferta.</span>
            </div>
          </div>
        <?php endif; ?>

        <div class="form-group">
          <label class="form-label">Status</label>
          <label class="checkbox-row">
            <input type="checkbox" name="ativo" <?= (int) $form['ativo'] === 1 ? 'checked' : '' ?>>
            <span>Funil ativo para disparo</span>
          </label>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><?= $form['id'] > 0 ? 'Salvar alteracoes' : 'Criar funil' ?></button>
          <?php if ($form['id'] > 0): ?>
            <a href="<?= admin_url('funis.php') ?>" class="btn btn-ghost">Limpar formulario</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Funis cadastrados</h2>
        <p class="card-copy">Use editar para carregar o funil no formulario ao lado. Aqui tambem aparece a midia do upsell que vai junto com a oferta.</p>
      </div>
    </div>
    <div class="card-body" style="padding-top: 0;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Funil</th>
              <th>Principal</th>
              <th>Upsell</th>
              <th>Desconto</th>
              <th>Midia</th>
              <th>Webhook</th>
              <th>Ordem</th>
              <th>Status</th>
              <th>Acoes</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$funis): ?>
              <tr>
                <td colspan="10" class="empty-state">Nenhum funil cadastrado ainda.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($funis as $funil): ?>
              <?php
                $principal = trim((string) ($funil['produto_principal_nome'] ?? ''));
                $upsell = trim((string) ($funil['upsell_produto_nome'] ?? ''));
                $mediaTipo = normalizar_media_tipo((string) ($funil['upsell_media_tipo'] ?? 'none'));
                $mediaTipoLabel = [
                    'none' => 'Sem midia',
                    'photo' => 'Imagem',
                    'video' => 'Video',
                    'audio' => 'Audio',
                    'document' => 'Arquivo',
                ][$mediaTipo] ?? 'Sem midia';
                $mediaPreview = trim((string) ($funil['upsell_media_url'] ?? ''));
                if ($mediaPreview !== '' && strlen($mediaPreview) > 42) {
                    $mediaPreview = substr($mediaPreview, 0, 39) . '...';
                }
                $webhookPreview = trim((string) ($funil['upsell_webhook_url'] ?? ''));
                if ($webhookPreview !== '' && strlen($webhookPreview) > 42) {
                    $webhookPreview = substr($webhookPreview, 0, 39) . '...';
                }
              ?>
              <tr>
                <td class="mono"><?= (int) $funil['id'] ?></td>
                <td>
                  <strong><?= htmlspecialchars((string) $funil['nome']) ?></strong>
                  <?php if (!empty($funil['headline'])): ?>
                    <div class="text-muted"><?= htmlspecialchars((string) $funil['headline']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?= $principal !== '' ? htmlspecialchars($principal) : 'Sem produto' ?>
                  <?php if (!empty($funil['produto_principal_valor'])): ?>
                    <div class="text-muted mono"><?= formatar_valor((float) $funil['produto_principal_valor']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?= $upsell !== '' ? htmlspecialchars($upsell) : 'Sem upsell' ?>
                  <?php if (!empty($funil['upsell_produto_valor'])): ?>
                    <div class="text-muted mono"><?= formatar_valor((float) $funil['upsell_produto_valor']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="mono"><?= number_format((float) ($funil['upsell_desconto_percentual'] ?? 0), 2, ',', '.') ?>%</td>
                <td>
                  <?= htmlspecialchars($mediaTipoLabel) ?>
                  <?php if ($mediaPreview !== ''): ?>
                    <div class="text-muted mono"><?= htmlspecialchars($mediaPreview) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($webhookPreview !== ''): ?>
                    Configurado
                    <div class="text-muted mono"><?= htmlspecialchars($webhookPreview) ?></div>
                  <?php else: ?>
                    <span class="text-muted">Nao</span>
                  <?php endif; ?>
                </td>
                <td class="mono"><?= (int) ($funil['ordem'] ?? 0) ?></td>
                <td>
                  <form method="POST" class="inline-form">
                    <input type="hidden" name="acao" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $funil['id'] ?>">
                    <input type="hidden" name="ativo" value="<?= (int) $funil['ativo'] ?>">
                    <button type="submit" class="btn btn-sm <?= (int) $funil['ativo'] === 1 ? 'btn-secondary' : 'btn-ghost' ?>">
                      <?= (int) $funil['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                    </button>
                  </form>
                </td>
                <td>
                  <div class="actions">
                    <a href="<?= admin_url('funis.php?editar=' . (int) $funil['id']) ?>" class="btn btn-ghost btn-sm">Editar</a>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Excluir este funil?');">
                      <input type="hidden" name="acao" value="excluir">
                      <input type="hidden" name="id" value="<?= (int) $funil['id'] ?>">
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
  const tipoSelect = document.getElementById('upsell_media_tipo');
  const mediaGroup = document.getElementById('upsell_media_url_group');
  const mediaInput = document.getElementById('upsell_media_url');

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
