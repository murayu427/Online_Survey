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

// データの加工処理（ハッシュ化、UUID生成）
$username = $input['username'];
$hashed_password = password_hash($input['password'], PASSWORD_DEFAULT);
$uuid = bin2hex(random_bytes(16)); // 32文字の疑似UUID（16進数表現）を生成
$agreed_terms = $input['agreed_terms'] ? 1 : 0;

try {
    // データベースへの挿入SQL構築と実行（db.phpのプレースホルダ処理を利用）
    $sql = "INSERT INTO users (uuid, username, password, agreed_terms, created_at) 
            VALUES (:uuid, :username, :password, :agreed_terms, NOW())";
            
    // db.php内に定義されているクエリ実行関数を呼び出し
    executeQuery($sql, [
        ':uuid'         => $uuid,
        ':username'     => $username,
        ':password'     => $hashed_password,
        ':agreed_terms' => $agreed_terms
    ]);

    // 登録処理に成功したら、一時セッションデータをクレンジング
    unset($_SESSION['signup_input']);
    
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
        <h1 class="text-2xl font-bold mb-4 text-green-600">会員登録が完了しました！</h1>
        <p class="text-gray-600 mb-6">アカウントの作成が正常に成功しました。さっそくログインしてサービスを利用しましょう。</p>
        
        <a href="signin.php" class="inline-block bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition font-medium w-full">
            ログイン画面へ移動する
        </a>
    </div>
</body>
</html>