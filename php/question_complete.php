<?php
require_once 'db.php';
require_once 'auth.php';
require_once 'security.php';
require_once 'error.php';

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$csrf_token = $_SESSION['csrf_token'] ?? '';

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    renderError('不正なリクエストです。もう一度お試しください。', 400, 'APP', 'WARNING');
    exit;
}

$q_key = $_GET['question_id'] ?? ($_POST['question_id'] ?? '');
$survey = get_survey_by_key($q_key, 'question_key');
if (!$survey) {
    header('Location: index.php');
    exit;
}
$survey_id = $survey['survey_id'];

$user_id = $_SESSION['user_id'] ?? null;

$answer_data = [];
$spec = $survey['survey_spec'];
foreach (($spec['questions'] ?? []) as $i => $question) {
    $key = "q{$i}";
    if (array_key_exists($key, $_POST)) {
        $answer_data[$key] = $_POST[$key];
    }
}

$success = upsert_response($survey_id, $user_id, $answer_data);

if ($success) {
    unset($_SESSION['saved_answer']);
    unset($_SESSION['csrf_token']);

    header('Location: result.php?question_id=' . rawurlencode($q_key));
    exit;
}

die('保存に失敗しました。');
