<?php
require "db.php";
require_once 'auth.php';
//require_once 'header.php';
require_once 'security.php';
$q_key = $_GET['question_id'] ?? '';//テストデータ対応

start_sess();
$csrf_token = generate_csrf();
$raw_autosave = $_SESSION['autosave']['answer']['data'] ?? [];

$autosave = [];

foreach ($raw_autosave as $key => $value) {

    $cleanKey = preg_replace('/\[\]$/', '', $key);

    // そのまま入れる（無理に配列化しない）
    $autosave[$cleanKey] = $value;
}
foreach ($autosave as $key => $value) {
    if (is_array($value) && count($value) === 1) {
        $autosave[$key] = $value[0];
    }
}

$r = get_survey_by_key($q_key, "question_key");
if(is_null($r)){
    echo("アンケートは削除されたか未公開です");
}else{
    $json = $r["survey_spec"];
    //以下，本実装

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $errors = [];

        foreach ($json['questions'] as $index => $question) {

            $key = "q{$index}";

            if (!isset($_POST[$key])) {
                $errors[] = "質問".($index + 1)."は必須です";
                continue;
            }
            if (
                !is_array($_POST[$key]) &&
                trim($_POST[$key]) === ''
            ) {
                $errors[] = "質問".($index + 1)."は必須です";
            }
            if (!is_array($_POST[$key]) && !checkWord($_POST[$key])){
                $errors[] = "入力できない文字が含まれます".$_POST[$key]; 
            }  
        }

        foreach ($errors as $error) {
            echo "<p style='color:red'>{$error}</p>";
        }
    }

    echo "<h1>".$r['title']."</h1>";
    echo "<p>".$r['survey_spec']["title"]."</p>";
    echo "<ul>";
    foreach($r["survey_spec"]["Survey_tag"] as $tag){
        echo "<li>{$tag}</li>";
    }
    echo "</ul>";
    $len = count($json["questions"]);
    echo "<form method='post' action='?question_id={$q_key}' id='main-form'>";
    echo "<input type='hidden' name='csrf_token' value='".htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8')."'>";
    $id_cnt = 0;//チェックボックス等のid用変数
    for ($i=0; $i<$len; $i++){
        echo "<div>";
        echo "<h2>質問".($i+1).":".$json["questions"][$i]["label"]."</h2>";
        if($json["questions"][$i]["type"]=="multiple"){
            foreach($json["questions"][$i]["options"] as $item){
                $checked = '';
                $current = $_POST["q{$i}"] ?? $autosave["q{$i}"] ?? [];
                $current = is_array($current) ? $current : [$current];
                if (
                    is_array($current) &&
                    in_array($item, $current, true)
                ){
                    $checked = 'checked';
                }
                $id_cnt+=1;
                echo "<div class='option'>";
                echo "<input type='checkbox' name='q{$i}[]' value='{$item}' id='{$id_cnt}' {$checked}>";
                echo "<label for='{$id_cnt}'>{$item}</label>";
                echo "</div>";
            }
        }elseif($json["questions"][$i]["type"]=="single"){
            foreach($json["questions"][$i]["options"] as $item){
                $checked = '';
                $current = $_POST["q{$i}"] ?? $autosave["q{$i}"] ?? '';
                if ($current === $item){
                    $checked = 'checked';
                }
                $id_cnt+=1;
                echo "<div class='option'>";
                echo "<input type='radio' name='q{$i}' value='{$item}' id='{$id_cnt}' required {$checked}>";
                echo "<label for='{$id_cnt}'>".$item."</label>";
                echo "</div>";
            }
        }elseif($json["questions"][$i]["type"]=="text"){
            $value="";
            $value = htmlspecialchars(
                $autosave["q{$i}"] ?? ($_POST["q{$i}"] ?? ''),
                ENT_QUOTES,
                'UTF-8'
            );
            echo "<input type='text' name='q{$i}' value='{$value}' required>";
        }
        echo "</div>"; 
    }
    if(isset($_SESSION["user_id"])){

    }else{
        echo "<div>";
        echo "<h2>性別を選択してください</h2>";
        echo "<div class='option'>";
        echo "<input type='checkbox' name='Q_gender' value='man'>";
        echo "<label>男性</label>";
        echo "</div>";
        echo "<div class='option'>";
        echo "<input type='checkbox' name='Q_gender' value='woman'>";
        echo "<label>女性</label>";
        echo "</div>";
        echo "<div class='option'>";
        echo "<input type='checkbox' name='Q_gender' value='other'>";
        echo "<label>その他</label>";
        echo "</div>";
        echo "<div class='option'>";
        echo "<input type='checkbox' name='Q\gender' value='doNotAnswer'>";
        echo "<label>回答しない</label>";
        echo "</div>";
        echo "</div><div>";
        echo "<h2>生年月日を入力してください</h2>";
        $max_date = date('Y-m-d');
        echo "<input type='date' name='birthday' min='1900-01-01' max='{$max_date}' required><br>";
        echo "</div>";
    }
    echo "<button type='submit'>送信</button>";
    echo "</form>";
    echo "<script src='../js/api_manager.js'></script>";
}