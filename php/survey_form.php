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
// 3. 編集モード判定（?key=xxx）
// ----------------------------------------
$edit_mode = false;
$survey_key = null;
$spec = [
    'title'      => '',
    'Survey_tag' => [],
    'aggregate'  => [
        'gender'       => false,
        'age'          => false,
        'gender_split' => false,
    ],
    'questions'  => [],
];

if (isset($_GET['key']) && $_GET['key'] !== '') {
    $survey_key = $_GET['key'];

    // surveys.question_key で検索
    $survey = get_survey_by_key($survey_key, "question_key");

    if ($survey && $survey['creator_id'] == $user_id) {
        $edit_mode = true;
        $spec = $survey['survey_spec'];
    } else {
        writeLog('survey_form', 'WARNING', "不正アクセス: key={$survey_key}");
        header('Location: index.php');
        exit;
    }
}

$errors = [];

// ----------------------------------------
// 4. POST 受信
// ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = '不正なリクエストです（CSRF）。';
    } else {

        // 入力値取得
        $title       = $_POST['title']       ?? '';
        $description = $_POST['description'] ?? '';
        $tags_raw    = $_POST['tags']        ?? '';

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
            if ($label === '') {
                $errors[] = '質問文はすべて入力してください。';
                continue;
            }
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
                if (trim($opt_raw) === '') {
                    $errors[] = '選択式の質問には選択肢が必要です。';
                } else {
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
            }

            $questions[] = [
                'label'          => $label,
                'type'           => $type,
                'options'        => $type === 'text' ? [] : $opts,
                'result_display' => $display,
            ];
        }

        if (empty($questions)) {
            $errors[] = '少なくとも1つの質問を作成してください。';
        }

        // ------------------------------
        // エラーなし → DB 登録準備
        // ------------------------------
        if (empty($errors)) {

            $spec = [
                'title'      => $description,
                'Survey_tag' => $tags,
                'aggregate'  => [
                    'gender'       => $agg_gender,
                    'age'          => $agg_age,
                    'gender_split' => $agg_gender_split,
                ],
                'questions'  => $questions,
            ];

            // ----------------------------------------
            // 5. DB 登録処理（ここが HTML より前にあるのが重要）
            // ----------------------------------------
            try {

                if ($edit_mode && $survey_key !== null) {

                    update_survey(
                        $survey_key,
                        $title,
                        $spec,
                        $user_id
                    );

                    writeLog('survey_form', 'INFO', "アンケート更新成功 key={$survey_key}");

                    header("Location: question.php?question_id=" . urlencode($survey_key));
                    exit;

                } else {

                    $new_key = insert_survey(
                        $title,
                        $spec,
                        $user_id
                    );

                    writeLog('survey_form', 'INFO', "アンケート作成成功 key={$new_key}");

                    header("Location: question.php?question_id=" . urlencode($new_key));
                    exit;
                }

            } catch (Throwable $e) {
                $errors[] = '登録中にエラーが発生しました。';
                writeLog('survey_form', 'ERROR', '登録エラー: ' . $e->getMessage());
            }
        }
    }
}

include 'header.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title><?= $edit_mode ? 'アンケート編集' : 'アンケート新規作成' ?></title>
<link rel="stylesheet" href="style.css">

<script>
// ----------------------------------------
// 質問追加
// ----------------------------------------
function addQuestion() {
    const container = document.getElementById('questions');
    const index = container.children.length;

    const div = document.createElement('div');
    div.className = 'border p-3 mb-3';

    div.innerHTML = `
        <label>質問文</label><br>
        <input type="text" name="q_label[${index}]" class="w-full border p-1 mb-2"><br>

        <label>質問タイプ</label><br>
        <select name="q_type[${index}]" class="border p-1 mb-2" onchange="toggleOptions(this, ${index})">
            <option value="single">単一選択</option>
            <option value="multiple">複数選択</option>
            <option value="text">自由記述</option>
        </select><br>

        <div id="opt-wrap-${index}">
            <label>選択肢（カンマ区切り）</label><br>
            <input type="text" name="q_options[${index}]" class="w-full border p-1 mb-2"><br>
        </div>

        <label>結果表示形式</label><br>
        <select name="q_result_display[${index}]" class="border p-1 mb-2">
            <option value="bar">棒グラフ</option>
            <option value="pie">円グラフ</option>
            <option value="line">折れ線グラフ</option>
            <option value="table">表</option>
        </select>
    `;

    container.appendChild(div);
}

// ----------------------------------------
// 質問タイプ変更 → 選択肢欄の表示/非表示
// ----------------------------------------
function toggleOptions(sel, index) {
    const wrap = document.getElementById('opt-wrap-' + index);
    if (!wrap) return;

    if (sel.value === 'text') {
        wrap.style.display = 'none';
    } else {
        wrap.style.display = 'block';
    }
}
</script>

