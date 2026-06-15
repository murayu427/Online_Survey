<?php
require_once 'db.php';
require_once 'auth.php';
require_once 'security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 既に認証済みの場合は標準のページ（例: survey_form.php や index.php）へ
if (isset($_SESSION['user_id'])) {
    header('Location: survey_form.php');
    exit;
}

// トークン生成
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$error_message = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF検証
    $posted_token = $_POST['csrf_token'] ?? '';
    if ($posted_token !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit("403 Forbidden");
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username !== '' && $password !== '') {
        // データベースからusernameをキーにユーザー情報を取得
        $sql = "SELECT id, uuid, username, password FROM users WHERE username = :username LIMIT 1";
        $stmt = executeQuery($sql, [':username' => $username]);
        $user = $stmt->fetch();

        // ユーザーが存在し、パスワードハッシュが一致するか検証
        if ($user && password_verify($password, $user['password'])) {
            // 仕様書③：セッションハイジャック対策として必ずセッションIDを再発行
            session_regenerate_id(true);

            // 仕様書③：ログインしたユーザー情報を格納
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['uuid'] = $user['uuid'];
            $_SESSION['username'] = $user['username'];

            // 仕様書③：セッションに redirect_url があればそこに移動し、セッション内から削除する
            if (!empty($_SESSION['redirect_url'])) {
                $target_url = $_SESSION['redirect_url'];
                unset($_SESSION['redirect_url']);
                header("Location: " . $target_url);
            } else {
                // なかった場合は標準のページに移動
                header('Location: survey_form.php');
            }
            exit;
        } else {
            $error_message = 'ユーザー名、またはパスワードが正しくありません。';
        }
    } else {
        $error_message = 'すべての項目を入力してください。';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ログイン</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <h1 class="text-2xl font-bold mb-6 text-center text-gray-800">ログイン</h1>

        <?php if ($error_message !== ''): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 text-sm">
                <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form action="signin.php" method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

            <div>
                <label class="block text-gray-700 font-medium mb-1">ユーザー名</label>
                <input type="text" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" class="w-full border rounded p-2 focus:outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>

            <div>
                <label class="block text-gray-700 font-medium mb-1">パスワード</label>
                <input type="password" name="password" class="w-full border rounded p-2 focus:outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700 transition font-medium">
                ログイン
            </button>
        </form>
        <p class="mt-4 text-center text-sm text-gray-600">
            <a href="signup.php" class="text-blue-500 hover:underline">新規会員登録はこちら</a>
        </p>
    </div>
</body>
</html>