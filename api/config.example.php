<?php
// ============================================================
// AdForge — config.example.php
// Copie este arquivo para config.php e preencha os valores.
// NUNCA suba config.php para o repositório.
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'cpanelusr_adforge');   // ← nome do banco no cPanel
define('DB_USER', 'cpanelusr_adforge');   // ← usuário MySQL
define('DB_PASS', 'SUA_SENHA_AQUI');      // ← senha MySQL

define('APP_URL',    'https://ericnunes.com.br/geradordeanuncios');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', APP_URL . '/uploads/');

// Gere com: openssl rand -base64 32
// Guarde esta chave em lugar seguro — trocar quebra todas as API keys salvas.
define('ENCRYPT_KEY', 'COLE_AQUI_UMA_STRING_ALEATORIA_DE_32_CHARS');

define('SESSION_TTL', 60 * 60 * 8); // 8 horas

// ── CORS / Headers ───────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$allowed_origin = APP_URL;
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === $allowed_origin) {
    header("Access-Control-Allow-Origin: $allowed_origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Conexão PDO (singleton) ──────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET ?? 'utf8mb4');
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        json_error('Erro de conexão com o banco de dados.', 503);
    }
    return $pdo;
}

function json_ok(mixed $data = null, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function auth_required(): array {
    $token = session_token_from_request();
    if (!$token) json_error('Não autenticado.', 401);
    $stmt = db()->prepare('SELECT s.user_id, s.expires_at, u.id, u.name, u.email, u.role, u.status FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.id = ? AND s.expires_at > NOW()');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== 'active') json_error('Sessão inválida ou expirada.', 401);
    return $row;
}

function admin_required(): array {
    $user = auth_required();
    if ($user['role'] !== 'admin') json_error('Acesso restrito a administradores.', 403);
    return $user;
}

function session_token_from_request(): ?string {
    if (!empty($_COOKIE['adforge_session'])) return $_COOKIE['adforge_session'];
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($header, 'Bearer ')) return substr($header, 7);
    return null;
}

function request_body(): array {
    static $body = null;
    if ($body !== null) return $body;
    $raw = file_get_contents('php://input');
    $body = $raw ? (json_decode($raw, true) ?? []) : [];
    return $body;
}

function require_body(string ...$keys): array {
    $data = request_body();
    foreach ($keys as $k) {
        if (!isset($data[$k]) || (is_string($data[$k]) && trim($data[$k]) === '')) {
            json_error("Campo obrigatório ausente: $k");
        }
    }
    return $data;
}

function project_role(int $userId, int $projectId, string $globalRole): string {
    if ($globalRole === 'admin') return 'admin';
    $stmt = db()->prepare('SELECT role FROM project_assignments WHERE project_id = ? AND user_id = ?');
    $stmt->execute([$projectId, $userId]);
    return $stmt->fetchColumn() ?: 'none';
}

function require_project_access(int $userId, int $projectId, string $globalRole, array $allowed = []): string {
    $role = project_role($userId, $projectId, $globalRole);
    if ($role === 'none') json_error('Sem acesso a este projeto.', 403);
    if (!empty($allowed) && !in_array($role, $allowed, true)) json_error('Sem permissão para esta ação.', 403);
    return $role;
}

function encrypt_key(string $plain): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv);
    return base64_encode($iv . $enc);
}

function decrypt_key(string $stored): string {
    $raw = base64_decode($stored);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv) ?: '';
}
