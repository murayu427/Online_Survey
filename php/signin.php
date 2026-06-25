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
    // $posted_token = $_POST['csrf_token'] ?? '';
    // if ($posted_token !== $_SESSION['csrf_token']) {
    //     http_response_code(403);
    //     exit("403 Forbidden");
    // }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';


    if ($username !== '' && $password !== '') {
        // -----------------------------------------------------------------
        // 【修正】共通関数 get_user_by_name() を利用してユーザー情報を取得
        // -----------------------------------------------------------------
        $user = get_user_by_name($username);
        // 該当するユーザーが存在し、パスワードが一致するか検証
        #if ($user && password_verify($password, $user['password_hash'])) {
        if(!is_null($user) && $password == $user['password_hash']){
            // セッション固定攻撃対策：ログイン成功時にセッションIDを再生成
            session_regenerate_id(true);

            // セッションにユーザー情報を格納
            // $_SESSION['user_id'] = $user['id']; 
            // $_SESSION['username'] = $user['username'];
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['account_name'];
            $_SESSION['last_acc'] = time(); // タイムアウト判定用のタイムスタンプ

            // 事前に遷移元のURLが記録されていればそこへ、なければ管理画面等へリダイレクト
            $redirect_url = $_SESSION['return_to'] ?? 'survey_form.php';
            unset($_SESSION['return_to']); // 使い終わったURLは削除
            header("Location: " . $redirect_url);
            exit;
        } else {
            $error_message = 'ユーザー名またはパスワードが正しくありません。';
        }
    } else {
        $error_message = 'ユーザー名とパスワードを入力してください。';
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

        <div class="mt-4 text-center">
            <p class="text-sm text-gray-600">
                アカウントをお持ちでないですか？ 
                <a href="signup.php" class="text-blue-500 hover:underline">新規登録</a>
            </p>
        </div>
    </div>
</body>
</html>