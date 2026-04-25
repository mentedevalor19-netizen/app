<?php
define('ADMIN_PANEL', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

$current_admin = require_auth();

if (($current_admin['nivel'] ?? '') !== 'super') {
    header('Location: ' . admin_url('index.php?erro=sem_permissao'));
    exit;
}

$pdo = db();
$msg = null;
$senhaAdmin = null;
$senhaId = (int) ($_GET['senha'] ?? 0);
$adminScope = tenant_scope_condition('admins');

if ($senhaId > 0) {
    $stmt = $pdo->prepare('SELECT id, nome, email FROM admins WHERE id = ? AND ' . $adminScope . ' LIMIT 1');
    $stmt->execute([$senhaId]);
    $senhaAdmin = $stmt->fetch();

    if (!$senhaAdmin) {
        $msg = ['tipo' => 'warning', 'texto' => 'Administrador nao encontrado para troca de senha.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'criar') {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $senha = (string) ($_POST['senha'] ?? '');
        $nivel = in_array(($_POST['nivel'] ?? ''), ['super', 'admin', 'viewer'], true) ? $_POST['nivel'] : 'admin';

        if ($nome === '' || $email === '' || strlen($senha) < 8) {
            $msg = ['tipo' => 'danger', 'texto' => 'Preencha nome, e-mail e uma senha com pelo menos 8 caracteres.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = ['tipo' => 'danger', 'texto' => 'Informe um e-mail valido.'];
        } else {
            $stmt = $pdo->prepare('SELECT id FROM admins WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $msg = ['tipo' => 'danger', 'texto' => 'Ja existe um administrador com esse e-mail.'];
            } else {
                $hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare('INSERT INTO admins (tenant_id, nome, email, senha_hash, nivel) VALUES (?, ?, ?, ?, ?)')
                    ->execute([current_tenant_id(), $nome, $email, $hash, $nivel]);
                header('Location: ' . admin_url('admins.php?ok=salvo'));
                exit;
            }
        }
    }

    if ($acao === 'toggle_ativo') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id !== (int) $current_admin['id']) {
            $pdo->prepare('UPDATE admins SET ativo = NOT ativo WHERE id = ? AND ' . $adminScope)->execute([$id]);
            header('Location: ' . admin_url('admins.php?ok=salvo'));
            exit;
        } else {
            $msg = ['tipo' => 'warning', 'texto' => 'Voce nao pode desativar seu proprio acesso.'];
        }
    }

    if ($acao === 'trocar_senha') {
        $id = (int) ($_POST['id'] ?? 0);
        $novaSenha = (string) ($_POST['nova_senha'] ?? '');

        if (strlen($novaSenha) < 8) {
            $msg = ['tipo' => 'danger', 'texto' => 'A nova senha precisa ter pelo menos 8 caracteres.'];
        } else {
            $hash = password_hash($novaSenha, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE admins SET senha_hash = ? WHERE id = ? AND ' . $adminScope)->execute([$hash, $id]);
            header('Location: ' . admin_url('admins.php?ok=salvo'));
            exit;
        }
    }

    if ($acao === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id === (int) $current_admin['id']) {
            $msg = ['tipo' => 'warning', 'texto' => 'Voce nao pode excluir sua propria conta logada.'];
        } else {
            $pdo->prepare('DELETE FROM admins WHERE id = ? AND ' . $adminScope)->execute([$id]);
            header('Location: ' . admin_url('admins.php?ok=excluido'));
            exit;
        }
    }
}

$admins = $pdo->query("SELECT * FROM admins WHERE $adminScope ORDER BY CASE nivel WHEN 'super' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END, created_at ASC")->fetchAll();

$page_title = 'Administradores';
$page_subtitle = count($admins) . ' admin(s) cadastrado(s)';
$active_menu = 'admins';
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
          <h2 class="card-title">Novo administrador</h2>
          <p class="card-copy">Crie contas para equipe, operacao ou visualizacao.</p>
        </div>
      </div>
      <div class="card-body">
        <form method="POST" class="stack">
          <input type="hidden" name="acao" value="criar">

          <div class="form-group">
            <label class="form-label" for="nome">Nome</label>
            <input class="form-control" id="nome" type="text" name="nome" placeholder="Nome completo" required>
          </div>

          <div class="form-group">
            <label class="form-label" for="email">E-mail</label>
            <input class="form-control" id="email" type="email" name="email" placeholder="admin@dominio.com" required>
          </div>

          <div class="form-group">
            <label class="form-label" for="senha">Senha</label>
            <input class="form-control" id="senha" type="password" name="senha" minlength="8" placeholder="Minimo de 8 caracteres" required>
          </div>

          <div class="form-group">
            <label class="form-label" for="nivel">Nivel de acesso</label>
            <select class="form-control" id="nivel" name="nivel">
              <option value="viewer">Viewer</option>
              <option value="admin" selected>Admin</option>
              <option value="super">Super</option>
            </select>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Criar administrador</button>
          </div>
        </form>
      </div>
    </article>

    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title"><?= $senhaAdmin ? 'Trocar senha' : 'Troca de senha' ?></h2>
          <p class="card-copy"><?= $senhaAdmin ? 'Defina uma nova senha para o administrador selecionado.' : 'Use o botao "Senha" na tabela para carregar um administrador aqui.' ?></p>
        </div>
        <?php if ($senhaAdmin): ?>
          <a href="<?= admin_url('admins.php') ?>" class="btn btn-ghost btn-sm">Cancelar</a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($senhaAdmin): ?>
          <form method="POST" class="stack">
            <input type="hidden" name="acao" value="trocar_senha">
            <input type="hidden" name="id" value="<?= (int) $senhaAdmin['id'] ?>">

            <div class="split-value">
              <span class="text-muted">Administrador</span>
              <strong><?= htmlspecialchars((string) $senhaAdmin['nome']) ?></strong>
            </div>
            <div class="split-value">
              <span class="text-muted">E-mail</span>
              <span class="mono"><?= htmlspecialchars((string) $senhaAdmin['email']) ?></span>
            </div>

            <div class="form-group">
              <label class="form-label" for="nova_senha">Nova senha</label>
              <input class="form-control" id="nova_senha" type="password" name="nova_senha" minlength="8" placeholder="Minimo de 8 caracteres" required>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn btn-primary">Salvar nova senha</button>
            </div>
          </form>
        <?php else: ?>
          <div class="empty-state">Nenhum administrador selecionado para troca de senha.</div>
        <?php endif; ?>
      </div>
    </article>
  </section>

  <section class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Administradores</h2>
        <p class="card-copy">Controle de acesso ao painel e niveis de permissao.</p>
      </div>
    </div>
    <div class="card-body" style="padding-top: 0;">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Nome</th>
              <th>E-mail</th>
              <th>Nivel</th>
              <th>Status</th>
              <th>Ultimo login</th>
              <th>Cadastro</th>
              <th>Acoes</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$admins): ?>
              <tr>
                <td colspan="8" class="empty-state">Nenhum administrador cadastrado.</td>
              </tr>
            <?php endif; ?>

            <?php foreach ($admins as $admin): ?>
              <?php
              $isMe = (int) $admin['id'] === (int) $current_admin['id'];
              $nivelBadge = match ((string) $admin['nivel']) {
                  'super' => 'badge-green',
                  'admin' => 'badge-blue',
                  default => 'badge-gray',
              };
              ?>
              <tr>
                <td class="mono"><?= (int) $admin['id'] ?></td>
                <td>
                  <strong><?= htmlspecialchars((string) $admin['nome']) ?></strong>
                  <?php if ($isMe): ?>
                    <div class="text-muted">Conta atual</div>
                  <?php endif; ?>
                </td>
                <td class="mono"><?= htmlspecialchars((string) $admin['email']) ?></td>
                <td><span class="badge <?= $nivelBadge ?>"><?= htmlspecialchars((string) $admin['nivel']) ?></span></td>
                <td>
                  <form method="POST" class="inline-form">
                    <input type="hidden" name="acao" value="toggle_ativo">
                    <input type="hidden" name="id" value="<?= (int) $admin['id'] ?>">
                    <button type="submit" class="btn btn-sm <?= (int) $admin['ativo'] === 1 ? 'btn-secondary' : 'btn-ghost' ?>" <?= $isMe ? 'disabled' : '' ?>>
                      <?= (int) $admin['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                    </button>
                  </form>
                </td>
                <td class="mono"><?= !empty($admin['ultimo_login']) ? date('d/m/Y H:i', strtotime((string) $admin['ultimo_login'])) : '-' ?></td>
                <td class="mono"><?= !empty($admin['created_at']) ? date('d/m/Y', strtotime((string) $admin['created_at'])) : '-' ?></td>
                <td>
                  <div class="actions">
                    <a href="<?= admin_url('admins.php?senha=' . (int) $admin['id']) ?>" class="btn btn-ghost btn-sm">Senha</a>
                    <?php if (!$isMe): ?>
                      <form method="POST" class="inline-form" onsubmit="return confirm('Excluir este administrador?');">
                        <input type="hidden" name="acao" value="excluir">
                        <input type="hidden" name="id" value="<?= (int) $admin['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                      </form>
                    <?php endif; ?>
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
