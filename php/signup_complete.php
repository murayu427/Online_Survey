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
$password = $input['password']; 

$hashed_password = $input['password']; 

// ハッシュ化
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
        // ユーザー登録
    $result = insert_user($username, $hashed_password);

    if (!$result) {
        throw new Exception("ユーザーの挿入に失敗しました。");
    }

    // 登録したユーザー情報を取得
    $user = get_user_by_name($username);

    if ($user === null) {
        throw new Exception("登録したユーザーを取得できませんでした。");
    }

    // ログイン状態にする
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['username'] = $user['account_name'];
    $_SESSION['last_acc'] = time();

    // 一時保存データを削除
    unset($_SESSION['signup_input']);

    // 元のURLが保存されていればそこへ戻る
    if (!empty($_SESSION['return_to'])) {
        $url = $_SESSION['return_to'];
        unset($_SESSION['return_to']);
        header("Location: $url");
        exit;
    }

    // なければトップページへ
    header("Location: index.php");
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    exit("500 Internal Server Error: データベース登録中にエラーが発生しました。");
}
?>
<!-- <!DOCTYPE html>
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
</html> -->