</head>
<body class="pt-20">

<div class="max-w-4xl mx-auto p-6 bg-white shadow-md rounded">

<h1 class="text-2xl font-bold mb-4">
    <?= $edit_mode ? 'アンケート編集' : 'アンケート新規作成' ?>
</h1>

<?php if (!empty($errors)): ?>
    <div class="bg-red-100 text-red-700 p-3 rounded mb-4">
        <ul class="list-disc pl-5">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

    <!-- タイトル -->
    <div class="mb-4">
        <label class="font-bold">アンケートタイトル</label><br>
        <input type="text" name="title" class="w-full border p-2"
               value="<?= htmlspecialchars($survey['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <!-- 説明文（survey_spec["title"] として保存） -->
    <div class="mb-4">
        <label class="font-bold">アンケート説明文</label><br>
        <textarea name="description" class="w-full border p-2" rows="3"><?= 
            htmlspecialchars($spec['title'] ?? '', ENT_QUOTES, 'UTF-8') 
        ?></textarea>
    </div>

    <!-- タグ -->
    <div class="mb-4">
        <label class="font-bold">タグ（カンマ区切り）</label><br>
        <input type="text" name="tags" class="w-full border p-2"
               value="<?= htmlspecialchars(implode(',', $spec['Survey_tag'] ?? []), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <!-- 集計設定 -->
    <div class="mb-4">
        <label class="font-bold">集計オプション</label><br>
        <label><input type="checkbox" name="agg_gender" <?= !empty($spec['aggregate']['gender']) ? 'checked' : '' ?>> 性別で集計</label><br>
        <label><input type="checkbox" name="agg_age" <?= !empty($spec['aggregate']['age']) ? 'checked' : '' ?>> 年代で集計</label><br>
        <label><input type="checkbox" name="agg_gender_split" <?= !empty($spec['aggregate']['gender_split']) ? 'checked' : '' ?>> 性別×年代で集計</label>
    </div>

    <hr class="my-4">

    <!-- 質問一覧 -->
    <h2 class="text-xl font-bold mb-2">質問一覧</h2>

    <div id="questions">
        <?php if (!empty($spec['questions'])): ?>
            <?php foreach ($spec['questions'] as $i => $q): ?>
                <div class="border p-3 mb-3">

                    <label>質問文</label><br>
                    <input type="text" name="q_label[<?= $i ?>]" class="w-full border p-1 mb-2"
                           value="<?= htmlspecialchars($q['label'], ENT_QUOTES, 'UTF-8') ?>"><br>

                    <label>質問タイプ</label><br>
                    <select name="q_type[<?= $i ?>]" class="border p-1 mb-2" onchange="toggleOptions(this, <?= $i ?>)">
                        <option value="single"   <?= $q['type'] === 'single'   ? 'selected' : '' ?>>単一選択</option>
                        <option value="multiple" <?= $q['type'] === 'multiple' ? 'selected' : '' ?>>複数選択</option>
                        <option value="text"     <?= $q['type'] === 'text'     ? 'selected' : '' ?>>自由記述</option>
                    </select><br>

                    <?php $opt_str = implode(',', $q['options'] ?? []); ?>

                    <div id="opt-wrap-<?= $i ?>" style="<?= $q['type'] === 'text' ? 'display:none;' : '' ?>">
                        <label>選択肢（カンマ区切り）</label><br>
                        <input type="text" name="q_options[<?= $i ?>]" class="w-full border p-1 mb-2"
                               value="<?= htmlspecialchars($opt_str, ENT_QUOTES, 'UTF-8') ?>"><br>
                    </div>

                    <label>結果表示形式</label><br>
                    <select name="q_result_display[<?= $i ?>]" class="border p-1 mb-2">
                        <?php $rd = $q['result_display'] ?? 'bar'; ?>
                        <option value="bar"   <?= $rd === 'bar'   ? 'selected' : '' ?>>棒グラフ</option>
                        <option value="pie"   <?= $rd === 'pie'   ? 'selected' : '' ?>>円グラフ</option>
                        <option value="line"  <?= $rd === 'line'  ? 'selected' : '' ?>>折れ線グラフ</option>
                        <option value="table" <?= $rd === 'table' ? 'selected' : '' ?>>表</option>
                    </select>

                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <script>
                window.addEventListener('DOMContentLoaded', () => addQuestion());
            </script>
        <?php endif; ?>
    </div>

    <button type="button" onclick="addQuestion()" class="bg-blue-500 text-white px-3 py-1 rounded">
        ＋ 質問を追加
    </button>

    <div class="mt-6">
        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded">
            <?= $edit_mode ? '更新する' : '登録する' ?>
        </button>
    </div>

</form>

</div>
</body>
</html>
