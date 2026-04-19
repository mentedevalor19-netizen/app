<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();
$pdo = db();
$msg = null;
$temCpf = db_has_column('usuarios', 'cpf');
$canManageUsers = ($current_admin['nivel'] ?? '') !== 'viewer';

$usuarioAtivar = null;
$ativarId = (int) ($_GET['ativar'] ?? 0);

if ($ativarId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$ativarId]);
    $usuarioAtivar = $stmt->fetch();

    if (!$usuarioAtivar) {
        $msg = ['tipo' => 'warning', 'texto' => 'O usuario selecionado nao foi encontrado.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin($current_admin);

    $acao = (string) ($_POST['acao'] ?? '');
    $usuarioId = (int) ($_POST['usuario_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$usuarioId]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        $msg = ['tipo' => 'danger', 'texto' => 'Usuario nao encontrado para esta acao.'];
    } else {
        if ($acao === 'ativar') {
            $dias = max(1, (int) ($_POST['dias'] ?? DIAS_ACESSO));
            $expiraEm = date('Y-m-d H:i:s', strtotime('+' . $dias . ' days'));

            $pdo->prepare("UPDATE usuarios SET status = 'ativo', data_expiracao = ? WHERE id = ?")->execute([$expiraEm, $usuarioId]);

            $link = gerar_link_convite();
            if ($link) {
                $pdo->prepare('UPDATE usuarios SET grupo_adicionado = 1 WHERE id = ?')->execute([$usuarioId]);
                enviar_mensagem(
                    (int) $usuario['telegram_id'],
                    render_template(message_template('msg_manual_activation'), [
                        'dias' => $dias,
                        'convite' => $link,
                    ])
                );
            }

            log_evento('admin_ativar', "Admin #{$current_admin['id']} ativou usuario #{$usuarioId} por {$dias} dias");
            header('Location: ' . admin_url('usuarios.php?ok=ativado'));
            exit;
        }

        if ($acao === 'remover') {
            remover_do_grupo((int) $usuario['telegram_id']);
            expirar_usuario($usuarioId);
            enviar_mensagem((int) $usuario['telegram_id'], message_template('msg_admin_removed'));
            log_evento('admin_remover', "Admin #{$current_admin['id']} removeu usuario #{$usuarioId} do grupo");
            header('Location: ' . admin_url('usuarios.php?ok=removido'));
            exit;
        }

        if ($acao === 'excluir') {
            remover_do_grupo((int) $usuario['telegram_id']);
            $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$usuarioId]);
            log_evento('admin_excluir', "Admin #{$current_admin['id']} excluiu usuario #{$usuarioId}");
            header('Location: ' . admin_url('usuarios.php?ok=excluido'));
            exit;
        }
    }
}

$filtroStatus = trim((string) ($_GET['status'] ?? ''));
$busca = trim((string) ($_GET['q'] ?? ''));
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 20;
$offset = ($pagina - 1) * $porPagina;

$where = [];
$params = [];

if ($filtroStatus !== '') {
    $where[] = 'status = ?';
    $params[] = $filtroStatus;
}

if ($busca !== '') {
    $like = db_like_operator();
    $where[] = $temCpf
        ? "(first_name {$like} ? OR username {$like} ? OR CAST(telegram_id AS TEXT) {$like} ? OR cpf {$like} ?)"
        : "(first_name {$like} ? OR username {$like} ? OR CAST(telegram_id AS TEXT) {$like} ?)";
    $params[] = '%' . $busca . '%';
    $params[] = '%' . $busca . '%';
    $params[] = '%' . $busca . '%';
    if ($temCpf) {
        $params[] = '%' . $busca . '%';
    }
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM usuarios $whereSql");
$stmtTotal->execute($params);
$totalRows = (int) $stmtTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalRows / $porPagina));

