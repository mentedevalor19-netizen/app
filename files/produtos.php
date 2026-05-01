<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();
require_admin($current_admin);

$pdo = db();
$msg = null;
$editingId = (int) ($_GET['editar'] ?? 0);
$hasTipo = db_has_column('produtos', 'tipo');
$hasPackLink = db_has_column('produtos', 'pack_link');
$hasVisibilityControls = produtos_has_visibility_controls();
$produtoScope = tenant_scope_condition('produtos');
$pagamentoScope = tenant_scope_condition('pagamentos');

if (!db_has_table('produtos')) {
    $page_title = 'Produtos / Planos e Packs';
    $page_subtitle = 'Modulo indisponivel';
    $active_menu = 'produtos';
    include '_layout.php';
    ?>
    <div class="alert alert-warning">A tabela <span class="mono">produtos</span> ainda nao existe. Importe o SQL do painel antes de usar esta tela.</div>
    <?php
    include '_footer.php';
    return;
}

$form = [
    'id' => 0,
    'nome' => '',
    'descricao' => '',
    'valor' => '',
    'dias_acesso' => 30,
    'tipo' => 'grupo',
    'pack_link' => '',
    'ativo' => 1,
    'mostrar_catalogo' => 1,
    'permitir_orderbump' => 1,
    'permitir_upsell' => 1,
    'permitir_downsell' => 1,
    'ordem' => 0,
];

