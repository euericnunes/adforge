<?php
// ============================================================
// AdForge — upload.php
//
// POST  (multipart/form-data)
//   file       → campo do arquivo
//   project_id → ID do projeto
//
// Retorna: { success: true, data: { path, url, mime } }
// ============================================================

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método não permitido.', 405);
}

$user      = auth_required();
$projectId = (int) ($_POST['project_id'] ?? 0);
if (!$projectId) json_error('project_id obrigatório.');

require_project_access($user['id'], $projectId, $user['role'], ['admin', 'editor']);

// ── Validação do arquivo ─────────────────────────────────────
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCodes = [
        UPLOAD_ERR_INI_SIZE   => 'Arquivo maior que o permitido pelo servidor.',
        UPLOAD_ERR_FORM_SIZE  => 'Arquivo maior que o permitido pelo formulário.',
        UPLOAD_ERR_PARTIAL    => 'Upload incompleto.',
        UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo enviado.',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente.',
        UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar no disco.',
    ];
    $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    json_error($errCodes[$code] ?? 'Erro desconhecido no upload.');
}

$file     = $_FILES['file'];
$maxBytes = 5 * 1024 * 1024; // 5 MB

if ($file['size'] > $maxBytes) {
    json_error('Imagem muito grande. Máximo 5 MB.');
}

// Detecta MIME real (não confia no Content-Type do browser)
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mime     = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
if (!in_array($mime, $allowed, true)) {
    json_error('Formato inválido. Use JPG, PNG, WEBP ou GIF.');
}

$ext = match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
};

// ── Cria diretório do projeto ─────────────────────────────────
$projectDir = UPLOAD_DIR . $projectId . '/';
if (!is_dir($projectDir)) {
    if (!mkdir($projectDir, 0755, true)) {
        json_error('Falha ao criar diretório de upload.', 500);
    }
    // Bloqueia listagem do diretório
    file_put_contents($projectDir . '.htaccess', "Options -Indexes\n");
}

// ── Move o arquivo ───────────────────────────────────────────
$filename = bin2hex(random_bytes(16)) . '.' . $ext;
$destPath = $projectDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    json_error('Falha ao salvar o arquivo no servidor.', 500);
}

// Caminho relativo (gravado no banco) e URL pública
$relativePath = $projectId . '/' . $filename;
$publicUrl    = UPLOAD_URL . $relativePath;

json_ok([
    'path' => $relativePath,
    'url'  => $publicUrl,
    'mime' => $mime,
], 201);
