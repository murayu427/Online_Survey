<?php
    ob_start();

    require_once "db.php";
    require_once "auth.php";
    require_once "security.php";
    start_sess();
    login_check();
    $csrf_token = generate_csrf();

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $posted_token = $_POST['csrf_token'] ?? '';
        check_csrf($posted_token);
    }
?>

<!DOCTYPE html>
<html lang="jp">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ユーザ情報変更</title>
    <link rel='stylesheet' href='../css/footer.css'>
    <link rel='stylesheet' href='../css/profile.css'>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
    <script src='https://cdn.tailwindcss.com'></script>
</head>
<body class="profile-page">
    <?php include 'header.php'; ?>
    <main class="profile-main">
        <div class="profile-card">
            <h1 class="profile-title">ユーザ情報変更</h1>
            <?php if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["change"])):?>
                <p class="profile-desc">現在のパスワードを入力してください。</p>
                <form method="post" action="" id="verify" class="profile-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="profile-field">
                        <label class="profile-label">現在のパスワード</label>
                        <input type="password" name="current_password" required class="profile-input">
                    </div>
                    <button type="submit" name="verify" class="profile-button">確認</button>
                </form>
            <?php elseif($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["verify"])):?>
                <?php
                    $username = $_SESSION['username'] ?? null;
                    $pw_ok = false;
                    if($username){
                        $user = get_user_by_name($username);
                        if($user && isset($user['password_hash'])){
                            $pw_ok = password_verify($_POST['current_password'] ?? '', $user['password_hash']);
                        }
                    }
                ?>
                <?php if(!$pw_ok): ?>
                    <div class="profile-alert">現在のパスワードが違います。</div>
                    <form method="post" action="" id="verify" class="profile-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="profile-field">
                            <label class="profile-label">現在のパスワード</label>
                            <input type="password" name="current_password" required class="profile-input">
                        </div>
                        <button type="submit" name="verify" class="profile-button">確認</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="" id="confirm" class="profile-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="profile-field">
                            <label class="profile-label">ユーザ名</label>
                            <input type="text" name="newusername" value="<?php echo htmlspecialchars($_SESSION['username'], ENT_QUOTES); ?>" class="profile-input">
                        </div>
                        <div class="profile-field">
                            <label class="profile-label">新しいパスワード</label>
                            <input type="password" name="newpassword" class="profile-input">
                        </div>
                        <div class="profile-field">
                            <label class="profile-label">もう一度入力</label>
                            <input type="password" name="newpassword_cheack" class="profile-input">
                        </div>
                        <button type="submit" name="confirm" class="profile-button">確定</button>
                    </form>
                <?php endif; ?>
            <?php elseif($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["confirm"])):?>
                <?php if(($_POST["newpassword"] ?? '') !== ($_POST["newpassword_cheack"] ?? '')):?>
                    <form method="post" action="" id="confirm" class="profile-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="profile-field">
                            <label class="profile-label">ユーザ名</label>
                            <input type="text" name="newusername" value="<?php echo htmlspecialchars($_POST['newusername'] ?? '', ENT_QUOTES); ?>" class="profile-input">
                        </div>
                        <div class="profile-field">
                            <label class="profile-label">新しいパスワード</label>
                            <input type="password" name="newpassword" class="profile-input">
                        </div>
                        <div class="profile-field">
                            <label class="profile-label">もう一度入力</label>
                            <input type="password" name="newpassword_cheack" class="profile-input">
                        </div>
                        <div class="profile-alert">パスワードが不一致です</div>
                        <button type="submit" name="confirm" class="profile-button">確定</button>
                    </form>
                <?php else:?>
                    <?php
                        $newusername = trim($_POST['newusername'] ?? '');
                        if(!checkWord($newusername)){
                            ?>
                            <form method="post" action="" id="confirm" class="profile-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="profile-field">
                                    <label class="profile-label">ユーザ名</label>
                                    <input type="text" name="newusername" value="<?php echo htmlspecialchars($newusername, ENT_QUOTES); ?>" class="profile-input">
                                </div>
                                <div class="profile-field">
                                    <label class="profile-label">新しいパスワード</label>
                                    <input type="password" name="newpassword" class="profile-input">
                                </div>
                                <div class="profile-field">
                                    <label class="profile-label">もう一度入力</label>
                                    <input type="password" name="newpassword_cheack" class="profile-input">
                                </div>
                                <div class="profile-alert">ユーザ名に不正な文字が含まれています</div>
                                <button type="submit" name="confirm" class="profile-button">確定</button>
                            </form>
                            <?php
                        } else {
                            $r = update_user($_SESSION["user_id"], $newusername, password_hash($_POST["newpassword"],PASSWORD_DEFAULT));
                            if($r){
                                $user_id = $_SESSION['user_id'];
                                session_regenerate_id(true);
                                $_SESSION['user_id'] = $user_id;
                                $_SESSION['username'] = $newusername;
                                $_SESSION['last_acc'] = time();
                                header('Location: /php/index.php');
                                exit;
                            }else{
                                echo "<div class=\"profile-alert\">失敗</div>";
                            }
                        }
                    ?>
                <?php endif?>
            <?php else:?>
                <div class="profile-summary">
                    <p>ユーザ名：<?php echo htmlspecialchars($_SESSION["username"], ENT_QUOTES, 'UTF-8'); ?></p>
                    <p>パスワード：******</p>
                </div>
                <form method="post" action="" class="profile-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="change" class="profile-button">変更する</button>
                </form>
            <?php endif?>
        </div>
    </main>
    <div class="profile-footer-wrap">
        <?php include "footer.php"?>
    </div>
<?php ob_end_flush(); ?>
</body>
</html>