if ($editingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM produtos WHERE id = ? AND ' . $produtoScope . ' LIMIT 1');
    $stmt->execute([$editingId]);
    $editing = $stmt->fetch();

    if ($editing) {
        $form = [
            'id' => (int) $editing['id'],
            'nome' => (string) $editing['nome'],
            'descricao' => (string) ($editing['descricao'] ?? ''),
            'valor' => (string) $editing['valor'],
            'dias_acesso' => (int) ($editing['dias_acesso'] ?? 0),
            'tipo' => produto_tipo($editing),
            'pack_link' => produto_pack_link($editing),
            'ativo' => (int) $editing['ativo'],
            'mostrar_catalogo' => produto_visibility_flag($editing, 'mostrar_catalogo'),
            'permitir_orderbump' => produto_visibility_flag($editing, 'permitir_orderbump'),
            'permitir_upsell' => produto_visibility_flag($editing, 'permitir_upsell'),
            'permitir_downsell' => produto_visibility_flag($editing, 'permitir_downsell'),
            'ordem' => (int) $editing['ordem'],
        ];
    } else {
        $msg = ['tipo' => 'warning', 'texto' => 'O produto selecionado nao foi encontrado.'];
        $editingId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        $form = [
            'id' => (int) ($_POST['id'] ?? 0),
            'nome' => trim((string) ($_POST['nome'] ?? '')),
            'descricao' => trim((string) ($_POST['descricao'] ?? '')),
            'valor' => str_replace(',', '.', trim((string) ($_POST['valor'] ?? ''))),
            'dias_acesso' => max(0, (int) ($_POST['dias_acesso'] ?? 0)),
            'tipo' => ((string) ($_POST['tipo'] ?? 'grupo')) === 'pack' ? 'pack' : 'grupo',
            'pack_link' => trim((string) ($_POST['pack_link'] ?? '')),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'mostrar_catalogo' => isset($_POST['mostrar_catalogo']) ? 1 : 0,
            'permitir_orderbump' => isset($_POST['permitir_orderbump']) ? 1 : 0,
            'permitir_upsell' => isset($_POST['permitir_upsell']) ? 1 : 0,
            'permitir_downsell' => isset($_POST['permitir_downsell']) ? 1 : 0,
            'ordem' => max(0, (int) ($_POST['ordem'] ?? 0)),
        ];

        if ($form['tipo'] === 'pack') {
            $form['dias_acesso'] = 0;
        }

        if ($form['tipo'] === 'pack' && (!$hasTipo || !$hasPackLink)) {
            $msg = ['tipo' => 'warning', 'texto' => 'Execute o SQL atualizado antes de cadastrar packs. O banco atual ainda nao suporta esse tipo de entrega.'];
        } elseif ($form['nome'] === '' || (float) $form['valor'] <= 0) {
            $msg = ['tipo' => 'danger', 'texto' => 'Preencha nome e valor corretamente.'];
        } elseif ($form['tipo'] === 'grupo' && $form['dias_acesso'] <= 0) {
            $msg = ['tipo' => 'danger', 'texto' => 'Para um plano de grupo, informe uma duracao em dias maior que zero.'];
        } elseif ($form['tipo'] === 'pack' && $form['pack_link'] === '') {
            $msg = ['tipo' => 'danger', 'texto' => 'Informe o link que sera entregue quando o pack for pago.'];
        } elseif ($form['tipo'] === 'pack' && filter_var($form['pack_link'], FILTER_VALIDATE_URL) === false) {
            $msg = ['tipo' => 'danger', 'texto' => 'O link do pack precisa ser uma URL valida, com http:// ou https://.'];
        } elseif ($hasVisibilityControls && $form['mostrar_catalogo'] !== 1 && $form['permitir_orderbump'] !== 1 && $form['permitir_upsell'] !== 1 && $form['permitir_downsell'] !== 1) {
            $msg = ['tipo' => 'danger', 'texto' => 'Escolha pelo menos um lugar onde esse produto pode ser usado.'];
        } else {
            if ($form['id'] > 0) {
                $setParts = [
                    'nome = ?',
                    'descricao = ?',
                    'valor = ?',
                    'dias_acesso = ?',
                ];
                $params = [
                    $form['nome'],
                    $form['descricao'],
                    (float) $form['valor'],
                    $form['dias_acesso'],
                ];

                if ($hasTipo) {
                    $setParts[] = 'tipo = ?';
                    $params[] = $form['tipo'];
                }

                if ($hasPackLink) {
                    $setParts[] = 'pack_link = ?';
                    $params[] = $form['tipo'] === 'pack' ? $form['pack_link'] : null;
                }

                $setParts[] = 'ativo = ?';
                $params[] = $form['ativo'];

                if ($hasVisibilityControls) {
                    $setParts[] = 'mostrar_catalogo = ?';
                    $setParts[] = 'permitir_orderbump = ?';
                    $setParts[] = 'permitir_upsell = ?';
                    $setParts[] = 'permitir_downsell = ?';
                    $params[] = $form['mostrar_catalogo'];
                    $params[] = $form['permitir_orderbump'];
                    $params[] = $form['permitir_upsell'];
                    $params[] = $form['permitir_downsell'];
                }

                $setParts[] = 'ordem = ?';
                $params[] = $form['ordem'];
                $params[] = $form['id'];

                $pdo->prepare(
                    'UPDATE produtos
                     SET ' . implode(', ', $setParts) . '
                     WHERE id = ? AND ' . $produtoScope
                )->execute($params);
            } else {
                $columns = ['nome', 'descricao', 'valor', 'dias_acesso'];
                $placeholders = ['?', '?', '?', '?'];
                $params = [
                    $form['nome'],
                    $form['descricao'],
                    (float) $form['valor'],
                    $form['dias_acesso'],
                ];

                if ($hasTipo) {
                    $columns[] = 'tipo';
                    $placeholders[] = '?';
                    $params[] = $form['tipo'];
                }

                if ($hasPackLink) {
                    $columns[] = 'pack_link';
                    $placeholders[] = '?';
                    $params[] = $form['tipo'] === 'pack' ? $form['pack_link'] : null;
                }

                $columns[] = 'ativo';
                $placeholders[] = '?';
                $params[] = $form['ativo'];

                if ($hasVisibilityControls) {
                    $columns[] = 'mostrar_catalogo';
                    $columns[] = 'permitir_orderbump';
                    $columns[] = 'permitir_upsell';
                    $columns[] = 'permitir_downsell';
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                    $params[] = $form['mostrar_catalogo'];
                    $params[] = $form['permitir_orderbump'];
                    $params[] = $form['permitir_upsell'];
                    $params[] = $form['permitir_downsell'];
                }

                $columns[] = 'ordem';
                $placeholders[] = '?';
                $params[] = $form['ordem'];

                tenant_insert_append('produtos', $columns, $placeholders, $params);
                $pdo->prepare(
                    'INSERT INTO produtos (' . implode(', ', $columns) . ')
                     VALUES (' . implode(', ', $placeholders) . ')'
                )->execute($params);
            }

            header('Location: ' . admin_url('produtos.php?ok=salvo'));
            exit;
        }
    }

    if ($acao === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $ativo = (int) ($_POST['ativo'] ?? 0);

        $pdo->prepare('UPDATE produtos SET ativo = ? WHERE id = ? AND ' . $produtoScope)->execute([$ativo ? 0 : 1, $id]);
        header('Location: ' . admin_url('produtos.php?ok=salvo'));
        exit;
    }

    if ($acao === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        $emUso = 0;

        if (db_has_column('pagamentos', 'produto_id')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM pagamentos WHERE produto_id = ? AND ' . $pagamentoScope);
            $stmt->execute([$id]);
            $emUso = (int) $stmt->fetchColumn();
        }

        if ($emUso > 0) {
            $msg = ['tipo' => 'warning', 'texto' => 'Esse produto possui pagamentos vinculados. Desative em vez de excluir.'];
        } else {
            $pdo->prepare('DELETE FROM produtos WHERE id = ? AND ' . $produtoScope)->execute([$id]);
            header('Location: ' . admin_url('produtos.php?ok=excluido'));
            exit;
        }
    }
}

