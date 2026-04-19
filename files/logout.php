<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/auth.php';

auth_logout();
header('Location: ' . admin_url('login.php'));
exit;
