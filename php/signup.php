<?php
require_once 'auth.php';
require_once 'security.php';
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// // // 初期アクセス時にセッションを開始し、仕様書①の通り csrf_token を生成・保存
  if (empty($_SESSION['csrf_token'])) {
     if (function_exists('generate_csrf')) {
          $_SESSION['csrf_token'] = generate_csrf();
     } else {
          $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
      }
}
$csrf_token = $_SESSION['csrf_token'];

$error = ["username" => null, "password"=>null];
if($_SERVER["REQUEST_METHOD"] == "POST"){
    if(is_null(get_user_by_name($_POST["username"]))){
        if(!checkWord($_POST["username"])){
            $error["username"] = "入力できない文字が含まれています";
        }
        if(!checkWord($_POST["password"])){
            $error["password"] = "入力できない文字が含まれています";
        }
        if(is_null($error["username"]) and is_null($error["password"])){
            //$_SESSION["csrf_token"] = $_POST["csrf_token"];
            $_SESSION["username"] = $_POST["username"];
            $_SESSION["password"] = $_POST["password"];
            $_SESSION["agreed_terms"] = $_POST["agreed_terms"];
            header("Location: ./signup_confirm.php");
            exit;
        }
    }else{
        $error["username"] = "そのユーザ名は既に存在しています";
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>新規会員登録 - 入力</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#1E2D5A] flex items-center justify-center min-h-screen">
    <div class="bg-[#2A3D73] p-8 rounded-lg shadow-md w-full max-w-md text-white">
        <h1 class="text-2xl font-bold mb-6 text-center text-white">新規会員登録</h1>
        
        <form action="" method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

            <div>
                <label class="block text-white font-medium mb-1">ユーザー名 (ログインID)</label>
                <input type="text" name="username" class = "w-full border rounded p-2 bg-white text-black placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500"maxlength="50" required>
                <?php if(!is_null($error["username"])){echo "<p>". $error['username']. "</p>";}?>
            </div>

            <div>
                <label class="block text-white font-medium mb-1">パスワード</label>
                <input type="password" name="password" class="w-full border rounded p-2 bg-white text-black focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                <?php if(!is_null($error["password"])){echo "<p>". $error['password']. "</p>";}?>
            </div>

            <div class="flex items-center">
                <input type="checkbox" id="agreed_terms" name="agreed_terms" value="1" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded" required>
                <label for="agreed_terms" class="ml-2 block text-sm text-white">
                    <a href="term.php" target="_blank" class="text-blue-400 hover:underline font-semibold">利用規約 <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i></a>に同意する
                </label>
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700 transition font-medium">
                入力内容を確認する
            </button>
        </form>
        <p class="mt-4 text-center text-sm text-white">
            <a href="signin.php" class="text-blue-500 hover:underline">既にアカウントをお持ちの方</a>
        </p>
    </div>
</body>
</html>