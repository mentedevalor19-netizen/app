<?php
if (!defined('ADMIN_PANEL')) {
    http_response_code(403);
    exit;
}

$menuItems = [
    'dashboard' => ['label' => 'Dashboard', 'path' => admin_url('index.php'), 'tag' => 'DB'],
    'produtos' => ['label' => 'Produtos / Planos', 'path' => admin_url('produtos.php'), 'tag' => 'PL'],
    'orderbumps' => ['label' => 'Order Bump', 'path' => admin_url('orderbumps.php'), 'tag' => 'OB'],
    'fluxos' => ['label' => 'Fluxo / Start', 'path' => admin_url('fluxos.php'), 'tag' => 'FX'],
    'funis' => ['label' => 'Upsell', 'path' => admin_url('funis.php'), 'tag' => 'UP'],
    'downsells' => ['label' => 'Downsell', 'path' => admin_url('downsells.php'), 'tag' => 'DS'],
    'remarketing' => ['label' => 'Remarketing', 'path' => admin_url('remarketing.php'), 'tag' => 'RM'],
    'usuarios' => ['label' => 'Usuarios', 'path' => admin_url('usuarios.php'), 'tag' => 'US'],
    'pagamentos' => ['label' => 'Pagamentos', 'path' => admin_url('pagamentos.php'), 'tag' => 'PG'],
    'logs' => ['label' => 'Logs', 'path' => admin_url('logs.php'), 'tag' => 'LG'],
    'configuracoes' => ['label' => 'Configuracoes', 'path' => admin_url('configuracoes.php'), 'tag' => 'CF'],
    'admins' => ['label' => 'Administradores', 'path' => admin_url('admins.php'), 'tag' => 'AD'],
];

$successMessages = [
    'salvo' => 'Dados salvos com sucesso.',
    'excluido' => 'Registro excluido com sucesso.',
    'ativado' => 'Acao concluida com sucesso.',
    'removido' => 'Usuario removido com sucesso.',
];

$errorMessages = [
    'sem_permissao' => 'Voce nao tem permissao para esta acao.',
    'falha' => 'Ocorreu um erro. Tente novamente.',
];

$brandLogo = admin_url('assets/logomarca-branca.png');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(($page_title ?? 'Admin') . ' | Painel') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Syne:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
*,
*::before,
*::after {
  box-sizing: border-box;
}

:root {
  color-scheme: dark;
  --bg: #090b12;
  --panel: #10131d;
  --panel-soft: #151a27;
  --panel-strong: #1c2232;
  --border: #252d42;
  --text: #eef2ff;
  --muted: #9fa8bf;
  --muted-soft: #6f7894;
  --accent: #ff7a3d;
  --accent-soft: rgba(255, 122, 61, 0.16);
  --danger: #ff6b6b;
  --danger-soft: rgba(255, 107, 107, 0.14);
  --warning: #f7c948;
  --warning-soft: rgba(247, 201, 72, 0.14);
  --info: #59b8ff;
  --info-soft: rgba(89, 184, 255, 0.14);
  --radius: 16px;
  --radius-sm: 12px;
  --shadow: 0 18px 60px rgba(0, 0, 0, 0.35);
  --sidebar-width: 260px;
  --mono: 'JetBrains Mono', monospace;
  --sans: 'Syne', sans-serif;
}

html {
  min-height: 100%;
}

body {
  margin: 0;
  min-height: 100vh;
  background:
    radial-gradient(circle at top left, rgba(255, 122, 61, 0.13), transparent 30%),
    radial-gradient(circle at top right, rgba(89, 184, 255, 0.08), transparent 25%),
    linear-gradient(180deg, #0a0d14 0%, #07090f 100%);
  color: var(--text);
  font-family: var(--sans);
}

a {
  color: inherit;
}

button,
input,
select,
textarea {
  font: inherit;
}

.app-shell {
  min-height: 100vh;
}

.sidebar-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(3, 5, 10, 0.72);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
  z-index: 80;
}

