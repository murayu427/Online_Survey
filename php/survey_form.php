<?php
// ========================================
// survey_form.php（アンケート作成・編集）
// ========================================


require_once 'auth.php';
require_once 'db.php';
require_once 'security.php';
require_once 'logger.php';
require_once __DIR__ . '/error.php';

start_sess();

// ----------------------------------------
// 1. ログインチェック
// ----------------------------------------
login_check();
$user_id = $_SESSION['user_id'] ?? null;

// ----------------------------------------
// 2. CSRF トークン
// ----------------------------------------
$csrf_token = generate_csrf();

// ----------------------------------------
// 3. 編集モード判定
// ----------------------------------------
$edit_mode = false;
$survey_key = null;

$spec = [
    'title'       => '',
    'Survey_tag'  => [],
    'questions'   => [],
];

if (!empty($_GET['key'])) {
    $survey_key = $_GET['key'];
    $survey = get_survey_by_key($survey_key, "question_key");

    if ($survey && $survey['creator_id'] == $user_id) {
        $edit_mode = true;
        $spec = $survey['survey_spec'];
        $survey_id = $survey['survey_id'];
    } else {
        renderError('不正アクセスです。編集権限がありません。', 403, 'auth', 'WARNING');
    }
}

$errors = [];

// ----------------------------------------
// 4. POST 受信
// ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        renderError('不正なリクエストです（CSRF）。', 403, 'app', 'WARNING');
    }

    if (isset($_POST['delete_survey']) && $edit_mode && !empty($survey_id)) {
        try {
            delete_survey((int)$survey_id);
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            renderError('アンケートの削除に失敗しました。', 500, 'db', 'ERROR', $e, 'Survey Delete Error');
        }
    }

    if (isset($_POST['is_revision']) && $_POST['is_revision'] === '1') {
        // survey_confirm.php から「修正する」ボタンで戻ってきた場合
        // バリデーションとDB登録をスキップして、データを復元する
        if (!isset($survey) || !is_array($survey)) {
            $survey = [];
        }
        $survey['title'] = $_POST['title'] ?? '';
        $survey['start_at'] = $_POST['start_at'] ?? '';
        $survey['end_at'] = $_POST['end_at'] ?? '';
        
        $spec['description'] = $_POST['description'] ?? '';
        
        $tags_raw = $_POST['tags'] ?? '';
        $spec['Survey_tag'] = [];
        if (trim($tags_raw) !== '') {
            foreach (explode(',', $tags_raw) as $t) {
                $t = trim($t);
                if ($t !== '') {
                    $spec['Survey_tag'][] = $t;
                }
            }
        }
        
        $spec['questions'] = [];
        $q_labels = $_POST['q_label'] ?? [];
        $q_types = $_POST['q_type'] ?? [];
        $q_result_displays = $_POST['q_result_display'] ?? [];
        $q_options = $_POST['q_option'] ?? [];
        
        foreach ($q_labels as $i => $label) {
            $spec['questions'][] = [
                'label'          => $label,
                'type'           => $q_types[$i] ?? 'single',
                'result_display' => $q_result_displays[$i] ?? 'bar',
                'options'        => $q_options[$i] ?? []
            ];
        }
    } else {
        // 入力値取得
        $title       = $_POST['title']       ?? '';
        $description = $_POST['description'] ?? '';
        $tags_raw    = $_POST['tags']        ?? '';

        $start_at = $_POST['start_at'] ?? '';
        $end_at   = $_POST['end_at']   ?? '';

        if ($start_at === '' || $end_at === '') {
            $errors[] = '開始日時と終了日時を入力してください。';
        }

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
            if (!in_array($display, ['bar', 'band', 'pie', 'line', 'table', 'text'], true)) {
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
                'description' => $description,
                'Survey_tag'  => $tags,
                'questions'   => $questions,
            ];

            // 目安回答時間は作成者が指定せず、設問タイプと量から自動計算する
            unset($spec['estimated_minutes'], $spec['duration']);

            try {
                $survey_id = $survey['survey_id'] ?? null;
                if ($edit_mode && $survey_id !== null) {
                    $update_data = [
                        'title'       => $title,
                        'survey_spec' => $spec,
                        'start_at'    => $start_at,
                        'end_at'      => $end_at,
                    ];

                    update_survey($survey_id, $update_data);

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
                die("error". $e->getMessage());
                // $errors[] = '登録中にエラーが発生しました。';
                // writeLog('survey_form', 'ERROR', '登録エラー: ' . $e->getMessage());
            }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>アンケート作成 - 村上製作所</title>

    <!-- index.php / result.php と同じ読み込み順 -->
    <link rel="stylesheet" href="../css/reset.css">
    <link rel="stylesheet" href="../css/question.css">
    <link rel="stylesheet" href="../css/footer.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/readability.css">

    <style>
        /* index.php / result.php と同じ背景色 */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }
        body {
            background-color: #1e2d5a;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ================================
           index.php と同じヘッダーを再現する強制CSS
           ================================ */
        header.w-full.bg-

\[\#1e3a8a\]

.text-white.fixed.top-0.left-0.h-16.z-

\[9999\]

.shadow-lg {
            background-color: #1E3A8A !important;
            height: 64px !important;
            padding-left: 24px !important;
            padding-right: 24px !important;
            display: flex !important;
            align-items: center !important;
        }

        header .fa-house {
            color: #ffffff !important;
            font-size: 26px !important;
        }

        header .font-bold {
            color: #ffffff !important;
            font-size: 22px !important;
            font-weight: bold !important;
        }

        header .fa-bell {
            color: #ffffff !important;
            font-size: 26px !important;
        }

        header input#survey-search {
            background: #ffffff !important;
            border-radius: 8px !important;
            padding: 8px 12px !important;
            color: #333 !important;
            width: 220px !important;
            border: none !important;
            outline: none !important;
        }
    </style>
</head>

<body>

<meta charset="UTF-8">
<title><?= $edit_mode ? 'アンケート編集' : 'アンケート新規作成' ?></title>

<style>
body {
    font-family: "Yu Gothic", sans-serif;
    background: #1e2d5a;
    color: #ffffff;
}


/* フォーム外枠（白 → #1e2d5a） */
.survey-container {
    margin-top: 100px;
    max-width: 700px;
    margin-left: auto;
    margin-right: auto;
    margin-bottom: 40px;
    background: #1e2d5a; /* ← 指定色 */
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 0 15px rgba(0,0,0,0.15);
    border: 1px solid #334155; /* 濃い枠線で馴染ませる */
}

/* セクションタイトル */
.section h2 {
    font-size: 18px;
    margin-bottom: 10px;
    color: #ffffff; /* 白文字に変更 */
}

/* 入力欄（白 → #1e2d5a） */
.input-text,
.input-select {
    width: 100%;
    padding: 10px;
    border: 1px solid #475569; /* 少し明るい紺で枠線 */
    border-radius: 6px;
    margin-top: 5px;
    background: #2a3b7a; /* ← 指定色 */
    color: #ffffff; /* 白文字 */
}

/* 質問ブロック（白 → #1e2d5a） */
.question-block {
    border: 1px solid #475569;
    padding: 15px;
    margin-bottom: 15px;
    border-radius: 6px;
    background: #1e2d5a; /* ← 指定色 */
    color: #ffffff;
}


.section {
    margin-bottom: 30px;
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

.survey-heading-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.survey-heading-row h1 {
    margin: 0;
}

.survey-heading-row form {
    display: inline-flex;
    margin: 0;
}

.delete-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(17, 24, 39, 0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100000;
    padding: 20px;
}

.delete-modal-backdrop.is-open {
    display: flex;
}

.delete-modal {
    background: #ffffff;
    color: #111827;
    width: min(420px, 100%);
    border-radius: 14px;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.24);
    padding: 24px;
    animation: modalFadeIn 0.2s ease-out;
}

.delete-modal h3 {
    margin: 0 0 10px;
    font-size: 20px;
    color: #111827;
}

.delete-modal p {
    margin: 0 0 18px;
    line-height: 1.6;
    color: #4b5563;
}

.delete-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.delete-modal-actions .btn-cancel {
    background: #6b7280;
    color: white;
    padding: 10px 14px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
}

.delete-modal-actions .btn-confirm-delete {
    background: #dc2626;
    color: white;
    padding: 10px 14px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(8px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.survey-container button,
.survey-container input[type="submit"],
.survey-container input[type="button"] {
    transition: transform 0.18s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.18s ease, filter 0.18s ease, background-color 0.18s ease;
    transform: translateY(0);
}

.survey-container button:hover,
.survey-container input[type="submit"]:hover,
.survey-container input[type="button"]:hover {
    transform: translateY(-3px) scale(1.03);
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.16);
    filter: brightness(1.03);
}

.survey-container button:active,
.survey-container input[type="submit"]:active,
.survey-container input[type="button"]:active {
    transform: translateY(-1px) scale(0.99);
}

/* index.php と同じヘッダーを再現する強制CSS */
header.w-full.bg-

\[\#1e3a8a\]

.text-white.fixed.top-0.left-0.h-16.z-

\[9999\]

.shadow-lg {
    background-color: #1E3A8A !important;
    height: 64px !important;
    padding-left: 24px !important;
    padding-right: 24px !important;
    display: flex !important;
    align-items: center !important;
}

header .fa-house {
    color: #ffffff !important;
    font-size: 26px !important;
}

header .font-bold {
    color: #ffffff !important;
    font-size: 22px !important;
    font-weight: bold !important;
}

header .fa-bell {
    color: #ffffff !important;
    font-size: 26px !important;
}

header input#survey-search {
    background: #ffffff !important;
    border-radius: 8px !important;
    padding: 8px 12px !important;
    color: #333 !important;
    width: 220px !important;
    border: none !important;
    outline: none !important;
}

</style>

<script>
function openDeleteModal() {
    document.getElementById('deleteModalBackdrop').classList.add('is-open');
}

function closeDeleteModal(event = null) {
    const backdrop = document.getElementById('deleteModalBackdrop');
    if (event && event.target !== backdrop && !backdrop.contains(event.target)) {
        return;
    }
    backdrop.classList.remove('is-open');
}

function submitDeleteSurvey() {
    document.getElementById('delete-survey-form').submit();
}

// 質問追加（既存構造に合わせる）
let questionIndex = 1;   // ★ グローバルで管理する

function addQuestion(existingData = null) {
    const container = document.getElementById('questions');
    const index = questionIndex++;   // ★ これで採番が壊れない

    const div = document.createElement('div');
    div.className = 'question-block border p-3 mb-3';
    div.id = `question-${index}`;

    const deleteButton =
        index === 1 ? "" :
        `<button type="button" class="btn-delete" onclick="deleteQuestion(${index})">削除</button>`;

    div.innerHTML = `
        <h3>質問${index}</h3>

        <label>質問文</label>
        <input type="text" name="q_label[${index}]" class="input-text"
               value="${existingData ? existingData.label : ''}">

        <label>回答形式</label>
        <select name="q_type[${index}]" class="input-select"
                onchange="toggleOptions(this, ${index})">
            <option value="single" ${existingData?.type === 'single' ? 'selected' : ''}>択一選択</option>
            <option value="multiple" ${existingData?.type === 'multiple' ? 'selected' : ''}>複数選択</option>
            <option value="text" ${existingData?.type === 'text' ? 'selected' : ''}>自由記述</option>
        </select>

        <div id="opt-wrap-${index}" style="${existingData?.type === 'text' ? 'display:none;' : ''}">
            <label>選択肢</label>
            <div id="options-${index}" class="option-list"></div>
            <button type="button" class="btn-add" onclick="addOption(${index})">＋選択肢追加</button>
        </div>

        <label>結果表示形式</label>
        <select name="q_result_display[${index}]" class="input-select">
            <option value="bar" ${existingData?.result_display === 'bar' ? 'selected' : ''}>ヒストグラム</option>
            <option value="band" ${existingData?.result_display === 'band' ? 'selected' : ''}>帯グラフ</option>
            <option value="table" ${existingData?.result_display === 'table' ? 'selected' : ''}>集計表</option>
            <option value="pie" ${existingData?.result_display === 'pie' ? 'selected' : ''}>円グラフ</option>
            <option value="text" ${existingData?.result_display === 'text' ? 'selected' : ''}>テキスト</option>
        </select>

        ${deleteButton}
    `;

    container.appendChild(div);

    // 初期選択肢
    if (existingData && existingData.options) {
        existingData.options.forEach(opt => addOption(index, opt));
    } else {
        addOption(index);
        addOption(index);
    }
}



function addOption(qIndex, value = "") {
    const optContainer = document.getElementById(`options-${qIndex}`);
    const optIndex = optContainer.children.length;

    const div = document.createElement('div');
    div.className = "option-block flex items-center gap-2";
    div.id = `option-${qIndex}-${optIndex}`;

    const numberLabel = `<span class="option-number">${optIndex + 1}.</span>`;

    const deleteBtn =
        optIndex >= 2
        ? `<button type="button" class="btn-delete" onclick="deleteOption(${qIndex}, ${optIndex})">削除</button>`
        : "";

    div.innerHTML = `
        ${numberLabel}
        <input type="text" name="q_option[${qIndex}][]" class="input-text option-input"
               value="${value}">
        ${deleteBtn}
    `;

    optContainer.appendChild(div);

    renumberOptions(qIndex);
}





function deleteOption(qIndex, optIndex) {
    const optContainer = document.getElementById(`options-${qIndex}`);
    const currentCount = optContainer.querySelectorAll(".option-block").length;

    if (currentCount <= 2) {
        alert("選択肢は最低2つ必要です。");
        return;
    }

    const target = document.getElementById(`option-${qIndex}-${optIndex}`);
    if (target) target.remove();

    renumberOptions(qIndex);
}



function renumberOptions(qIndex) {
    const optContainer = document.getElementById(`options-${qIndex}`);
    const blocks = optContainer.querySelectorAll(".option-block");

    blocks.forEach((block, i) => {
        block.querySelector(".option-number").textContent = `${i + 1}.`;

        const btn = block.querySelector(".btn-delete");
        if (btn) {
            btn.style.display = (i >= 2) ? "inline-block" : "none";
        }
    });
}

function refreshOptionDeleteButtons(qIndex) {
    const optContainer = document.getElementById(`options-${qIndex}`);
    const blocks = optContainer.querySelectorAll(".option-block");

    blocks.forEach((block, i) => {
        const btn = block.querySelector(".btn-delete");
        if (blocks.length <= 2) {
            btn.style.display = "none";
        } else {
            btn.style.display = "inline-block";
        }
    });
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

<div class="survey-heading-row">
    <h1><?= $edit_mode ? 'アンケート編集' : 'アンケート新規作成' ?></h1>
    <?php if ($edit_mode): ?>
        <form method="post" action="" id="delete-survey-form" class="inline-flex">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="delete_survey" value="1">
            <button type="button" class="btn-delete" onclick="openDeleteModal()">アンケート削除</button>
        </form>
    <?php endif; ?>
</div>

<div id="deleteModalBackdrop" class="delete-modal-backdrop" onclick="closeDeleteModal(event)">
    <div class="delete-modal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
        <h3 id="deleteModalTitle">アンケートを削除しますか？</h3>
        <p>この操作は取り消せません。削除したアンケートは一覧から消えます。</p>
        <div class="delete-modal-actions">
            <button type="button" class="btn-cancel" onclick="closeDeleteModal()">キャンセル</button>
            <button type="button" class="btn-confirm-delete" onclick="submitDeleteSurvey()">削除する</button>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div style="background:#fee; padding:10px; border:1px solid #f99; margin-bottom:20px;">
        <ul>
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form action="survey_confirm.php" method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="edit_mode" value="<?= htmlspecialchars($edit_mode ? '1' : '0', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="survey_id" value="<?= htmlspecialchars((string)($survey_id ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="survey_key" value="<?= htmlspecialchars((string)($survey_key ?? ''), ENT_QUOTES, 'UTF-8') ?>">

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
            htmlspecialchars($spec['description'] ?? '', ENT_QUOTES, 'UTF-8') 
        ?></textarea>
    </div>

    <!-- 3. 回答期限 -->
    <div class="section">
        <h2>2. 回答期限を選択してください</h2>

        <label>開始日時</label>
        <input type="datetime-local" name="start_at" class="input-text"
               value="<?= htmlspecialchars(substr($survey['start_at'] ?? '', 0, 16), ENT_QUOTES, 'UTF-8') ?>">

        <label style="margin-top:15px;">終了日時</label>
        <input type="datetime-local" name="end_at" class="input-text"
               value="<?= htmlspecialchars(substr($survey['end_at'] ?? '', 0, 16), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <!-- 4. タグ -->
    <div class="section">
        <h2>3. タグ（カンマ区切り） 記入例:PC,容量,スペック</h2>
        <input type="text" name="tags" class="input-text"
               value="<?= htmlspecialchars(implode(',', $spec['Survey_tag'] ?? []), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <!-- 6. 質問一覧 -->
<div class="section">
    <h2>4. 質問を記入してください</h2>

    <div id="questions"></div>

    <script>
    <?php if (!empty($spec['questions'])): ?>
        <?php foreach ($spec['questions'] as $i => $q): ?>
            window.addEventListener('load', () => addQuestion(<?= json_encode($q) ?>));
        <?php endforeach; ?>
    <?php else: ?>
        window.addEventListener('load', () => addQuestion());
    <?php endif; ?>
    </script>

    <!-- 質問追加ボタン -->
    <button type="button" class="btn-add" onclick="addQuestion()">＋</button>
</div>

<!-- ★ 送信ボタンは form の中に置く（重要） -->
<button type="submit" class="btn-submit">送信確認へ</button>

</form>

</div>
</body>
</html>

<?php include 'footer.php'; ?>





