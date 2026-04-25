<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();
$pdo = db();

$filtroTipo = trim((string) ($_GET['tipo'] ?? ''));
$busca = trim((string) ($_GET['q'] ?? ''));
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 50;
$offset = ($pagina - 1) * $porPagina;

$where = ['1=1', tenant_scope_condition('logs')];
$params = [];

if ($filtroTipo !== '') {
    $where[] = 'tipo = ?';
    $params[] = $filtroTipo;
}

if ($busca !== '') {
    $where[] = 'mensagem ' . db_like_operator() . ' ?';
    $params[] = '%' . $busca . '%';
}

$whereSql = implode(' AND ', $where);

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM logs WHERE $whereSql");
$stmtTotal->execute($params);
$totalRows = (int) $stmtTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalRows / $porPagina));

$stmt = $pdo->prepare("SELECT * FROM logs WHERE $whereSql ORDER BY created_at DESC LIMIT $porPagina OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$tipos = $pdo->query('SELECT DISTINCT tipo FROM logs WHERE ' . tenant_scope_condition('logs') . ' ORDER BY tipo')->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Logs';
$page_subtitle = $totalRows . ' evento(s) encontrado(s)';
$active_menu = 'logs';
include '_layout.php';
?>

<section class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Filtros</h2>
      <p class="card-copy">Busque mensagens e tipos de evento gravados no sistema.</p>
    </div>
  </div>
  <div class="card-body">
    <form method="GET" class="toolbar">
      <input class="form-control" type="text" name="q" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar na mensagem do log">
      <select class="form-control" name="tipo" style="max-width: 240px;">
        <option value="">Todos os tipos</option>
        <?php foreach ($tipos as $tipo): ?>
          <option value="<?= htmlspecialchars((string) $tipo) ?>" <?= $filtroTipo === (string) $tipo ? 'selected' : '' ?>>
            <?= htmlspecialchars((string) $tipo) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">Filtrar</button>
      <a href="<?= admin_url('logs.php') ?>" class="btn btn-ghost">Limpar</a>
    </form>
  </div>
</section>

<section class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Eventos registrados</h2>
      <p class="card-copy">Historico tecnico para acompanhar login, pagamentos, webhooks e acoes do painel.</p>
    </div>
  </div>
  <div class="card-body" style="padding-top: 0;">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Quando</th>
            <th>Tipo</th>
            <th>Mensagem</th>
            <th>Dados</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$logs): ?>
            <tr>
              <td colspan="4" class="empty-state">Nenhum log encontrado para os filtros escolhidos.</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($logs as $log): ?>
            <?php
            $tipo = (string) $log['tipo'];
            $badge = match ($tipo) {
                'pix_processado', 'admin_login', 'admin_ativar' => 'badge-green',
                'pix_gerado' => 'badge-blue',
                'admin_remover' => 'badge-yellow',
                'db_error', 'webhook_pix_auth_falhou', 'admin_excluir' => 'badge-red',
                default => 'badge-gray',
            };
            $jsonFormatado = '';
            if (!empty($log['dados'])) {
                $decoded = json_decode((string) $log['dados'], true);
                $jsonFormatado = $decoded !== null
                    ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    : (string) $log['dados'];
            }
            ?>
            <tr>
              <td class="mono"><?= date('d/m/Y H:i:s', strtotime((string) $log['created_at'])) ?></td>
              <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($tipo) ?></span></td>
              <td><?= htmlspecialchars((string) $log['mensagem']) ?></td>
              <td>
                <?php if ($jsonFormatado !== ''): ?>
                  <details>
                    <summary class="text-muted">Ver JSON</summary>
                    <pre class="code-box" style="margin-top: 10px; white-space: pre-wrap;"><?= htmlspecialchars($jsonFormatado) ?></pre>
                  </details>
                <?php else: ?>
                  <span class="text-muted">Sem dados extras</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($totalPaginas > 1): ?>
    <div class="pagination">
      <span class="page-info">Pagina <?= $pagina ?> de <?= $totalPaginas ?></span>
      <?php for ($i = max(1, $pagina - 3); $i <= min($totalPaginas, $pagina + 3); $i++): ?>
        <a
          href="?p=<?= $i ?>&tipo=<?= urlencode($filtroTipo) ?>&q=<?= urlencode($busca) ?>"
          class="page-link <?= $i === $pagina ? 'active' : '' ?>"
        ><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</section>

<?php include '_footer.php'; ?>