.sidebar-backdrop.open {
  opacity: 1;
  pointer-events: auto;
}

.sidebar {
  position: fixed;
  inset: 0 auto 0 0;
  width: var(--sidebar-width);
  background: rgba(13, 17, 27, 0.92);
  border-right: 1px solid rgba(255, 255, 255, 0.06);
  backdrop-filter: blur(18px);
  display: flex;
  flex-direction: column;
  z-index: 90;
}

.brand {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 28px 22px 24px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  text-decoration: none;
}

.brand-logo {
  width: min(100%, 180px);
  display: block;
  filter: drop-shadow(0 12px 28px rgba(0, 0, 0, 0.28));
}

.brand-kicker {
  margin: 0;
  color: var(--accent);
  font-family: var(--mono);
  font-size: 11px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
}

.brand-title {
  margin: 0;
  font-size: 24px;
  line-height: 1.02;
  font-weight: 800;
  letter-spacing: -0.04em;
}

.brand-copy {
  margin: 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.55;
  max-width: 210px;
}

.sidebar-scroll {
  flex: 1;
  overflow-y: auto;
  padding: 18px 14px 18px;
}

.nav-section {
  margin: 18px 8px 8px;
  color: var(--muted-soft);
  font-size: 10px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  font-family: var(--mono);
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 14px;
  color: var(--muted);
  text-decoration: none;
  transition: background 0.18s ease, color 0.18s ease, transform 0.18s ease;
}

.nav-item:hover {
  background: rgba(255, 255, 255, 0.04);
  color: var(--text);
  transform: translateX(2px);
}

.nav-item.active {
  background: linear-gradient(135deg, var(--accent-soft), rgba(89, 184, 255, 0.12));
  color: var(--text);
  border: 1px solid rgba(255, 122, 61, 0.22);
}

.nav-tag {
  width: 30px;
  height: 30px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.05);
  color: var(--accent);
  font-size: 10px;
  font-family: var(--mono);
  font-weight: 700;
  letter-spacing: 0.08em;
  flex-shrink: 0;
}

.nav-item.active .nav-tag {
  background: rgba(255, 122, 61, 0.2);
}

.sidebar-footer {
  padding: 18px 20px 22px;
  border-top: 1px solid rgba(255, 255, 255, 0.06);
}

.admin-chip {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}

.admin-avatar {
  width: 42px;
  height: 42px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(255, 122, 61, 0.24), rgba(89, 184, 255, 0.18));
  border: 1px solid rgba(255, 122, 61, 0.22);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-family: var(--mono);
  font-weight: 700;
  color: var(--accent);
}

.admin-name {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
}

.admin-role {
  margin: 4px 0 0;
  font-size: 10px;
  font-family: var(--mono);
  color: var(--muted-soft);
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.logout-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  padding: 12px 14px;
  border-radius: 12px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  color: var(--muted);
  text-decoration: none;
  transition: border-color 0.18s ease, color 0.18s ease, background 0.18s ease;
}

.logout-link:hover {
  border-color: rgba(255, 107, 107, 0.3);
  color: #fff;
  background: rgba(255, 107, 107, 0.08);
}

.main {
  margin-left: var(--sidebar-width);
  min-height: 100vh;
}

.topbar {
  position: sticky;
  top: 0;
  z-index: 40;
  padding: 18px 30px;
  background: rgba(8, 11, 18, 0.78);
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  backdrop-filter: blur(18px);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
}

.topbar-left {
  display: flex;
  align-items: center;
  gap: 14px;
}

.menu-toggle {
  display: none;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background: rgba(255, 255, 255, 0.03);
  color: var(--text);
  padding: 10px 14px;
  border-radius: 12px;
  cursor: pointer;
}

.page-title {
  margin: 0;
  font-size: 26px;
  font-weight: 800;
  line-height: 1;
}

