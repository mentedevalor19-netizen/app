<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();
$pdo = db();

$filtroStatus = trim((string) ($_GET['status'] ?? ''));
$filtroMes = trim((string) ($_GET['mes'] ?? ''));
$busca = trim((string) ($_GET['q'] ?? ''));
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 25;
$offset = ($pagina - 1) * $porPagina;

$where = ['1=1', tenant_scope_condition('pagamentos', 'p')];
$params = [];

if ($filtroStatus !== '') {
    $where[] = 'p.status = ?';
    $params[] = $filtroStatus;
}

if ($filtroMes !== '') {
    $inicioMes = date('Y-m-01 00:00:00', strtotime($filtroMes . '-01'));
    $inicioProximoMes = date('Y-m-01 00:00:00', strtotime($filtroMes . '-01 +1 month'));
    $where[] = 'p.created_at >= ? AND p.created_at < ?';
    $params[] = $inicioMes;
    $params[] = $inicioProximoMes;
}

if ($busca !== '') {
    $like = db_like_operator();
    $where[] = "(u.first_name {$like} ? OR u.username {$like} ? OR CAST(u.telegram_id AS TEXT) {$like} ? OR p.txid {$like} ?)";
    $params[] = '%' . $busca . '%';
    $params[] = '%' . $busca . '%';
    $params[] = '%' . $busca . '%';
    $params[] = '%' . $busca . '%';
}

$whereSql = implode(' AND ', $where);

$temProdutoId = db_has_column('pagamentos', 'produto_id');
$temFunilId = db_has_column('pagamentos', 'funil_id');
$temTipoOferta = db_has_column('pagamentos', 'tipo_oferta');
$temTabelaProdutos = db_has_table('produtos');
$temTabelaFunis = db_has_table('funis');

$joins = [];
$fields = [
    'p.*',
    'u.first_name',
    'u.username',
    'u.telegram_id',
];
$userJoinScope = tenant_scope_condition('usuarios', 'u');
$productJoinScope = tenant_scope_condition('produtos', 'pr');
$funilJoinScope = tenant_scope_condition('funis', 'f');

if ($temProdutoId && $temTabelaProdutos) {
    $joins[] = 'LEFT JOIN produtos pr ON pr.id = p.produto_id AND ' . $productJoinScope;
    $fields[] = 'pr.nome AS produto_nome';
} else {
    $fields[] = 'NULL AS produto_nome';
}

if ($temFunilId && $temTabelaFunis) {
    $joins[] = 'LEFT JOIN funis f ON f.id = p.funil_id AND ' . $funilJoinScope;
    $fields[] = 'f.nome AS funil_nome';
} else {
    $fields[] = 'NULL AS funil_nome';
}

$sqlTotal = "SELECT COUNT(*) FROM pagamentos p JOIN usuarios u ON u.id = p.usuario_id AND $userJoinScope WHERE $whereSql";
$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute($params);
$totalRows = (int) $stmtTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalRows / $porPagina));

$sqlTotais = "SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN p.status = 'pago' THEN p.valor ELSE 0 END) AS receita,
    SUM(CASE WHEN p.status = 'pago' THEN 1 ELSE 0 END) AS pagos,
    SUM(CASE WHEN p.status = 'pendente' THEN 1 ELSE 0 END) AS pendentes
  FROM pagamentos p
  JOIN usuarios u ON u.id = p.usuario_id AND $userJoinScope
  WHERE $whereSql";
$stmtTotais = $pdo->prepare($sqlTotais);
$stmtTotais->execute($params);
$totais = $stmtTotais->fetch() ?: ['total' => 0, 'receita' => 0, 'pagos' => 0, 'pendentes' => 0];

$sql = "SELECT " . implode(', ', $fields) . "
  FROM pagamentos p
  JOIN usuarios u ON u.id = p.usuario_id AND $userJoinScope
  " . implode("\n", $joins) . "
  WHERE $whereSql
  ORDER BY p.created_at DESC
  LIMIT $porPagina OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pagamentos = $stmt->fetchAll();

$page_title = 'Pagamentos';
$page_subtitle = $totalRows . ' transacao(oes) encontrada(s)';
$active_menu = 'pagamentos';
include '_layout.php';
?>

<div class="stats-grid">
  <section class="card stat-card">
    <div class="stat-label">Receita filtrada</div>
    <div class="stat-value">R$ <?= number_format((float) ($totais['receita'] ?? 0), 2, ',', '.') ?></div>
    <div class="stat-subtitle"><?= (int) ($totais['pagos'] ?? 0) ?> pagamento(s) confirmados</div>
  </section>

  <section class="card stat-card">
    <div class="stat-label">Total de transacoes</div>
    <div class="stat-value"><?= (int) ($totais['total'] ?? 0) ?></div>
    <div class="stat-subtitle"><?= (int) ($totais['pendentes'] ?? 0) ?> pendente(s)</div>
  </section>
</div>

