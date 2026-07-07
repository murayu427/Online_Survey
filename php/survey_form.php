<?php
// ========================================
// survey_form.php（アンケート作成・編集）
// ========================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'auth.php';
require_once 'db.php';
require_once 'security.php';
require_once 'logger.php';
require_once __DIR__ . '/error.php';

// ----------------------------------------
// 1. ログインチェック
// ----------------------------------------
login_check();
$user_id = $_SESSION['user_id'] ?? null;

// ----------------------------------------
// 2. CSRF トークン
// ----------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ----------------------------------------
// 3. 編集モード判定
// ----------------------------------------
$edit_mode = false;
$survey_key = null;

$spec = [
    'title'       => '',
    'Survey_tag'  => [],
    'aggregate'   => [
        'gender'       => false,
        'age'          => false,
        'gender_split' => false,
    ],
    'questions'   => [],
    'start_at'    => '',
    'end_at'      => '',
];

if (!empty($_GET['key'])) {
    $survey_key = $_GET['key'];
    $survey = get_survey_by_key($survey_key, "question_key");

    if ($survey && $survey['creator_id'] == $user_id) {
        $edit_mode = true;
        $spec = $survey['survey_spec'];
    } else {
        renderError('不正アクセスです。編集権限がありません。', 403, 'auth', 'WARNING');
    }
}

$errors = [];

// ----------------------------------------
// 4. POST 受信
// ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        renderError('不正なリクエストです（CSRF）。', 403, 'app', 'WARNING');
    }

    // 入力値取得
    $title       = $_POST['title']       ?? '';
    $description = $_POST['description'] ?? '';
    $tags_raw    = $_POST['tags']        ?? '';

    $start_at = $_POST['start_at'] ?? '';
    $end_at   = $_POST['end_at']   ?? '';

    if ($start_at === '' || $end_at === '') {
        $errors[] = '開始日時と終了日時を入力してください。';
    }

    $agg_gender       = !empty($_POST['agg_gender']);
    $agg_age          = !empty($_POST['agg_age']);
    $agg_gender_split = !empty($_POST['agg_gender_split']);

    $q_labels         = $_POST['q_label']         ?? [];
    $q_types          = $_POST['q_type']          ?? [];
    $q_result_display = $_POST['q_result_display'] ?? [];
    $q_options        = $_POST['q_options']       ?? [];

    // ------------------------------
    // バリデーション
    // ------------------------------
    if (!checkWord($title, 100)) {
        $errors[] = 'タイトルに禁止文字または文字数超過があります。';
    }
    if (!checkWord($description, 500)) {
        $errors[] = '説明文に禁止文字または文字数超過があります。';
    }

    // タグ
    $tags = [];
    if (trim($tags_raw) !== '') {
        foreach (explode(',', $tags_raw) as $t) {
            $t = trim($t);
            if ($t === '') continue;
            if (!checkWord($t, 50)) {
                $errors[] = 'タグに禁止文字または文字数超過があります。';
            } else {
                $tags[] = $t;
            }
        }
    }

    // 質問
    $questions = [];
    foreach ($q_labels as $i => $label) {

        $label = trim($label);
        if ($label === '') continue;

        if (!checkWord($label, 200)) {
            $errors[] = '質問文に禁止文字または文字数超過があります。';
            continue;
        }

        $type = $q_types[$i] ?? 'single';
        if (!in_array($type, ['single', 'multiple', 'text'], true)) {
            $errors[] = '質問タイプが不正です。';
            continue;
        }

        $display = $q_result_display[$i] ?? 'bar';
        if (!in_array($display, ['bar', 'pie', 'line', 'table'], true)) {
            $display = 'bar';
        }

        $opts = [];
        if ($type !== 'text') {
            $opt_raw = $q_options[$i] ?? '';
            foreach (explode(',', $opt_raw) as $o) {
                $o = trim($o);
                if ($o === '') continue;
                if (!checkWord($o, 100)) {
                    $errors[] = '選択肢に禁止文字または文字数超過があります。';
                } else {
                    $opts[] = $o;
                }
            }
        }

        $questions[] = [
            'label'          => $label,
            'type'           => $type,
            'options'        => $type === 'text' ? [] : $opts,
            'result_display' => $display,
        ];
    }

    // 空の要素だけの場合は空扱いにする
    $questions = array_filter($questions, fn($q) => !empty($q['label']));

    if (empty($questions)) {
        $errors[] = '少なくとも1つの質問を作成してください。';
    }

    // ------------------------------
    // エラーなし → DB 登録
    // ------------------------------
    if (empty($errors)) {

        $spec = [
            'title'       => $description,
            'Survey_tag'  => $tags,
            'aggregate'   => [
                'gender'       => $agg_gender,
                'age'          => $agg_age,
                'gender_split' => $agg_gender_split,
            ],
            'questions'   => $questions,
            'start_at'    => $start_at,
            'end_at'      => $end_at,
        ];

        try {

            if ($edit_mode && $survey_key !== null) {

                update_survey(
                    $survey_key,
                    $title,
                    $spec,
                    $user_id,
                    $start_at,
                    $end_at
                );

                header("Location: question.php?question_id=" . urlencode($survey_key));
                exit;

            } else {

                $new_key = insert_survey(
                    $user_id,
                    $title,
                    $spec,
                    $start_at,
                    $end_at
                );

                header("Location: question.php?question_id=" . urlencode($new_key));
                exit;
            }

        } catch (Throwable $e) {
            $errors[] = '登録中にエラーが発生しました。';
            writeLog('survey_form', 'ERROR', '登録エラー: ' . $e->getMessage());
        }
    }
}