.page-subtitle {
  margin: 6px 0 0;
  font-size: 12px;
  color: var(--muted);
  font-family: var(--mono);
}

.topbar-time {
  padding: 10px 14px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(255, 255, 255, 0.06);
  color: var(--muted);
  font-family: var(--mono);
  font-size: 12px;
}

.content {
  padding: 28px 30px 36px;
}

.content > * + * {
  margin-top: 20px;
}

.alert {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 16px;
  border-radius: 14px;
  border: 1px solid transparent;
  font-size: 14px;
}

.alert-success {
  background: rgba(44, 230, 166, 0.12);
  border-color: rgba(44, 230, 166, 0.22);
  color: #bff7df;
}

.alert-danger {
  background: rgba(255, 107, 107, 0.12);
  border-color: rgba(255, 107, 107, 0.22);
  color: #ffd0d0;
}

.alert-warning {
  background: rgba(247, 201, 72, 0.12);
  border-color: rgba(247, 201, 72, 0.22);
  color: #ffe9b0;
}

.alert-info {
  background: rgba(89, 184, 255, 0.12);
  border-color: rgba(89, 184, 255, 0.22);
  color: #cbe8ff;
}

.stats-grid,
.content-grid {
  display: grid;
  gap: 20px;
}

.stats-grid {
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.content-grid--sidebar {
  grid-template-columns: minmax(320px, 420px) minmax(0, 1fr);
  align-items: start;
}

.card {
  background: linear-gradient(180deg, rgba(19, 24, 37, 0.94), rgba(15, 19, 30, 0.96));
  border: 1px solid rgba(255, 255, 255, 0.06);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  overflow: hidden;
}

.card-header,
.card-body {
  padding: 22px;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.card-title {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
}

.card-copy,
.section-copy,
.muted {
  color: var(--muted);
  font-size: 14px;
  line-height: 1.6;
}

.stat-card {
  padding: 22px;
}

.stat-label {
  color: var(--muted-soft);
  font-size: 11px;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  font-family: var(--mono);
}

.stat-value {
  margin-top: 12px;
  font-size: 34px;
  font-weight: 800;
}

.stat-subtitle {
  margin-top: 10px;
  color: var(--muted);
  font-size: 13px;
}

.form-group + .form-group {
  margin-top: 16px;
}

.form-grid {
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.form-grid.form-grid-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.form-label {
  display: block;
  margin-bottom: 8px;
  font-size: 11px;
  color: var(--muted-soft);
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-family: var(--mono);
}

.form-help {
  display: block;
  margin-top: 7px;
  color: var(--muted-soft);
  font-size: 12px;
}

.form-control {
  width: 100%;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background: rgba(255, 255, 255, 0.04);
  color: var(--text);
  border-radius: 12px;
  padding: 12px 14px;
  outline: none;
  transition: border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
}

.form-control:focus {
  border-color: rgba(255, 122, 61, 0.38);
  background: rgba(255, 255, 255, 0.05);
  box-shadow: 0 0 0 4px rgba(255, 122, 61, 0.12);
}

select.form-control {
  -webkit-appearance: none;
  appearance: none;
  color-scheme: dark;
  forced-color-adjust: none;
  background-color: rgba(255, 255, 255, 0.04);
  background-image:
    linear-gradient(45deg, transparent 50%, rgba(238, 242, 255, 0.78) 50%),
    linear-gradient(135deg, rgba(238, 242, 255, 0.78) 50%, transparent 50%);
  background-position:
    calc(100% - 18px) calc(50% - 2px),
    calc(100% - 12px) calc(50% - 2px);
  background-repeat: no-repeat;
  background-size: 6px 6px;
  padding-right: 42px;
}

select.form-control option,
select.form-control optgroup {
  background: #10131d;
  color: var(--text);
}

select.form-control option:checked,
select.form-control option:hover {
  background: #1d2740 linear-gradient(0deg, #1d2740 0%, #1d2740 100%);
  color: #ffffff;
}

textarea.form-control {
  min-height: 120px;
  resize: vertical;
}

.checkbox-row {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--muted);
}

.checkbox-row input {
  width: 18px;
  height: 18px;
}

.form-actions,
.toolbar,
.actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 42px;
  padding: 0 16px;
  border-radius: 12px;
  border: 1px solid transparent;
  text-decoration: none;
  cursor: pointer;
  transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease, color 0.18s ease;
}

.btn:hover {
  transform: translateY(-1px);
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}

.btn-primary {
  background: linear-gradient(135deg, #ff9a4d, #ff5d1f);
  color: #1a0b03;
  font-weight: 700;
}

.btn-secondary,
.btn-ghost {
  background: rgba(255, 255, 255, 0.04);
  border-color: rgba(255, 255, 255, 0.08);
  color: var(--text);
}

.btn-ghost {
  color: var(--muted);
}

.btn-danger {
  background: rgba(255, 107, 107, 0.1);
  border-color: rgba(255, 107, 107, 0.18);
  color: #ffd6d6;
}

.btn-sm {
  min-height: 34px;
  padding: 0 12px;
  border-radius: 10px;
  font-size: 12px;
}

.table-wrap {
  overflow-x: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 14px 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  text-align: left;
  vertical-align: top;
}

th {
  color: var(--muted-soft);
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-family: var(--mono);
  white-space: nowrap;
}

td {
  color: var(--text);
  font-size: 14px;
}

tbody tr:hover td {
  background: rgba(255, 255, 255, 0.02);
}

.mono {
  font-family: var(--mono);
  font-size: 12px;
}

.text-muted {
  color: var(--muted);
}

.inline-form {
  display: inline;
}

.badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid transparent;
  font-size: 11px;
  font-family: var(--mono);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.badge::before {
  content: '';
  width: 7px;
  height: 7px;
  border-radius: 999px;
  background: currentColor;
  opacity: 0.8;
}

.badge-green {
  background: rgba(44, 230, 166, 0.12);
  border-color: rgba(44, 230, 166, 0.2);
  color: #9ef1cd;
}

.badge-red {
  background: rgba(255, 107, 107, 0.12);
  border-color: rgba(255, 107, 107, 0.2);
  color: #ffb4b4;
}

.badge-yellow {
  background: rgba(247, 201, 72, 0.12);
  border-color: rgba(247, 201, 72, 0.2);
  color: #ffe08a;
}

.badge-blue {
  background: rgba(89, 184, 255, 0.12);
  border-color: rgba(89, 184, 255, 0.2);
  color: #b2deff;
}

.badge-gray {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.08);
  color: var(--muted);
}

.empty-state {
  padding: 32px 16px;
  text-align: center;
  color: var(--muted);
}

.split-value {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.split-value:last-child {
  border-bottom: 0;
}

.code-box {
  padding: 12px 14px;
  background: rgba(255, 255, 255, 0.04);
  border-radius: 12px;
  border: 1px solid rgba(255, 255, 255, 0.06);
  color: var(--text);
  font-family: var(--mono);
  font-size: 12px;
  overflow-wrap: anywhere;
}

.pagination {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  padding: 18px 22px 22px;
}

.page-info {
  margin-right: auto;
  color: var(--muted);
  font-size: 12px;
  font-family: var(--mono);
}

.page-link {
  min-width: 34px;
  height: 34px;
  padding: 0 10px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  color: var(--muted);
  border: 1px solid rgba(255, 255, 255, 0.08);
  background: rgba(255, 255, 255, 0.03);
}

.page-link.active,
.page-link:hover {
  color: var(--text);
  border-color: rgba(255, 122, 61, 0.24);
  background: rgba(255, 122, 61, 0.1);
}

.stack > * + * {
  margin-top: 18px;
}

.stack-sm > * + * {
  margin-top: 10px;
}

.card-kicker,
.step-card-kicker {
  margin: 0 0 8px;
  color: var(--accent);
  font-family: var(--mono);
  font-size: 11px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.section-copy {
  margin: 0;
  color: var(--muted);
  line-height: 1.65;
}

.journey-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.8fr);
  gap: 20px;
  align-items: start;
}

.journey-map,
.flow-card-list,
.step-card-list {
  display: grid;
  gap: 14px;
}

.journey-side {
  display: grid;
  gap: 14px;
}

.journey-step,
.soft-panel,
.flow-card,
.step-card {
  border-radius: 16px;
  border: 1px solid rgba(255, 255, 255, 0.07);
  background: rgba(255, 255, 255, 0.03);
}

.journey-step {
  display: grid;
  grid-template-columns: 68px minmax(0, 1fr);
  gap: 16px;
  padding: 18px;
}

.journey-step-index {
  width: 52px;
  height: 52px;
  border-radius: 16px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-family: var(--mono);
  font-size: 16px;
  font-weight: 700;
  color: var(--accent);
  background: rgba(44, 230, 166, 0.12);
  border: 1px solid rgba(44, 230, 166, 0.18);
}

.journey-step-title,
.flow-card-title,
.step-card-title,
.journey-group-title {
  margin: 0;
  font-size: 18px;
  line-height: 1.2;
}

.journey-step-copy,
.flow-card-copy,
.journey-group-copy,
.step-card-copy {
  margin: 10px 0 0;
  color: var(--muted);
  line-height: 1.65;
}

.soft-panel {
  padding: 18px;
}

.soft-panel--compact {
  height: 100%;
}

.soft-panel-title {
  margin: 0 0 10px;
  font-size: 13px;
  font-family: var(--mono);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--muted-soft);
}

