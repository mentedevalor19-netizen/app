<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

if (auth_admin()) {
    header('Location: ' . admin_url('index.php'));
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $workspace = trim((string) ($_POST['workspace'] ?? ''));
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?: '';
    $senha = (string) ($_POST['senha'] ?? '');
    $confirmar = (string) ($_POST['confirmar_senha'] ?? '');

    if (!db_has_table('tenants') || !db_has_column('admins', 'tenant_id')) {
        $erro = 'A migracao SaaS ainda nao foi aplicada no banco.';
    } elseif ($workspace === '' || $nome === '' || $email === '' || $senha === '') {
        $erro = 'Preencha workspace, nome, e-mail e senha.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe um e-mail valido.';
    } elseif (strlen($senha) < 8) {
        $erro = 'A senha precisa ter pelo menos 8 caracteres.';
    } elseif (!hash_equals($senha, $confirmar)) {
        $erro = 'As senhas nao conferem.';
    } else {
        try {
            register_workspace_admin($workspace, $nome, $email, $senha);
            header('Location: ' . admin_url('login.php?ok=cadastro'));
            exit;
        } catch (Throwable $e) {
            $erro = $e->getMessage() !== '' ? $e->getMessage() : 'Nao foi possivel criar o workspace agora.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cadastrar Workspace | Painel</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Syne:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
*,
*::before,
*::after {
  box-sizing: border-box;
}

body {
  margin: 0;
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 24px;
  background:
    radial-gradient(circle at top left, rgba(255, 122, 61, 0.16), transparent 28%),
    radial-gradient(circle at top right, rgba(89, 184, 255, 0.12), transparent 24%),
    linear-gradient(180deg, #090c13 0%, #05070c 100%);
  color: #eef2ff;
  font-family: 'Syne', sans-serif;
}

.shell {
  width: min(100%, 520px);
}

.header {
  text-align: center;
  margin-bottom: 24px;
}

.logo {
  width: min(100%, 220px);
  display: block;
  margin: 0 auto 18px;
}

.kicker {
  margin: 0 0 10px;
  color: #ff7a3d;
  font-size: 11px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  font-family: 'JetBrains Mono', monospace;
}

.title {
  margin: 0;
  font-size: 34px;
  line-height: 0.98;
  font-weight: 800;
  letter-spacing: -0.05em;
}

.subtitle {
  margin: 12px 0 0;
  color: #9fa8bf;
  font-size: 13px;
}

.card {
  background: rgba(16, 20, 31, 0.94);
  border: 1px solid rgba(255, 122, 61, 0.16);
  border-radius: 22px;
  box-shadow: 0 22px 70px rgba(0, 0, 0, 0.42);
  padding: 28px;
  backdrop-filter: blur(16px);
}

.form-group + .form-group {
  margin-top: 16px;
}

.grid {
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.label {
  display: block;
  margin-bottom: 8px;
  color: #8e98b4;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-family: 'JetBrains Mono', monospace;
}

.control {
  width: 100%;
  padding: 13px 14px;
  border-radius: 14px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background: rgba(255, 255, 255, 0.04);
  color: #eef2ff;
  outline: none;
}

.control:focus {
  border-color: rgba(255, 122, 61, 0.38);
  box-shadow: 0 0 0 4px rgba(255, 122, 61, 0.1);
}

.help {
  margin-top: 8px;
  color: #7f88a3;
  font-size: 12px;
}

.btn {
  width: 100%;
  min-height: 46px;
  margin-top: 22px;
  border: 0;
  border-radius: 14px;
  background: linear-gradient(135deg, #ff9a4d, #ff5d1f);
  color: #1a0b03;
  font-weight: 800;
  font-size: 15px;
  cursor: pointer;
}

.alert {
  margin-bottom: 18px;
  padding: 13px 14px;
  border-radius: 14px;
  border: 1px solid rgba(255, 107, 107, 0.2);
  background: rgba(255, 107, 107, 0.12);
  color: #ffd3d3;
  font-size: 14px;
}

.footer {
  margin-top: 14px;
  text-align: center;
  color: #79829c;
  font-size: 12px;
  font-family: 'JetBrains Mono', monospace;
}

.footer a {
  color: #ff9a4d;
  text-decoration: none;
  font-weight: 700;
}

@media (max-width: 640px) {
  .grid {
    grid-template-columns: 1fr;
  }
}
</style>
</head>
<body>
  <div class="shell">
    <div class="header">
      <img class="logo" src="<?= htmlspecialchars(admin_url('assets/logomarca-branca.png')) ?>" alt="Pesto Pay">
      <p class="kicker">// saas mode</p>
      <h1 class="title">Novo workspace<br>do painel</h1>
      <p class="subtitle">Cada cliente ganha o proprio login, bot, webhook, funis, ofertas e automacoes isoladas.</p>
    </div>

    <div class="card">
      <?php if ($erro !== ''): ?>
        <div class="alert"><?= htmlspecialchars($erro) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label class="label" for="workspace">Nome do workspace</label>
          <input class="control" id="workspace" type="text" name="workspace" value="<?= htmlspecialchars((string) ($_POST['workspace'] ?? '')) ?>" placeholder="Ex: Agencia Atlas" required autofocus>
          <div class="help">Esse nome vira o painel do cliente e gera o slug interno do workspace.</div>
        </div>

        <div class="form-group">
          <label class="label" for="nome">Responsavel</label>
          <input class="control" id="nome" type="text" name="nome" value="<?= htmlspecialchars((string) ($_POST['nome'] ?? '')) ?>" placeholder="Nome completo" required>
        </div>

        <div class="grid">
          <div class="form-group">
            <label class="label" for="email">E-mail</label>
            <input class="control" id="email" type="email" name="email" value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>" placeholder="cliente@dominio.com" required>
          </div>

          <div class="form-group">
            <label class="label" for="senha">Senha</label>
            <input class="control" id="senha" type="password" name="senha" minlength="8" placeholder="Minimo de 8 caracteres" required>
          </div>
        </div>

        <div class="form-group">
          <label class="label" for="confirmar_senha">Confirmar senha</label>
          <input class="control" id="confirmar_senha" type="password" name="confirmar_senha" minlength="8" placeholder="Repita a senha" required>
        </div>

        <button type="submit" class="btn">Criar workspace e painel</button>
      </form>
    </div>

    <div class="footer">Ja tem acesso? <a href="<?= htmlspecialchars(admin_url('login.php')) ?>">Voltar para o login</a></div>
  </div>
</body>
</html>
