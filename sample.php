<!-- こんな感じで呼び出してくれれば禁止文字の有無が分かります -->
<?php
require "security.php";
$error = "";
if ($_SERVER["REQUEST_METHOD"]=="POST"){
    if (Word_check($_POST["text"])){
        $error = "禁止文字列が含まれています";
    }else{
        $error = "";
    }
}
?>

<!DOCTYPE html>
<html>
<body>

<form method="post">
    <input type="text" name="text"
           value="<?= htmlspecialchars($_POST['text'] ?? '') ?>">
    <input type="submit" value="送信">
</form>
<?php if($error): ?>
<p style="color:red"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
</body>
</html>