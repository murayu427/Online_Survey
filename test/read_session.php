<?php
// read_session.php
// テスト用：現在のセッションに保存された autosave データを JSON 出力する
if (file_exists(__DIR__ . '/../php/auth.php')) {
    require_once __DIR__ . '/../php/auth.php';
}
if (function_exists('start_sess')) {
    start_sess();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
$data = $_SESSION['autosave'] ?? null;
echo json_encode(['autosave' => $data], JSON_UNESCAPED_UNICODE);
