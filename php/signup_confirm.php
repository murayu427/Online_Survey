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
<body class="bg-[#1E2D5A] flex items-center justify-center min-h-screen">
    <div class="bg-[#24376F] p-8 rounded-2xl shadow-2xl border border-white/10 w-full max-w-md">
        <h1 class="text-3xl font-bold mb-8 text-center text-white">登録内容の確認</h1>
        
        <div class="space-y-4 mb-6">
            <div class="border-b border-white/10 pb-2">
                <span class="text-sm text-gray-300 block">ユーザー名</span>
                <span class="text-lg font-medium text-white"><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="border-b pb-2">
                <span class="text-sm text-gray-300 block">パスワード</span>
                <span class="text-lg font-medium text-white">******** (セキュリティのため非表示)</span>
            </div>
            <div class="border-b pb-2">
                <span class="text-sm text-gray-300 block">利用規約への同意</span>
                <span class="text-lg font-medium text-green-400">同意済み</span>
            </div>
        </div>

        <form action="signup_complete.php" method="POST" class="flex space-x-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session_token, ENT_QUOTES, 'UTF-8') ?>">
            <a href="signup.php" class="w-1/2 bg-gray-600 text-center text-white py-2 rounded hover:bg-gray-500 transition font-medium">
                戻って修正
            </a>
            <button type="submit" class="w-1/2 bg-blue-600 text-white py-2 rounded hover:bg-blue-700 transition font-medium">
                登録を確定する
            </button>
        </form>
    </div>
</body>
</html>