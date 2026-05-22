<?php
// AdForge — image.php
// POST { prompt, aspect? } → { url: 'https://...' }

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método inválido.', 405);

auth_required();

$data   = require_body('prompt');
$prompt = trim($data['prompt']);
if (!$prompt) json_error('Prompt é obrigatório.');

$stmt = db()->prepare("SELECT api_key, enabled FROM ai_settings WHERE provider = 'openai'");
$stmt->execute();
$row = $stmt->fetch();

if (!$row || !$row['enabled']) json_error('Provedor OpenAI não habilitado. Ative-o em Configurações → Provedor de IA.');
if (empty($row['api_key']))   json_error('Chave de API OpenAI não configurada.');

$key = decrypt_key($row['api_key']);
if (!$key) json_error('Erro ao decifrar chave de API.');

$aspect = $data['aspect'] ?? 'square';
$size   = match ($aspect) {
    'landscape' => '1792x1024',
    'portrait'  => '1024x1792',
    default     => '1024x1024',
};

$body = json_encode([
    'model'           => 'dall-e-3',
    'prompt'          => $prompt,
    'n'               => 1,
    'size'            => $size,
    'quality'         => 'standard',
    'response_format' => 'url',
]);

$ch = curl_init('https://api.openai.com/v1/images/generations');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 60,
]);
$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($res === false) json_error('Falha na conexão: ' . $err);

$d = json_decode($res, true);
if (isset($d['error'])) json_error($d['error']['message'] ?? 'Erro DALL-E 3');

$imageUrl = $d['data'][0]['url'] ?? null;
if (!$imageUrl) json_error('Sem URL na resposta da API.');

// Baixa e salva localmente (URL da OpenAI expira em ~1h)
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

$ch2 = curl_init($imageUrl);
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
$img = curl_exec($ch2);
curl_close($ch2);

if (!$img) json_error('Falha ao baixar imagem gerada.');

$filename = 'bg_' . uniqid() . '.png';
file_put_contents(UPLOAD_DIR . $filename, $img);

json_ok(['url' => UPLOAD_URL . $filename]);