// ----------------------------------------
// 5. HTML の前に header.php を読み込む
// ----------------------------------------
include 'header.php';
?>

<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title><?= $edit_mode ? 'アンケート編集' : 'アンケート新規作成' ?></title>

<style>
body {
    font-family: "Yu Gothic", sans-serif;
    background: #1E3A8A; /* PDF の青背景 */
    padding: 20px;
    color: #111827; /* 黒文字 */
}

.survey-container {
    max-width: 700px;
    margin: auto;
    background: #ffffff; /* 白カード */
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 0 15px rgba(0,0,0,0.15);
    border: 1px solid #dddddd;
}

.section {
    margin-bottom: 30px;
}

.section h2 {
    font-size: 18px;
    margin-bottom: 10px;
    color: #111827;
}

.input-text, .input-select {
    width: 100%;
    padding: 10px;
    border: 1px solid #aaaaaa;
    border-radius: 6px;
    margin-top: 5px;
    background: #ffffff;
    color: #111827;
}

.question-block {
    border: 1px solid #dddddd;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 6px;
    background: #ffffff;
}

/* 質問ボタン（質問1 / 質問2） */
.btn-question {
    background: #2563EB; /* PDF の青 */
    color: white;
    padding: 10px 14px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    margin-bottom: 10px;
    width: 100%;
    text-align: left;
    font-size: 16px;
}

/* ＋ボタン */
.btn-add {
    background: #3B82F6; /* 明るい青 */
    color: white;
    padding: 8px 14px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    margin-top: 10px;
}

/* 送信画面へボタン（赤） */
.btn-submit {
    background: #DC2626; /* PDF の赤 */
    color: white;
    padding: 12px 20px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    width: 100%;
    font-size: 18px;
    margin-top: 20px;
}

.btn-delete {
    background: #DC2626; /* 赤 */
    color: white;
    padding: 6px 12px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    margin-top: 10px;
}



</style>

<script>
// 質問追加（既存構造に合わせる）
function addQuestion() {
    const container = document.getElementById('questions');
    const index = container.children.length;

    const div = document.createElement('div');
    div.className = 'question-block border p-3 mb-3';
    div.id = `question-${index}`;

    // index=0 のときは削除ボタンなし
    const deleteButton =
        index === 1
        ? "" 
        : `<button type="button" class="btn-delete" onclick="deleteQuestion(${index})">削除</button>`;

    div.innerHTML = `
        <h3>質問${index}</h3>

        <label>質問文</label>
        <input type="text" name="q_label[${index}]" class="input-text">

        <label>回答形式</label>
        <select name="q_type[${index}]" class="input-select" onchange="toggleOptions(this, ${index})">
            <option value="single">択一選択</option>
            <option value="multiple">複数選択</option>
            <option value="text">自由記述</option>
        </select>

        <div id="opt-wrap-${index}">
            <label>選択肢（カンマ区切り）</label>
            <input type="text" name="q_options[${index}]" class="input-text">
        </div>

        <label>結果表示形式</label>
        <select name="q_result_display[${index}]" class="input-select">
            <option value="bar">ヒストグラフ</option>
            <option value="table">集計表</option>
            <option value="pie">円グラフ</option>
            <option value="pie3d">3D円グラフ</option>
            <option value="text">テキスト</option>
        </select>

        ${deleteButton}
    `;

    container.appendChild(div);
}