$stmtUsuarios = $pdo->prepare("SELECT * FROM usuarios $whereSql ORDER BY created_at DESC LIMIT $porPagina OFFSET $offset");
$stmtUsuarios->execute($params);
$usuarios = $stmtUsuarios->fetchAll();

$page_title = 'Usuarios';
$page_subtitle = $totalRows . ' usuario(s) encontrado(s)';
$active_menu = 'usuarios';
include '_layout.php';
?>

<?php if ($msg): ?>
  <div class="alert alert-<?= htmlspecialchars($msg['tipo']) ?>"><?= htmlspecialchars($msg['texto']) ?></div>
<?php endif; ?>

<div class="content-grid content-grid--sidebar">
  <section class="stack">
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Filtros</h2>
          <p class="card-copy">Busque por nome, username, Telegram ID ou CPF.</p>
        </div>
      </div>
      <div class="card-body">
        <form method="GET" class="stack">
          <div class="form-group">
            <label class="form-label" for="q">Busca</label>
            <input class="form-control" id="q" type="text" name="q" value="<?= htmlspecialchars($busca) ?>" placeholder="Digite nome, ID ou CPF">
          </div>

          <div class="form-group">
            <label class="form-label" for="status">Status</label>
            <select class="form-control" id="status" name="status">
              <option value="">Todos</option>
              <option value="ativo" <?= $filtroStatus === 'ativo' ? 'selected' : '' ?>>Ativo</option>
              <option value="pendente" <?= $filtroStatus === 'pendente' ? 'selected' : '' ?>>Pendente</option>
              <option value="expirado" <?= $filtroStatus === 'expirado' ? 'selected' : '' ?>>Expirado</option>
            </select>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <a href="<?= admin_url('usuarios.php') ?>" class="btn btn-ghost">Limpar</a>
          </div>
        </form>
      </div>
    </article>

    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title"><?= $usuarioAtivar ? 'Ativacao manual' : 'Ativacao manual pronta' ?></h2>
          <p class="card-copy">
            <?php if (!$canManageUsers): ?>
              Sua conta esta em modo somente leitura.
            <?php else: ?>
              <?= $usuarioAtivar ? 'Revise os dados abaixo e envie um convite novo para o usuario.' : 'Clique em "Ativar" na tabela para carregar um usuario aqui.' ?>
            <?php endif; ?>
          </p>
        </div>
        <?php if ($usuarioAtivar && $canManageUsers): ?>
          <a href="<?= admin_url('usuarios.php') ?>" class="btn btn-ghost btn-sm">Cancelar</a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!$canManageUsers): ?>
          <div class="empty-state">Acoes de gestao ficam disponiveis apenas para administradores.</div>
        <?php elseif ($usuarioAtivar): ?>
          <form method="POST" class="stack">
            <input type="hidden" name="acao" value="ativar">
            <input type="hidden" name="usuario_id" value="<?= (int) $usuarioAtivar['id'] ?>">

            <div class="split-value">
              <span class="text-muted">Usuario</span>
              <strong><?= htmlspecialchars((string) ($usuarioAtivar['first_name'] ?: $usuarioAtivar['telegram_id'])) ?></strong>
            </div>
            <div class="split-value">
              <span class="text-muted">Telegram ID</span>
              <span class="mono"><?= htmlspecialchars((string) $usuarioAtivar['telegram_id']) ?></span>
            </div>
            <div class="split-value">
              <span class="text-muted">Status atual</span>
              <span><?= htmlspecialchars((string) $usuarioAtivar['status']) ?></span>
            </div>

            <div class="form-group">
              <label class="form-label" for="dias">Duracao em dias</label>
              <input class="form-control" id="dias" type="number" min="1" max="3650" name="dias" value="30" required>
              <span class="form-help">Ao salvar, o sistema envia um novo convite automaticamente.</span>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn btn-primary">Ativar e enviar convite</button>
            </div>
          </form>
        <?php else: ?>
          <div class="empty-state">Nenhum usuario selecionado para ativacao manual.</div>
        <?php endif; ?>
      </div>
    </article>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Usuarios cadastrados</h2>
        <p class="card-copy">Gerencie acesso, remocao do grupo e exclusao de registros.</p>
      </div>
    </div>
    <div class="card-body" style="padding-top: 0;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Usuario</th>
              <th>Telegram ID</th>
              <?php if ($temCpf): ?><th>CPF</th><?php endif; ?>
              <th>Status</th>
              <th>Expira em</th>
              <th>Grupo</th>
              <th>Cadastro</th>
              <th>Acoes</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$usuarios): ?>
              <tr>
                <td colspan="<?= $temCpf ? '9' : '8' ?>" class="empty-state">Nenhum usuario encontrado.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($usuarios as $usuario): ?>
              <?php
              $statusAtual = (string) $usuario['status'];
              $expirado = !empty($usuario['data_expiracao']) && strtotime((string) $usuario['data_expiracao']) < time();
              $badge = match ($statusAtual) {
                  'ativo' => $expirado ? 'badge-red' : 'badge-green',
                  'pendente' => 'badge-yellow',
                  default => 'badge-gray',
              };
              $labelStatus = $statusAtual === 'ativo' && $expirado ? 'vencido' : $statusAtual;
              ?>
              <tr>
                <td class="mono"><?= (int) $usuario['id'] ?></td>
                <td>
                  <strong><?= htmlspecialchars((string) ($usuario['first_name'] ?: 'Sem nome')) ?></strong>
                  <?php if (!empty($usuario['username'])): ?>
                    <div class="text-muted">@<?= htmlspecialchars((string) $usuario['username']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="mono"><?= htmlspecialchars((string) $usuario['telegram_id']) ?></td>
                <?php if ($temCpf): ?><td class="mono"><?= htmlspecialchars((string) ($usuario['cpf'] ?? '-')) ?></td><?php endif; ?>
                <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($labelStatus) ?></span></td>
                <td class="mono"><?= !empty($usuario['data_expiracao']) ? date('d/m/Y H:i', strtotime((string) $usuario['data_expiracao'])) : '-' ?></td>
                <td><?= (int) ($usuario['grupo_adicionado'] ?? 0) === 1 ? 'Sim' : 'Nao' ?></td>
                <td class="mono"><?= !empty($usuario['created_at']) ? date('d/m/Y', strtotime((string) $usuario['created_at'])) : '-' ?></td>
                <td>
                  <div class="actions">
                    <?php if ($canManageUsers): ?>
                      <a href="<?= admin_url('usuarios.php?ativar=' . (int) $usuario['id']) ?>" class="btn btn-ghost btn-sm">Ativar</a>
                      <?php if ((int) ($usuario['grupo_adicionado'] ?? 0) === 1): ?>
                        <form method="POST" class="inline-form" onsubmit="return confirm('Remover este usuario do grupo?');">
                          <input type="hidden" name="acao" value="remover">
                          <input type="hidden" name="usuario_id" value="<?= (int) $usuario['id'] ?>">
                          <button type="submit" class="btn btn-secondary btn-sm">Remover</button>
                        </form>
                      <?php endif; ?>
                      <form method="POST" class="inline-form" onsubmit="return confirm('Excluir este usuario permanentemente?');">
                        <input type="hidden" name="acao" value="excluir">
                        <input type="hidden" name="usuario_id" value="<?= (int) $usuario['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                      </form>
                    <?php else: ?>
                      <span class="text-muted">Somente leitura</span>
                    <?php endif; ?>
                  </div>
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
        <?php for ($i = max(1, $pagina - 2); $i <= min($totalPaginas, $pagina + 2); $i++): ?>
          <a
            href="?p=<?= $i ?>&status=<?= urlencode($filtroStatus) ?>&q=<?= urlencode($busca) ?>"
            class="page-link <?= $i === $pagina ? 'active' : '' ?>"
          ><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php include '_footer.php'; ?>
