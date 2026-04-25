<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();
$pdo = db();
$agora = db_now();
$hojeInicio = date('Y-m-d 00:00:00');
$amanhaInicio = date('Y-m-d 00:00:00', strtotime('+1 day'));
$inicioMes = date('Y-m-01 00:00:00');
$inicioProximoMes = date('Y-m-01 00:00:00', strtotime('+1 month'));
$expiraEmTresDias = date('Y-m-d H:i:s', strtotime('+3 days'));
$userScope = tenant_scope_condition('usuarios');
$paymentScope = tenant_scope_condition('pagamentos');
$recentPaymentScope = tenant_scope_condition('pagamentos', 'p');
$recentUserScope = tenant_scope_condition('usuarios', 'u');

$totalUsuarios = (int) $pdo->query('SELECT COUNT(*) FROM usuarios WHERE ' . $userScope)->fetchColumn();
$stmtUsuariosAtivos = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE $userScope AND status = 'ativo' AND (data_expiracao IS NULL OR data_expiracao > ?)");
$stmtUsuariosAtivos->execute([$agora]);
$usuariosAtivos = (int) $stmtUsuariosAtivos->fetchColumn();
$stmtExpirandoHoje = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE $userScope AND status = 'ativo' AND data_expiracao >= ? AND data_expiracao < ?");
$stmtExpirandoHoje->execute([$hojeInicio, $amanhaInicio]);
$usuariosExpirandoHoje = (int) $stmtExpirandoHoje->fetchColumn();
$pagamentosConfirmados = (int) $pdo->query("SELECT COUNT(*) FROM pagamentos WHERE $paymentScope AND status = 'pago'")->fetchColumn();
$pagamentosPendentes = (int) $pdo->query("SELECT COUNT(*) FROM pagamentos WHERE $paymentScope AND status = 'pendente'")->fetchColumn();
$receitaTotal = (float) $pdo->query("SELECT COALESCE(SUM(valor), 0) FROM pagamentos WHERE $paymentScope AND status = 'pago'")->fetchColumn();
$stmtReceitaMes = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM pagamentos WHERE $paymentScope AND status = 'pago' AND paid_at >= ? AND paid_at < ?");
$stmtReceitaMes->execute([$inicioMes, $inicioProximoMes]);
$receitaMes = (float) $stmtReceitaMes->fetchColumn();

$ultimosPagamentos = $pdo->query(
    "SELECT p.*, u.first_name, u.telegram_id
     FROM pagamentos p
     JOIN usuarios u ON u.id = p.usuario_id AND $recentUserScope
     WHERE $recentPaymentScope
     ORDER BY p.created_at DESC
     LIMIT 8"
)->fetchAll();

$stmtUsuariosExpirando = $pdo->prepare(
    "SELECT *
     FROM usuarios
     WHERE $userScope
       AND status = 'ativo'
       AND data_expiracao BETWEEN ? AND ?
     ORDER BY data_expiracao ASC
     LIMIT 10"
);
$stmtUsuariosExpirando->execute([$agora, $expiraEmTresDias]);
$usuariosExpirando = $stmtUsuariosExpirando->fetchAll();

$page_title = 'Dashboard';
$page_subtitle = 'Visao geral do sistema';
$active_menu = 'dashboard';
include '_layout.php';
?>

<div class="stats-grid">
  <section class="card stat-card">
    <div class="stat-label">Usuarios ativos</div>
    <div class="stat-value"><?= $usuariosAtivos ?></div>
    <div class="stat-subtitle"><?= $totalUsuarios ?> usuario(s) cadastrados no total</div>
  </section>

  <section class="card stat-card">
    <div class="stat-label">Receita do mes</div>
    <div class="stat-value">R$ <?= number_format($receitaMes, 2, ',', '.') ?></div>
    <div class="stat-subtitle">Receita total: R$ <?= number_format($receitaTotal, 2, ',', '.') ?></div>
  </section>

  <section class="card stat-card">
    <div class="stat-label">Pagamentos</div>
    <div class="stat-value"><?= $pagamentosConfirmados ?></div>
    <div class="stat-subtitle"><?= $pagamentosPendentes ?> pagamento(s) pendentes</div>
  </section>

  <section class="card stat-card">
    <div class="stat-label">Expiram hoje</div>
    <div class="stat-value"><?= $usuariosExpirandoHoje ?></div>
    <div class="stat-subtitle">Usuarios que precisam renovar ainda hoje</div>
  </section>

</div>

<div class="content-grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));">
  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Ultimos pagamentos</h2>
        <p class="card-copy">Visao rapida das cobrancas criadas recentemente.</p>
      </div>
      <a href="<?= admin_url('pagamentos.php') ?>" class="btn btn-ghost btn-sm">Ver todos</a>
    </div>
    <div class="card-body" style="padding-top: 0;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Usuario</th>
              <th>Valor</th>
              <th>Status</th>
              <th>Data</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$ultimosPagamentos): ?>
              <tr>
                <td colspan="4" class="empty-state">Nenhum pagamento registrado ainda.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($ultimosPagamentos as $pagamento): ?>
              <?php
              $badge = match ((string) $pagamento['status']) {
                  'pago' => 'badge-green',
                  'pendente' => 'badge-yellow',
                  'cancelado' => 'badge-red',
                  default => 'badge-gray',
              };
              ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars((string) ($pagamento['first_name'] ?: 'Sem nome')) ?></strong>
                  <div class="text-muted mono"><?= htmlspecialchars((string) $pagamento['telegram_id']) ?></div>
                </td>
                <td class="mono">R$ <?= number_format((float) $pagamento['valor'], 2, ',', '.') ?></td>
                <td><span class="badge <?= $badge ?>"><?= htmlspecialchars((string) $pagamento['status']) ?></span></td>
                <td class="mono"><?= !empty($pagamento['created_at']) ? date('d/m H:i', strtotime((string) $pagamento['created_at'])) : '-' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Usuarios expirando em 3 dias</h2>
        <p class="card-copy">Bom momento para mandar lembrete e renovar o acesso.</p>
      </div>
      <a href="<?= admin_url('usuarios.php') ?>" class="btn btn-ghost btn-sm">Ver usuarios</a>
    </div>
    <div class="card-body" style="padding-top: 0;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Usuario</th>
              <th>Telegram ID</th>
              <th>Expira em</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$usuariosExpirando): ?>
              <tr>
                <td colspan="3" class="empty-state">Nenhum usuario expirando nos proximos 3 dias.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($usuariosExpirando as $usuario): ?>
              <tr>
                <td><?= htmlspecialchars((string) ($usuario['first_name'] ?: 'Sem nome')) ?></td>
                <td class="mono"><?= htmlspecialchars((string) $usuario['telegram_id']) ?></td>
                <td class="mono"><?= !empty($usuario['data_expiracao']) ? date('d/m/Y H:i', strtotime((string) $usuario['data_expiracao'])) : '-' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<?php include '_footer.php'; ?>
