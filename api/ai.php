<?php
// AdForge — ai.php
// POST { provider, model, system_prompt, user_prompt } → { content: string }

set_time_limit(120); // geração de HTML pode levar até 2 min

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Método inválido.', 405);

auth_required();

$data       = require_body('provider', 'model', 'user_prompt');
$provider   = $data['provider'];
$model      = $data['model'];
$system     = $data['system_prompt'] ?? '';
$userPrompt = $data['user_prompt'];

$validProviders = ['anthropic', 'openai', 'google', 'groq', 'deepseek', 'mistral'];
if (!in_array($provider, $validProviders, true)) json_error('Provedor inválido.');

$stmt = db()->prepare('SELECT api_key, enabled FROM ai_settings WHERE provider = ?');
$stmt->execute([$provider]);
$row = $stmt->fetch();

if (!$row || !$row['enabled'])   json_error('Provedor não habilitado.');
if (empty($row['api_key']))      json_error('Chave de API não configurada.');

$key = decrypt_key($row['api_key']);
if (!$key) json_error('Erro ao decifrar chave de API.');

$content = '';

if ($provider === 'anthropic') {
    $body = json_encode([
        'model'      => $model,
        'max_tokens' => 4096,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $userPrompt]],
    ]);
    $res = curl_post('https://api.anthropic.com/v1/messages', $body, [
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ]);
    $d = json_decode($res, true);
    if (isset($d['error'])) json_error($d['error']['message'] ?? 'Erro Anthropic');
    $content = $d['content'][0]['text'] ?? '';

} elseif (in_array($provider, ['openai', 'groq', 'deepseek', 'mistral'], true)) {
    $urls = [
        'openai'   => 'https://api.openai.com/v1/chat/completions',
        'groq'     => 'https://api.groq.com/openai/v1/chat/completions',
        'deepseek' => 'https://api.deepseek.com/chat/completions',
        'mistral'  => 'https://api.mistral.ai/v1/chat/completions',
    ];
    $url = $urls[$provider];
    $body = json_encode([
        'model'      => $model,
        'max_tokens' => 4096,
        'messages'   => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userPrompt],
        ],
    ]);
    $res = curl_post($url, $body, [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
    ]);
    $d = json_decode($res, true);
    if (isset($d['error'])) json_error($d['error']['message'] ?? 'Erro ' . $provider);
    $content = $d['choices'][0]['message']['content'] ?? '';

} elseif ($provider === 'google') {
    $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
    $body = json_encode([
        'contents'         => [['parts' => [['text' => $system . "\n\n" . $userPrompt]]]],
        'generationConfig' => ['maxOutputTokens' => 1024],
    ]);
    $res = curl_post($url, $body, ['Content-Type: application/json']);
    $d   = json_decode($res, true);
    if (isset($d['error'])) json_error($d['error']['message'] ?? 'Erro Google');
    $content = $d['candidates'][0]['content']['parts'][0]['text'] ?? '';
}

if (!$content) json_error('Resposta vazia da IA.');
json_ok(['content' => $content]);

// ── Helper ───────────────────────────────────────────────────
function curl_post(string $url, string $body, array $headers): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 90,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new \RuntimeException('Falha na requisição: ' . $err);
    return $res;
}
