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
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $senha = (string) ($_POST['senha'] ?? '');

    if (!$email || $senha === '') {
        $erro = 'Preencha e-mail e senha.';
    } else {
        $admin = auth_login($email, $senha);

        if ($admin) {
            header('Location: ' . admin_url('index.php'));
            exit;
        }

        $erro = 'E-mail ou senha incorretos.';
        sleep(1);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | Painel</title>
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

.login-shell {
  width: min(100%, 420px);
}

.login-header {
  text-align: center;
  margin-bottom: 24px;
}

.login-logo {
  width: min(100%, 220px);
  display: block;
  margin: 0 auto 18px;
  filter: drop-shadow(0 14px 30px rgba(0, 0, 0, 0.28));
}

.login-kicker {
  margin: 0 0 10px;
  color: #ff7a3d;
  font-size: 11px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  font-family: 'JetBrains Mono', monospace;
}

.login-title {
  margin: 0;
  font-size: 36px;
  line-height: 0.94;
  font-weight: 800;
  letter-spacing: -0.05em;
}

.login-subtitle {
  margin: 12px 0 0;
  color: #9fa8bf;
  font-size: 13px;
}

.login-card {
  background: rgba(16, 20, 31, 0.94);
  border: 1px solid rgba(255, 122, 61, 0.16);
  border-radius: 22px;
  box-shadow: 0 22px 70px rgba(0, 0, 0, 0.42);
  padding: 28px;
  backdrop-filter: blur(16px);
}

.form-group + .form-group {
  margin-top: 18px;
}

.form-label {
  display: block;
  margin-bottom: 8px;
  color: #8e98b4;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-family: 'JetBrains Mono', monospace;
}

.form-control {
  width: 100%;
  padding: 13px 14px;
  border-radius: 14px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background: rgba(255, 255, 255, 0.04);
  color: #eef2ff;
  outline: none;
}

.form-control:focus {
  border-color: rgba(255, 122, 61, 0.38);
  box-shadow: 0 0 0 4px rgba(255, 122, 61, 0.1);
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

.login-footer {
  margin-top: 14px;
  text-align: center;
  color: #79829c;
  font-size: 12px;
  font-family: 'JetBrains Mono', monospace;
}
</style>
</head>
<body>
  <div class="login-shell">
    <div class="login-header">
      <img class="login-logo" src="<?= htmlspecialchars(admin_url('assets/logomarca-branca.png')) ?>" alt="Pesto Pay">
      <p class="login-kicker">// painel dark</p>
      <h1 class="login-title">Gestao<br>Administrativa</h1>
      <p class="login-subtitle">Checkout, bot, ofertas e pagamentos em um unico centro de comando.</p>
    </div>

    <div class="login-card">
      <?php if ($erro !== ''): ?>
        <div class="alert"><?= htmlspecialchars($erro) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label class="form-label" for="email">E-mail</label>
          <input class="form-control" id="email" type="email" name="email" value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>" placeholder="admin@dominio.com" required autofocus>
        </div>

        <div class="form-group">
          <label class="form-label" for="senha">Senha</label>
          <input class="form-control" id="senha" type="password" name="senha" placeholder="Sua senha" required>
        </div>

        <button type="submit" class="btn">Entrar no painel</button>
      </form>
    </div>

    <div class="login-footer">Acesso monitorado e registrado.</div>
  </div>
</body>
</html>
