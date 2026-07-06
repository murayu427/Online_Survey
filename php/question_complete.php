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
    renderError('指定されたアンケートが見つかりません。', 404, 'app', 'WARNING', null, 'Survey Not Found');
}
$survey_id = $survey['survey_id'];
$survey_title = (string)($survey['title'] ?? '');
if ($survey_title === '' && isset($survey['survey_spec']['title'])) {
    $survey_title = (string)$survey['survey_spec']['title'];
}

$user_id = $_SESSION['user_id'] ?? null;

$answer_data = [];
$spec = $survey['survey_spec'];
foreach (($spec['questions'] ?? []) as $i => $question) {
    $key = "q{$i}";
    if (array_key_exists($key, $_POST)) {
        $answer_data[$key] = $_POST[$key];
    }
}

$birth_date_str = $_POST['birthday'] ?? '';

// 性別の取り出しとマッピング（DB側は整数カラムなのでコードに変換）
$gender_raw = $_POST['Q_gender'] ?? null;
$gender_map = [
    'man' => 1,
    'woman' => 2,
    'other' => 3,
    'doNotAnswer' => null,
];
$gender = array_key_exists($gender_raw, $gender_map) ? $gender_map[$gender_raw] : null;

// 生年月日から年齢を算出（年齢は満年齢）
$age = null;
if (!empty($birth_date_str)) {
    $dob = DateTime::createFromFormat('Y-m-d', $birth_date_str);
    if ($dob !== false) {
        $today = new DateTime('now');
        $interval = $today->diff($dob);
        $age = $interval->y;
    }
}

$success = upsert_response($survey_id, $user_id, $answer_data, $gender, $age);

if ($success) {
    unset($_SESSION['autosave']);
    unset($_SESSION['csrf_token']);

    echo "<title>送信完了 - " . h($survey_title) . "</title>";
    echo "<head><link rel='stylesheet' href='../css/question_complete.css'><link rel='stylesheet' href='../css/footer.css'>";
    echo "<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>";
    echo "<script src='https://cdn.tailwindcss.com'></script></head>";
    echo "<body>";
    include "header.php";
    echo "<main class='text-center py-20'>";
    echo "<h1 class='complete-title'>送信が完了しました</h1>";
    echo "<h2 class='text-3xl font-semibold mb-10 text-blue-200'>アンケート: 「" . h($survey_title) . "」</h2>";
    echo "<p class='text-xl mb-16'>アンケートへのご協力、ありがとうございました。</p>";
    
    echo "<div class='flex justify-center gap-10'>";
    
    echo "<a href='result.php?question_id=" . h($q_key) . "' 
                 class='bg-blue-500 hover:bg-blue-700 text-white font-bold py-6 px-12 rounded-xl text-2xl transition flex items-center shadow-lg hover:shadow-xl lift-button'>
             <i class='fa-solid fa-chart-pie mr-3'></i>集計結果を見る
          </a>";
    
    echo "<a href='index.php' 
                 class='bg-gray-500 hover:bg-gray-700 text-white font-bold py-6 px-12 rounded-xl text-2xl transition flex items-center shadow-lg hover:shadow-xl lift-button'>
             <i class='fa-solid fa-house mr-3'></i>ホームに戻る
          </a>";

    echo "</div>";
    echo "</main>";
    require_once "footer.php";
    echo "</body>";
    exit;
}

renderError('データの保存に失敗しました。', 500, 'db', 'ERROR');
