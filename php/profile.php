<?php
    require_once "db.php";
    require_once "auth.php";
    start_sess();
?>

<!DOCTYPE html>
<html lang="jp">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ユーザ情報変更</title>
    <link rel='stylesheet' href='../css/footer.css'>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
    <script src='https://cdn.tailwindcss.com'></script>
</head>
<body>
    <main>
        <h1>ユーザ情報変更</h1>
        <?php if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["change"])):?>
            <!-- ユーザ情報を変更する場合の入力フォーム  -->
            <form method="post" action="" id="confirm">
                <label for="">ユーザ名：
                    <input type="text" name="newusername">
                </label>
                <label for="">新しいパスワード：
                    <input type="password" name="newpassword">
                </label>
                <label for="">もう一度入力：
                    <input type="password" name="newpassword_cheack">
                </label>
                <button type="submit" name="confirm">確定</button>
            </form>
        <?php elseif($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["confirm"])):?>
            <!-- 入力データのチェック  -->
            <?php if($_POST["newpassword"] != $_POST["newpassword_cheack"]):?>
                <!-- パスワードの不一致  -->
                <form method="post" action="" id="confirm">
                    <label for="">ユーザ名：
                        <input type="text" name="newusername" value=<?php echo $_POST["newusername"]?>>
                    </label>
                    <label for="">新しいパスワード：
                        <input type="password" name="newpassword" value=<?php echo $_POST["newpassword"]?>>
                        <p>パスワードが不一致です</p>
                    </label>
                    <label for="">もう一度入力：
                        <input type="password" name="newpassword_cheack" value=<?php echo $_POST["newpassword"]?>>
                    </label>
                    <button type="submit" name="confirm">確定</button>
                </form>
            <?php else:?>
                <!--  パスワードが一致入力  -->
                <?php $r = update_user($_SESSION["user_id"], $_POST["newusername"], password_hash($_POST["newpassword"],PASSWORD_DEFAULT));
                if($r){
                    $user_id = $_SESSION['user_id'];
                    del_sess();
                    start_sess();
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $_POST['newusername'];
                    $_SESSION['last_acc'] = time();
                    echo "<script>setTimeout(() => {
                        location.href = '/php/index.php';
                        }, 0); </script>";
                }else{
                    echo "失敗";
                }
                ?>
            <?php endif?>
        <?php else:?>
            <p>ユーザ名：<?php echo $_SESSION["username"] ?></p>
            <p>パスワード：******</p>
            <form method="post" action="">
                <button type="submit" name="change">変更する<button>
            </form>
        <?php endif?>
    </main>
    <?php include "footer.php"?>
</body>
</html>