<section class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Filtros</h2>
      <p class="card-copy">Filtre pagamentos por status, mes ou dados do usuario.</p>
    </div>
  </div>
  <div class="card-body">
    <form method="GET" class="toolbar">
      <input class="form-control" type="text" name="q" value="<?= htmlspecialchars($busca) ?>" placeholder="Nome, username, Telegram ID ou txid">
      <select class="form-control" name="status" style="max-width: 220px;">
        <option value="">Todos os status</option>
        <option value="pago" <?= $filtroStatus === 'pago' ? 'selected' : '' ?>>Pago</option>
        <option value="pendente" <?= $filtroStatus === 'pendente' ? 'selected' : '' ?>>Pendente</option>
        <option value="expirado" <?= $filtroStatus === 'expirado' ? 'selected' : '' ?>>Expirado</option>
        <option value="cancelado" <?= $filtroStatus === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
      </select>
      <input class="form-control" type="month" name="mes" value="<?= htmlspecialchars($filtroMes) ?>" style="max-width: 180px;">
      <button type="submit" class="btn btn-primary">Filtrar</button>
      <a href="<?= admin_url('pagamentos.php') ?>" class="btn btn-ghost">Limpar</a>
    </form>
  </div>
</section>

<section class="card">
  <div class="card-header">
    <div>
      <h2 class="card-title">Historico de pagamentos</h2>
      <p class="card-copy">Acompanhamento dos pagamentos e dos planos vendidos.</p>
    </div>
  </div>
  <div class="card-body" style="padding-top: 0;">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Usuario</th>
            <th>Plano</th>
            <?php if ($temFunilId): ?><th>Funil</th><?php endif; ?>
            <?php if ($temTipoOferta): ?><th>Oferta</th><?php endif; ?>
            <th>Valor</th>
            <th>Status</th>
            <th>TXID</th>
            <th>Criado em</th>
            <th>Pago em</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$pagamentos): ?>
            <tr>
              <td colspan="<?= 7 + ($temFunilId ? 1 : 0) + ($temTipoOferta ? 1 : 0) + 1 ?>" class="empty-state">Nenhum pagamento encontrado para os filtros aplicados.</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($pagamentos as $pagamento): ?>
            <?php
            $status = (string) ($pagamento['status'] ?? '');
            $badge = match ($status) {
                'pago' => 'badge-green',
                'pendente' => 'badge-yellow',
                'cancelado' => 'badge-red',
                default => 'badge-gray',
            };
            $usuarioNome = trim((string) ($pagamento['first_name'] ?? ''));
            if ($usuarioNome === '') {
                $usuarioNome = $pagamento['username'] ? '@' . $pagamento['username'] : 'Usuario sem nome';
            }
            $txidCompleto = (string) ($pagamento['txid'] ?? '');
            $txidCurto = strlen($txidCompleto) > 18 ? substr($txidCompleto, 0, 18) . '...' : $txidCompleto;
            ?>
            <tr>
              <td class="mono"><?= (int) $pagamento['id'] ?></td>
              <td>
                <strong><?= htmlspecialchars($usuarioNome) ?></strong>
                <div class="text-muted mono"><?= htmlspecialchars((string) $pagamento['telegram_id']) ?></div>
              </td>
              <td><?= htmlspecialchars((string) ($pagamento['produto_nome'] ?? 'Plano nao identificado')) ?></td>
              <?php if ($temFunilId): ?>
                <td><?= htmlspecialchars((string) ($pagamento['funil_nome'] ?? '-')) ?></td>
              <?php endif; ?>
              <?php if ($temTipoOferta): ?>
                <td><?= htmlspecialchars((string) ucfirst((string) ($pagamento['tipo_oferta'] ?? 'principal'))) ?></td>
              <?php endif; ?>
              <td class="mono">R$ <?= number_format((float) $pagamento['valor'], 2, ',', '.') ?></td>
              <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($status) ?></span></td>
              <td class="mono" title="<?= htmlspecialchars($txidCompleto) ?>"><?= htmlspecialchars($txidCurto) ?></td>
              <td class="mono"><?= !empty($pagamento['created_at']) ? date('d/m/Y H:i', strtotime((string) $pagamento['created_at'])) : '-' ?></td>
              <td class="mono"><?= !empty($pagamento['paid_at']) ? date('d/m/Y H:i', strtotime((string) $pagamento['paid_at'])) : '-' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($totalPaginas > 1): ?>
    <div class="pagination">
      <span class="page-info">Pagina <?= $pagina ?> de <?= $totalPaginas ?></span>
      <?php for ($i = max(1, $pagina - 2); $i <= min($totalPaginas, $pagina + 2); $i++): ?>
        <a
          href="?p=<?= $i ?>&status=<?= urlencode($filtroStatus) ?>&mes=<?= urlencode($filtroMes) ?>&q=<?= urlencode($busca) ?>"
          class="page-link <?= $i === $pagina ? 'active' : '' ?>"
        ><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</section>

<?php include '_footer.php'; ?>