$produtos = $pdo->query('SELECT * FROM produtos WHERE ' . $produtoScope . ' ORDER BY ' . db_order_by_clause('produtos'))->fetchAll();

$page_title = 'Produtos / Planos e Packs';
$page_subtitle = count($produtos) . ' produto(s) cadastrado(s)';
$active_menu = 'produtos';
include '_layout.php';
?>

<?php if ($msg): ?>
  <div class="alert alert-<?= htmlspecialchars($msg['tipo']) ?>"><?= htmlspecialchars($msg['texto']) ?></div>
<?php endif; ?>

<?php if (!$hasTipo || !$hasPackLink): ?>
  <div class="alert alert-warning">O banco ainda nao tem as colunas de packs. Execute o SQL atualizado para liberar a venda por link no bot.</div>
<?php endif; ?>

<?php if (!$hasVisibilityControls): ?>
  <div class="alert alert-warning">O banco ainda nao tem os controles de visibilidade por oferta. Rode a migration nova para criar produtos exclusivos de catalogo, order bump, upsell ou downsell.</div>
<?php endif; ?>

<div class="content-grid content-grid--sidebar">
  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title"><?= $form['id'] > 0 ? 'Editar produto' : 'Novo produto' ?></h2>
        <p class="card-copy"><?= $form['id'] > 0 ? 'Atualize o item e salve para refletir no bot.' : 'Cadastre planos de grupo e packs com entrega por link.' ?></p>
      </div>
      <?php if ($form['id'] > 0): ?>
        <a href="<?= admin_url('produtos.php') ?>" class="btn btn-ghost btn-sm">Cancelar edicao</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST" class="stack">
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

        <div class="form-group">
          <label class="form-label" for="nome">Nome do produto</label>
          <input class="form-control" id="nome" type="text" name="nome" value="<?= htmlspecialchars((string) $form['nome']) ?>" placeholder="Ex: Acesso VIP 30 dias ou Pack Creatives Abril" required>
        </div>

        <div class="form-group">
          <label class="form-label" for="descricao">Descricao</label>
          <textarea class="form-control" id="descricao" name="descricao" placeholder="Texto curto exibido no bot e usado para organizar melhor o catalogo."><?= htmlspecialchars((string) $form['descricao']) ?></textarea>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="tipo">Tipo de produto</label>
            <select class="form-control" id="tipo" name="tipo">
              <option value="grupo" <?= $form['tipo'] === 'grupo' ? 'selected' : '' ?>>Plano de grupo</option>
              <option value="pack" <?= $form['tipo'] === 'pack' ? 'selected' : '' ?>>Pack com entrega por link</option>
            </select>
            <span class="form-help">Plano libera acesso no grupo. Pack entrega um link depois do Pix pago.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="valor">Valor (R$)</label>
            <input class="form-control" id="valor" type="number" step="0.01" min="0.01" name="valor" value="<?= htmlspecialchars((string) $form['valor']) ?>" placeholder="29.90" required>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label" for="dias_acesso">Duracao em dias</label>
            <input class="form-control" id="dias_acesso" type="number" min="0" name="dias_acesso" value="<?= (int) $form['dias_acesso'] ?>">
            <span class="form-help" id="dias_acesso_help">Use esse campo para definir quantos dias de acesso serao liberados no grupo.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="ordem">Ordem de exibicao</label>
            <input class="form-control" id="ordem" type="number" min="0" name="ordem" value="<?= (int) $form['ordem'] ?>">
            <span class="form-help">Quanto menor o numero, antes o item aparece no bot.</span>
          </div>
        </div>

        <div class="form-group" id="pack_link_group">
          <label class="form-label" for="pack_link">Link do pack</label>
          <input class="form-control" id="pack_link" type="url" name="pack_link" value="<?= htmlspecialchars((string) $form['pack_link']) ?>" placeholder="https://seu-dominio.com/meu-pack ou link do drive">
          <span class="form-help">Esse link sera enviado automaticamente para o cliente apos o pagamento confirmado.</span>
        </div>

        <div class="form-group">
          <label class="form-label">Status</label>
          <label class="checkbox-row">
            <input type="checkbox" name="ativo" <?= (int) $form['ativo'] === 1 ? 'checked' : '' ?>>
            <span>Produto ativo para venda</span>
          </label>
        </div>

        <div class="form-group">
          <label class="form-label">Onde esse produto pode aparecer</label>
          <div class="stack" style="gap: 10px;">
            <label class="checkbox-row">
              <input type="checkbox" name="mostrar_catalogo" <?= (int) $form['mostrar_catalogo'] === 1 ? 'checked' : '' ?>>
              <span>Mostrar no catalogo principal do bot</span>
            </label>
            <label class="checkbox-row">
              <input type="checkbox" name="permitir_orderbump" <?= (int) $form['permitir_orderbump'] === 1 ? 'checked' : '' ?>>
              <span>Permitir como produto de order bump</span>
            </label>
            <label class="checkbox-row">
              <input type="checkbox" name="permitir_upsell" <?= (int) $form['permitir_upsell'] === 1 ? 'checked' : '' ?>>
              <span>Permitir como produto de upsell</span>
            </label>
            <label class="checkbox-row">
              <input type="checkbox" name="permitir_downsell" <?= (int) $form['permitir_downsell'] === 1 ? 'checked' : '' ?>>
              <span>Permitir como produto de downsell</span>
            </label>
          </div>
          <span class="form-help">Assim voce pode esconder um produto do menu principal e deixar ele disponivel apenas nas ofertas internas.</span>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><?= $form['id'] > 0 ? 'Salvar alteracoes' : 'Criar produto' ?></button>
          <?php if ($form['id'] > 0): ?>
            <a href="<?= admin_url('produtos.php') ?>" class="btn btn-ghost">Limpar formulario</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Catalogo cadastrado</h2>
        <p class="card-copy">Use editar para carregar o item no formulario ao lado.</p>
      </div>
    </div>
    <div class="card-body" style="padding-top: 0;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Produto</th>
              <th>Tipo</th>
              <th>Valor</th>
              <th>Entrega</th>
              <th>Uso</th>
              <th>Ordem</th>
              <th>Status</th>
              <th>Acoes</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$produtos): ?>
              <tr>
                <td colspan="9" class="empty-state">Nenhum produto cadastrado ainda.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($produtos as $produto): ?>
              <?php $tipoProduto = produto_tipo($produto); ?>
              <tr>
                <td class="mono"><?= (int) $produto['id'] ?></td>
                <td>
                  <strong><?= htmlspecialchars((string) $produto['nome']) ?></strong>
                  <?php if (!empty($produto['descricao'])): ?>
                    <div class="text-muted"><?= htmlspecialchars((string) $produto['descricao']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= $tipoProduto === 'pack' ? 'Pack' : 'Grupo' ?></td>
                <td class="mono">R$ <?= number_format((float) $produto['valor'], 2, ',', '.') ?></td>
                <td>
                  <?php if ($tipoProduto === 'pack'): ?>
                    <?php $packLink = produto_pack_link($produto); ?>
                    <?= $packLink !== '' ? 'Link direto' : 'Sem link' ?>
                    <?php if ($packLink !== ''): ?>
                      <?php $packPreview = strlen($packLink) > 42 ? substr($packLink, 0, 39) . '...' : $packLink; ?>
                      <div class="text-muted mono"><?= htmlspecialchars($packPreview) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <?= (int) ($produto['dias_acesso'] ?? 0) ?> dias no grupo
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                    $uso = [];
                    if (produto_mostrar_catalogo($produto)) {
                        $uso[] = 'Catalogo';
                    }
                    if (produto_permite_orderbump($produto)) {
                        $uso[] = 'Order bump';
                    }
                    if (produto_permite_upsell($produto)) {
                        $uso[] = 'Upsell';
                    }
                    if (produto_permite_downsell($produto)) {
                        $uso[] = 'Downsell';
                    }
                  ?>
                  <?= $uso ? htmlspecialchars(implode(' / ', $uso)) : 'Sem uso liberado' ?>
                </td>
                <td class="mono"><?= (int) $produto['ordem'] ?></td>
                <td>
                  <form method="POST" class="inline-form">
                    <input type="hidden" name="acao" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $produto['id'] ?>">
                    <input type="hidden" name="ativo" value="<?= (int) $produto['ativo'] ?>">
                    <button type="submit" class="btn btn-sm <?= (int) $produto['ativo'] === 1 ? 'btn-secondary' : 'btn-ghost' ?>">
                      <?= (int) $produto['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                    </button>
                  </form>
                </td>
                <td>
                  <div class="actions">
                    <a href="<?= admin_url('produtos.php?editar=' . (int) $produto['id']) ?>" class="btn btn-ghost btn-sm">Editar</a>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Excluir este produto?');">
                      <input type="hidden" name="acao" value="excluir">
                      <input type="hidden" name="id" value="<?= (int) $produto['id'] ?>">
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
  const tipoSelect = document.getElementById('tipo');
  const packField = document.getElementById('pack_link_group');
  const packInput = document.getElementById('pack_link');
  const diasInput = document.getElementById('dias_acesso');
  const diasHelp = document.getElementById('dias_acesso_help');

  if (!tipoSelect || !packField || !packInput || !diasInput || !diasHelp) {
    return;
  }

  const syncForm = () => {
    const isPack = tipoSelect.value === 'pack';
    packField.style.display = isPack ? 'block' : 'none';
    packInput.required = isPack;
    diasHelp.textContent = isPack
      ? 'Para pack, esse campo nao e usado e sera salvo como 0.'
      : 'Use esse campo para definir quantos dias de acesso serao liberados no grupo.';

    if (isPack) {
      diasInput.value = '0';
    } else if (diasInput.value === '0') {
      diasInput.value = '30';
    }
  };

  tipoSelect.addEventListener('change', syncForm);
  syncForm();
})();
</script>

<?php include '_footer.php'; ?>