function deleteQuestion(index) {
    const target = document.getElementById(`question-${index}`);
    if (target) {
        target.remove();
    }
}



// 回答形式変更
function toggleOptions(sel, index) {
    const wrap = document.getElementById('opt-wrap-' + index);
    if (!wrap) return;

    wrap.style.display = (sel.value === 'text') ? 'none' : 'block';
}

// 年月日のセレクトボックス生成
window.addEventListener("load", () => {
    const years = [...Array(40)].map((_, i) => 1990 + i);
    const months = [...Array(12)].map((_, i) => i + 1);
    const days = [...Array(31)].map((_, i) => i + 1);

    const fill = (name, arr) => {
        const sel = document.querySelector(`select[name="${name}"]`);
        arr.forEach(v => {
            const op = document.createElement("option");
            op.value = v;
            op.textContent = v;
            sel.appendChild(op);
        });
    };

    fill("start_year", years);
    fill("start_month", months);
    fill("start_day", days);

    fill("end_year", years);
    fill("end_month", months);
    fill("end_day", days);
});
</script>

</head>
<body>

<div class="survey-container">

<h1><?= $edit_mode ? 'アンケート編集' : 'アンケート新規作成' ?></h1>

<?php if (!empty($errors)): ?>
    <div style="background:#fee; padding:10px; border:1px solid #f99; margin-bottom:20px;">
        <ul>
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

<!-- 1. タイトル -->
<div class="section">
    <h2>1. アンケートのタイトルを記入してください</h2>
    <input type="text" name="title" class="input-text"
           value="<?= htmlspecialchars($survey['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
</div>

<!-- 2. 説明文 -->
<div class="section">
    <h2>アンケート説明文</h2>
    <textarea name="description" class="input-text" rows="3"><?= 
        htmlspecialchars($spec['title'] ?? '', ENT_QUOTES, 'UTF-8') 
    ?></textarea>
</div>

<!-- 3. 回答期限（元の datetime-local 方式） -->
<div class="section">
    <h2>2. 回答期限を選択してください</h2>

    <label>開始日時</label>
    <input type="datetime-local" name="start_at" class="input-text"
           value="<?= htmlspecialchars($spec['start_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <label style="margin-top:15px;">終了日時</label>
    <input type="datetime-local" name="end_at" class="input-text"
           value="<?= htmlspecialchars($spec['end_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
</div>


<!-- 4. タグ -->
<div class="section">
    <h2>タグ（カンマ区切り）</h2>
    <input type="text" name="tags" class="input-text"
           value="<?= htmlspecialchars(implode(',', $spec['Survey_tag'] ?? []), ENT_QUOTES, 'UTF-8') ?>">
</div>

<!-- 5. 集計設定 -->
<div class="section">
    <h2>3. 年齢別、性別も集計しますか？</h2>
    <select name="agg_gender_age" class="input-select">
        <option value="yes" <?= !empty($spec['aggregate']['gender']) ? 'selected' : '' ?>>はい</option>
        <option value="no" <?= empty($spec['aggregate']['gender']) ? 'selected' : '' ?>>いいえ</option>
    </select>
</div>

<!-- 6. 質問一覧 -->
<div class="section">
    <h2>4. 質問を記入してください</h2>

    <div id="questions">
        <?php if (!empty($spec['questions'])): ?>
            <?php foreach ($spec['questions'] as $i => $q): ?>
                <script>window.addEventListener('load', () => addQuestion());</script>
            <?php endforeach; ?>
        <?php else: ?>
            <script>window.addEventListener('load', () => addQuestion());</script>
        <?php endif; ?>
    </div>

    <button type="button" class="btn-add" onclick="addQuestion()">＋</button>
</div>

<button type="submit" class="btn-submit">送信画面へ</button>

</form>

</div>
</body>
</html>