.tag-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.tag-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 30px;
  padding: 0 12px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.06);
  color: var(--text);
  font-size: 12px;
}

.journey-group {
  border-radius: 16px;
  border: 1px solid rgba(255, 255, 255, 0.07);
  background: rgba(255, 255, 255, 0.03);
  overflow: hidden;
}

.journey-group-summary {
  list-style: none;
  padding: 18px 20px;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.journey-group-summary::-webkit-details-marker {
  display: none;
}

.journey-group-summary::after {
  content: 'Abrir';
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 64px;
  height: 30px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.05);
  color: var(--muted);
  font-family: var(--mono);
  font-size: 11px;
  flex-shrink: 0;
}

.journey-group[open] .journey-group-summary::after {
  content: 'Fechar';
}

.journey-group-body {
  padding: 0 20px 20px;
}

.flow-card,
.step-card {
  padding: 18px;
}

.flow-card-top,
.step-card-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
}

.flow-card-copy {
  margin-bottom: 0;
}

.step-card.is-muted,
.flow-card.is-muted {
  opacity: 0.65;
}

.preset-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 18px;
}

.modal-overlay {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: rgba(5, 8, 14, 0.76);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.18s ease;
  z-index: 100;
}

.modal-overlay.open {
  opacity: 1;
  pointer-events: auto;
}

