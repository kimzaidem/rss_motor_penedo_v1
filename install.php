<?php
require __DIR__ . '/app/bootstrap.php';
Database::pdo();
$error = '';
if (Database::hasUser()) {
    redirect_to('index.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($name === '' || $email === '' || strlen($password) < 6) {
        $error = 'Preencha nome, e-mail e uma senha com pelo menos 6 caracteres.';
    } else {
        Auth::create($name, $email, $password);
        Settings::cronKey();
        Auth::login($email, $password);
        redirect_to('index.php');
    }
}
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar RSS Motor Penedo</title><link rel="stylesheet" href="assets/app.css"></head><body class="login-page"><main class="login-box"><h1>Instalar RSS Motor Penedo</h1><p>Crie o acesso único do sistema.</p><?php if ($error): ?><div class="alert error"><?=h($error)?></div><?php endif; ?><form method="post" class="stack"><label>Nome<input name="name" required autofocus></label><label>E-mail<input name="email" type="email" required></label><label>Senha<input name="password" type="password" required minlength="6"></label><button class="btn primary">Instalar agora</button></form></main></body></html>
