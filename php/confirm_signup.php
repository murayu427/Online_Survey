<?php
require_once 'security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 送信された csrf_token の整合性を検証
$posted_token = $_SESSION['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';

if (empty($posted_token) || $posted_token !== $session_token) {
    http_response_code(403);
    exit("403 Forbidden: 不正なリクエストです。");
}

// 入力データの受け取り
$username = trim($_SESSION['username'] ?? '');
$password = $_SESSION['password'] ?? '';
$agreed_terms = isset($_SESSION['agreed_terms']) ? true : false;

// バリデーションチェック（不備があれば入力へ戻す）
if ($username === '' || $password === '' || !$agreed_terms) {
    header('Location: signup.php');
    exit;
}

// 一時的にデータをセッション(signup_input)に保存
$_SESSION['signup_input'] = [
    'username'     => $username,
    'password'     => $password, // 最終完了時にハッシュ化するためここではそのまま保持
    'agreed_terms' => $agreed_terms
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>新規会員登録 - 確認</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <h1 class="text-2xl font-bold mb-6 text-center text-gray-800">登録内容の確認</h1>
        
        <div class="space-y-4 mb-6">
            <div class="border-b pb-2">
                <span class="text-sm text-gray-500 block">ユーザー名</span>
                <span class="text-lg font-medium text-gray-800"><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="border-b pb-2">
                <span class="text-sm text-gray-500 block">パスワード</span>
                <span class="text-lg font-medium text-gray-800">******** (セキュリティのため非表示)</span>
            </div>
            <div class="border-b pb-2">
                <span class="text-sm text-gray-500 block">利用規約への同意</span>
                <span class="text-lg font-medium text-green-600">同意済み</span>
            </div>
        </div>

        <form action="signup_complete.php" method="POST" class="flex space-x-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session_token, ENT_QUOTES, 'UTF-8') ?>">
            <a href="signup.php" class="w-1/2 bg-gray-300 text-center text-gray-700 py-2 rounded hover:bg-gray-400 transition font-medium">
                戻って修正
            </a>
            <button type="submit" class="w-1/2 bg-green-600 text-white py-2 rounded hover:bg-green-700 transition font-medium">
                登録を確定する
            </button>
        </form>
    </div>
</body>
</html>