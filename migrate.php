<?php
// Script de migração — DELETE ESTE ARQUIVO após usar.
require __DIR__ . '/api/config.php';

$pdo = db();

// Verifica se a coluna já existe
$cols = $pdo->query("SHOW COLUMNS FROM ads LIKE 'bg_image'")->fetchAll();

if ($cols) {
    echo '<b style="color:green">Coluna bg_image já existe — nada a fazer.</b>';
} else {
    $pdo->exec("ALTER TABLE ads ADD COLUMN bg_image VARCHAR(500) NULL AFTER accent_color");
    echo '<b style="color:green">✅ Migração concluída! Coluna bg_image adicionada.</b><br><br>';
    echo '<b style="color:red">DELETE este arquivo agora via File Manager ou FTP!</b>';
}
