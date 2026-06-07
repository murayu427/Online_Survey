<?php
require "db.php";
$q_key = $_GET['question_id'] ?? '';//テストデータ対応

$r = get_survey_by_key($q_key, "question_key");
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
echo "<form method='post' action='?question_id={$q_key}'>";
for ($i=0; $i<$len; $i++){
    echo "<div>";
    echo "<h2>質問".($i+1).":".$json["questions"][$i]["label"]."</h2>";
    if($json["questions"][$i]["type"]=="multiple"){
        foreach($json["questions"][$i]["options"] as $item){
            $checked = '';
            if (isset($_POST["q{$i}"]) && is_array($_POST["q{$i}"]) && in_array($item, $_POST["q{$i}"])){
                $checked = 'checked';
            }
            echo "<input type='checkbox' name='q{$i}[]' value='{$item}' {$checked}>";
            echo "<label>{$item}</label><br>";
        }
    }elseif($json["questions"][$i]["type"]=="single"){
        foreach($json["questions"][$i]["options"] as $item){
            $checked = '';
            if (
                isset($_POST["q{$i}"])
                && $_POST["q{$i}"] === $item
            ){
                $checked = 'checked';
            }
            echo "<input type='radio' name='q{$i}' value='{$item}' required {$checked}>";
            echo "<label>".$item."</label><br>";
        }
    }elseif($json["questions"][$i]["type"]=="text"){
        $value="";
        $value = htmlspecialchars(
            $_POST["q{$i}"] ?? '',
            ENT_QUOTES,
            'UTF-8'
        );
        echo "<input type='text' name='q{$i}' value='{$value}' required>";
    }
    echo "</div>"; 
}
echo "<button type='submit'>送信</button>";
echo "</form>";