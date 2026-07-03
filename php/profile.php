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
    <title>Document</title>
    <link rel='stylesheet' href='../css/footer.css'>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
    <script src='https://cdn.tailwindcss.com'></script>
</head>
<body>
    <?php include "header.php"; ?>
    <main>
        <h1>ユーザ情報変更</h1>
        <p>ユーザ名：<?php echo $_SESSION["username"] ?></p>
        <p>パスワード：******</p>
        <?php
            if($_SERVER["REQUEST_METHOD"] == "POST"){
                echo "";
            }
        ?>
        <form action="post">
            <button type="submit" name="change">変更する<button>
        </form>
    </main>
    <?php include "footer.php"?>
</body>
</html>