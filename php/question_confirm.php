<?php
require_once "db.php";
require_once 'auth.php';
require_once 'security.php';
require_once 'error.php';

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$q_key = $_GET['question_id'] ?? ($_POST['question_id'] ?? '');

if ($q_key === '') {
    renderError('不正なアクセスです', 400, 'APP', 'WARNING', null, 'question_id missing');
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$csrf_token = $_SESSION['csrf_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        renderError('不正なリクエストです。もう一度お試しください。', 400, 'APP', 'WARNING');
        exit;
    }
} else {
    header('Location: question.php?question_id=' . rawurlencode($q_key));
    exit;
}

$r = get_survey_by_key($q_key, 'question_key');
if (is_null($r)) {
    renderError('存在しないページです', 500, 'APP', 'WARNING', null, '存在しないページ');
    exit;
}

$json = $r['survey_spec'];

echo "<title>" . h($r['title']) . "</title>";
echo "<main>";
echo "<h1>回答内容の確認</h1>";
echo "<p>" . h($r['survey_spec']['title'] ?? '') . "</p>";
echo "<div id='tag'>";
echo "<ul>";
foreach (($r['survey_spec']['Survey_tag'] ?? []) as $tag) {
    echo "<li>" . h($tag) . "</li>";
}
echo "</ul></div>";

echo "<p>以下の内容で送信します。内容を修正する場合は「修正する」を押してください。</p>";

echo "<form method='post' action='question_complete.php?question_id=" . h($q_key) . "'>";
echo "<input type='hidden' name='csrf_token' value='" . h($csrf_token) . "'>";
echo "<input type='hidden' name='question_id' value='" . h($q_key) . "'>";

foreach (($json['questions'] ?? []) as $i => $question) {
    $key = "q{$i}";
    echo "<div class='question'>";
    echo "<h3>" . h($question['label'] ?? '') . "</h3>";

    $answer_value = $_POST[$key] ?? null;
    if (is_array($answer_value)) {
        foreach ($answer_value as $ans) {
            echo "<div>" . h($ans) . "</div>";
        }
        foreach ($answer_value as $ans) {
            echo "<input type='hidden' name='{$key}[]' value='" . h($ans) . "'>";
        }
    } else {
        echo "<div>" . h($answer_value ?? '') . "</div>";
        echo "<input type='hidden' name='{$key}' value='" . h($answer_value ?? '') . "'>";
    }

    echo "</div>";
}

if (isset($_POST['Q_gender'])) {
    echo "<div class='question'>";
    echo "<h3>性別</h3>" . h($_POST['Q_gender']);
    echo "<input type='hidden' name='Q_gender' value='" . h($_POST['Q_gender']) . "'>";
    echo "</div>";
}
if (isset($_POST['birthday'])) {
    echo "<div class='question'>";
    echo "<h3>生年月日</h3>" . h($_POST['birthday']);
    echo "<input type='hidden' name='birthday' value='" . h($_POST['birthday']) . "'>";
    echo "</div>";
}

echo "<button type='submit' formaction='question.php?question_id=" . h($q_key) . "'>修正する</button>";
echo "<button type='submit'>送信する</button>";
echo "</form>";
echo "<script src='../js/api_manager.js'></script>";
echo "</main>";
require_once "footer.php";
echo "</body>";
