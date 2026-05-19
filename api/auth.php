<?php
// ============================================================
// AdForge — auth.php
// Rotas: login, logout, me, invite (aceitar convite)
//
// GET  ?action=me          → dados do usuário logado
// POST ?action=login       → { email, password }
// POST ?action=logout      → encerra sessão
// POST ?action=invite      → { token, name, password } (aceitar convite)
// POST ?action=send_invite → [admin] { name, email, role }
// ============================================================

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match (true) {
    $method === 'GET'  && $action === 'me'          => handle_me(),
    $method === 'POST' && $action === 'login'        => handle_login(),
    $method === 'POST' && $action === 'logout'       => handle_logout(),
    $method === 'POST' && $action === 'invite'       => handle_accept_invite(),
    $method === 'POST' && $action === 'send_invite'  => handle_send_invite(),
    default => json_error('Rota não encontrada.', 404),
};

// ── GET ?action=me ───────────────────────────────────────────
function handle_me(): void {
    $user = auth_required();
    json_ok([
        'id'     => (int) $user['id'],
        'name'   => $user['name'],
        'email'  => $user['email'],
        'role'   => $user['role'],
        'status' => $user['status'],
    ]);
}

// ── POST ?action=login ───────────────────────────────────────
function handle_login(): void {
    $data     = require_body('email', 'password');
    $email    = strtolower(trim($data['email']));
    $password = $data['password'];

    $stmt = db()->prepare('SELECT id, name, email, password, role, status FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        json_error('E-mail ou senha incorretos.', 401);
    }
    if ($user['status'] === 'inactive') {
        json_error('Conta inativa. Contate o administrador.', 403);
    }
    if ($user['status'] === 'pending') {
        json_error('Conta pendente. Aceite o convite enviado por e-mail.', 403);
    }

    // Apaga sessões expiradas deste usuário (limpeza oportunista)
    db()->prepare('DELETE FROM sessions WHERE user_id = ? AND expires_at <= NOW()')->execute([$user['id']]);

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + SESSION_TTL);

    db()->prepare('INSERT INTO sessions (id, user_id, expires_at) VALUES (?, ?, ?)')->execute([$token, $user['id'], $expires]);

    // Cookie HttpOnly — dura SESSION_TTL segundos
    setcookie('adforge_session', $token, [
        'expires'  => time() + SESSION_TTL,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    json_ok([
        'token'      => $token,
        'expires_at' => $expires,
        'user'       => [
            'id'    => (int) $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ],
    ]);
}

// ── POST ?action=logout ──────────────────────────────────────
function handle_logout(): void {
    $token = session_token_from_request();
    if ($token) {
        db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$token]);
    }
    setcookie('adforge_session', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    json_ok(['message' => 'Sessão encerrada.']);
}

// ── POST ?action=invite (aceitar convite) ────────────────────
function handle_accept_invite(): void {
    $data     = require_body('token', 'password');
    $token    = trim($data['token']);
    $name     = trim($data['name'] ?? '');
    $password = $data['password'];

    if (strlen($password) < 8) {
        json_error('A senha deve ter pelo menos 8 caracteres.');
    }

    $stmt = db()->prepare('SELECT id, name FROM users WHERE invite_token = ? AND status = ?');
    $stmt->execute([$token, 'pending']);
    $user = $stmt->fetch();

    if (!$user) {
        json_error('Token de convite inválido ou já utilizado.', 404);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $newName = $name ?: $user['name'];

    db()->prepare('
        UPDATE users
        SET password = ?, name = ?, status = ?, invite_token = NULL
        WHERE id = ?
    ')->execute([$hash, $newName, 'active', $user['id']]);

    json_ok(['message' => 'Conta ativada com sucesso. Faça login.']);
}

// ── POST ?action=send_invite [admin] ─────────────────────────
function handle_send_invite(): void {
    admin_required();

    $data  = require_body('name', 'email', 'role');
    $name  = trim($data['name']);
    $email = strtolower(trim($data['email']));
    $role  = $data['role'];

    $validRoles = ['admin', 'editor', 'approver', 'viewer'];
    if (!in_array($role, $validRoles, true)) {
        json_error('Perfil inválido.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('E-mail inválido.');
    }

    // Verifica se já existe
    $check = db()->prepare('SELECT id, status FROM users WHERE email = ?');
    $check->execute([$email]);
    $existing = $check->fetch();
    if ($existing) {
        json_error('Este e-mail já está cadastrado.');
    }

    $token = bin2hex(random_bytes(32));
    // Senha provisória vazia — usuário define via convite
    $placeholder_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);

    db()->prepare('
        INSERT INTO users (name, email, password, role, status, invite_token)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([$name, $email, $placeholder_hash, $role, 'pending', $token]);

    $inviteUrl = APP_URL . '/index.html?invite=' . $token;

    // Em produção: enviar e-mail com $inviteUrl via PHPMailer ou mail()
    // Por ora retorna o link para o admin copiar
    json_ok([
        'message'    => "Convite criado para $name.",
        'invite_url' => $inviteUrl,
        'token'      => $token,
    ], 201);
}
