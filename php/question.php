<?php
require "db.php";
require_once 'auth.php';
require_once 'security.php';
require_once 'error.php';

function checkEndAt($end_at,$q_key){
    $now_dt = new DateTime();
    $end_at_dt = new DateTime($end_at);
    if($end_at_dt > $now_dt){
        return;
    }else{
        header("Location: result.php?id=".$q_key);
        exit();
    }
}

function checkstartAt($start_at,$q_key){
    $now_dt = new DateTime();
    $start_at_dt = new DateTime($start_at);
    if($start_at_dt < $now_dt){
        return;
    }else{
        $_SESSION['flash_message'] = "このアンケートはまだ開始されていません。";
        header("Location: index.php");
        exit();
    }
}

//セッションに回答したアンケートIDが保存されているか確認
function check_Session_Answers($survey_id) {
    if (!isset($_SESSION['answered_surveys']) || !is_array($_SESSION['answered_surveys'])) {
        return false;
    }
    return in_array($survey_id, $_SESSION['answered_surveys'], true);
}

$q_key = $_GET['question_id'] ?? $_GET['id'] ?? '';

if (session_status() === PHP_SESSION_NONE) {
    start_sess();
}

if (empty($_SESSION['csrf_token'])) {
    if (function_exists('generate_csrf')) {
        $_SESSION['csrf_token'] = generate_csrf();
    } else {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
$csrf_token = $_SESSION['csrf_token'];

$raw_autosave = $_SESSION['autosave']['answer'][$q_key]['data'] ?? [];

$autosave = [];
$errors = [];
$previous_response = null;
$previous_answers = [];

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

//CSS読み込み
echo "<head><link rel='stylesheet' href='../css/question.css'><link rel='stylesheet' href='../css/footer.css'>";
echo "<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>";
echo "<script src='https://cdn.tailwindcss.com'></script></head>";

$r = get_survey_by_key($q_key, "question_key");
if(is_null($r)){
    renderError('存在しないページです',500,'APP','WARNING',Null,'存在しないページ');
}else{
    $json = $r["survey_spec"];
    checkstartAt($r["start_at"], $q_key);
    checkEndAt($r["end_at"], $q_key);
    //ログイン済みの場合、過去の回答を取得
    $current_user_id = $_SESSION['user_id'] ?? null;
    if ($current_user_id !== null && !empty($r['survey_id'])) {
        $previous_response = get_response_by_survey_and_user((int)$r['survey_id'], (int)$current_user_id);
    }else if ($current_user_id === null && !empty($r['survey_id'])) {
        //未ログインの場合、セッションに回答したアンケートIDが保存されているか確認
        //セッションに回答したアンケートIDが保存されている場合、トップページへリダイレクト
        if (check_Session_Answers((int)$r['survey_id'])) {
           // セッションにメッセージを一時保存
            $_SESSION['flash_message'] = "このアンケートは既に回答されています。";
            header("Location: index.php");
            exit();
        }
    }
    $previous_answers = is_array($previous_response['answer_data'] ?? null) ? $previous_response['answer_data'] : [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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
    }

    echo "<title>".$r['title']."</title>";
    echo "<body>";
    include "header.php";
    echo "<main>";
    echo "<h1>".$r['title']."</h1>";
    echo "<p>".$r['survey_spec']['description']."</p>";
    echo "<div id='tag'>";
    echo "<ul>";
    foreach($r["survey_spec"]["Survey_tag"] as $tag){
        echo "<li>{$tag}</li>";
    }
    echo "</ul></div>";
    if(!is_null($errors)){
        foreach ($errors as $error) {
            echo "<p style='color:red'>{$error}</p>";
        }
    }
    $len = count($json["questions"]);
    echo "<form method='post' action='question_confirm.php?question_id={$q_key}' id='main-form'>";
    echo "<input type='hidden' name='question_id' value='" . htmlspecialchars($q_key, ENT_QUOTES, 'UTF-8') . "'>";
    echo "<input type='hidden' name='csrf_token' value='".htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8')."'>";
    $id_cnt = 0;//チェックボックス等のid用変数
    for ($i=0; $i<$len; $i++){
        echo "<div class='question'>";
        echo "<h2>質問".($i+1).":".$json["questions"][$i]["label"]."</h2>";
        if($json["questions"][$i]["type"]=="multiple"){
            foreach($json["questions"][$i]["options"] as $item){
                $checked = '';
                $current = $_POST["q{$i}"] ?? $previous_answers["q{$i}"] ?? $autosave["q{$i}"] ?? [];
                $current = is_array($current) ? $current : [$current];
                if (
                    is_array($current) &&
                    in_array($item, $current, true)
                ){
                    $checked = 'checked';
                }
                $id_cnt+=1;
                echo "<label class='option'>";
                echo "<input type='checkbox' name='q{$i}[]' value='{$item}' {$checked}>";
                echo "{$item}</label>";
            }
        }elseif($json["questions"][$i]["type"]=="single"){
            foreach($json["questions"][$i]["options"] as $item){
                $checked = '';
                $current = $_POST["q{$i}"] ?? $previous_answers["q{$i}"] ?? $autosave["q{$i}"] ?? '';
                if ($current === $item){
                    $checked = 'checked';
                }
                $id_cnt+=1;
                echo "<label class='option'>";
                echo "<input type='radio' name='q{$i}' value='{$item}' required {$checked}>";
                echo "{$item}</label>";
            }
        }elseif($json["questions"][$i]["type"]=="text"){
            $value="";
            $value = htmlspecialchars(
                $_POST["q{$i}"] ?? $previous_answers["q{$i}"] ?? $autosave["q{$i}"] ?? '',
                ENT_QUOTES,
                'UTF-8'
            );
            //echo "<input type='text' name='q{$i}' value='{$value}' required>";
            echo "<div class='q_text'>";
            echo "<textarea name='q{$i}' maxlength='500' required>{$value}</textarea>";
            echo "</div>";
        }
        echo "</div>"; 
    }
    echo "<div id='submit'><button type='submit' class='lift-button'>送信画面へ</button></div>";
    echo "</form>";
    echo "<script src='../js/api_manager.js'></script>";
    echo "</main>";
    require_once "footer.php";
    echo "</body>";
}