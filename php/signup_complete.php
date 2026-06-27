<?php
require_once 'db.php';
require_once 'security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRFの再検証
$posted_token = $_POST['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';

if (empty($posted_token) || $posted_token !== $session_token) {
    http_response_code(403);
    exit("403 Forbidden: 不正なリクエストです。");
}

// セッションから一時保存データの取得
$input = $_SESSION['signup_input'] ?? null;
if (!$input) {
    header('Location: signup.php');
    exit;
}

// データの取得
$username = $input['username'];
$hashed_password = $input['password']; 

// ハッシュ化
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
    // -----------------------------------------------------------------
    // 【修正】共通関数 insert_user() を呼び出して安全にユーザー登録を実行
    // 不要な uuid、agreed_terms の処理および二重ハッシュ化を撤廃
    // -----------------------------------------------------------------
    $result = insert_user($username, $hashed_password);

    if ($result) {
        // 登録処理に成功したら、一時セッションデータをクレンジング
        unset($_SESSION['signup_input']);
    } else {
        throw new Exception("ユーザーの挿入に失敗しました。");
    }
    
} catch (Exception $e) {
    http_response_code(500);
    exit("500 Internal Server Error: データベース登録中にエラーが発生しました。");
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>新規会員登録 - 完了</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md text-center">
        <h1 class="text-2xl font-bold mb-4 text-green-600">登録が完了しました！</h1>
        <p class="text-gray-600 mb-6">アカウントの作成が正常に完了いたしました。</p>
        <a href="signin.php" class="inline-block w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700 transition font-medium">
            ログイン画面へ
        </a>
    </div>
</body>
</html>