.modal {
  width: min(100%, 520px);
  border-radius: 18px;
  background: linear-gradient(180deg, rgba(19, 24, 37, 0.98), rgba(15, 19, 30, 0.98));
  border: 1px solid rgba(255, 255, 255, 0.08);
  box-shadow: var(--shadow);
  padding: 24px;
}

details summary {
  cursor: pointer;
}

@media (max-width: 1080px) {
  .journey-grid,
  .content-grid--sidebar,
  .form-grid.form-grid-3 {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 900px) {
  .sidebar {
    transform: translateX(-100%);
    transition: transform 0.2s ease;
  }

  .sidebar.open {
    transform: translateX(0);
  }

  .main {
    margin-left: 0;
  }

  .menu-toggle {
    display: inline-flex;
  }
}

@media (max-width: 720px) {
  .topbar,
  .content {
    padding-left: 18px;
    padding-right: 18px;
  }

  .topbar {
    align-items: flex-start;
    flex-direction: column;
  }

  .form-grid,
  .stats-grid,
  .content-grid {
    grid-template-columns: 1fr;
  }

  th,
  td {
    padding: 12px;
  }
}
</style>
</head>
<body>
<div class="app-shell">
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= admin_url('index.php') ?>">
      <img class="brand-logo" src="<?= htmlspecialchars($brandLogo) ?>" alt="Pesto Pay">
      <p class="brand-kicker">// admin console</p>
      <p class="brand-copy">Checkout, automacoes e ofertas em um painel dark mais atual.</p>
    </a>

    <div class="sidebar-scroll">
      <div class="nav-section">Principal</div>
      <?php foreach (['dashboard'] as $key): ?>
        <a href="<?= $menuItems[$key]['path'] ?>" class="nav-item <?= ($active_menu ?? '') === $key ? 'active' : '' ?>">
          <span class="nav-tag"><?= $menuItems[$key]['tag'] ?></span>
          <span><?= htmlspecialchars($menuItems[$key]['label']) ?></span>
        </a>
      <?php endforeach; ?>

      <div class="nav-section">Vendas</div>
      <?php foreach (['produtos', 'orderbumps', 'fluxos', 'funis'] as $key): ?>
        <a href="<?= $menuItems[$key]['path'] ?>" class="nav-item <?= ($active_menu ?? '') === $key ? 'active' : '' ?>">
          <span class="nav-tag"><?= $menuItems[$key]['tag'] ?></span>
          <span><?= htmlspecialchars($menuItems[$key]['label']) ?></span>
        </a>
      <?php endforeach; ?>

      <div class="nav-section">Operacao</div>
      <?php foreach (['usuarios', 'pagamentos', 'logs'] as $key): ?>
        <a href="<?= $menuItems[$key]['path'] ?>" class="nav-item <?= ($active_menu ?? '') === $key ? 'active' : '' ?>">
          <span class="nav-tag"><?= $menuItems[$key]['tag'] ?></span>
          <span><?= htmlspecialchars($menuItems[$key]['label']) ?></span>
        </a>
      <?php endforeach; ?>

      <div class="nav-section">Sistema</div>
      <?php foreach (['configuracoes', 'admins'] as $key): ?>
        <a href="<?= $menuItems[$key]['path'] ?>" class="nav-item <?= ($active_menu ?? '') === $key ? 'active' : '' ?>">
          <span class="nav-tag"><?= $menuItems[$key]['tag'] ?></span>
          <span><?= htmlspecialchars($menuItems[$key]['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="sidebar-footer">
      <div class="admin-chip">
        <div class="admin-avatar"><?= htmlspecialchars(strtoupper(substr((string) ($current_admin['nome'] ?? 'A'), 0, 1))) ?></div>
        <div>
          <p class="admin-name"><?= htmlspecialchars((string) ($current_admin['nome'] ?? 'Administrador')) ?></p>
          <p class="admin-role"><?= htmlspecialchars((string) ($current_admin['nivel'] ?? 'admin')) ?></p>
        </div>
      </div>
      <a href="<?= admin_url('logout.php') ?>" class="logout-link">Sair do painel</a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <button type="button" class="menu-toggle" id="menuToggle">Menu</button>
        <div>
          <h1 class="page-title"><?= htmlspecialchars((string) ($page_title ?? 'Painel')) ?></h1>
          <?php if (!empty($page_subtitle)): ?>
            <p class="page-subtitle"><?= htmlspecialchars((string) $page_subtitle) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="topbar-time" id="clock">--:--:--</div>
    </header>

    <main class="content">
      <?php if (!empty($_GET['ok'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMessages[$_GET['ok']] ?? 'Operacao concluida com sucesso.') ?></div>
      <?php elseif (!empty($_GET['erro'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMessages[$_GET['erro']] ?? 'Nao foi possivel concluir a operacao.') ?></div>
      <?php endif; ?>
