<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/error.php';
start_sess();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

$commentError = false;

if($_SERVER["REQUEST_METHOD"] == "POST"){
    if(isset($_POST["commentSend"])){
        if(checkWord($_POST["comment"])){
            insert_comment($_POST["survey_id"], $_SESSION["user_id"], $_POST["comment"]);
        }else{
            $commentError = true;
        }
    }
}

// アンケートID
$question_key = $_GET["id"] ?? '';
$user_id = $_SESSION['user_id'] ?? 1;
if ($question_key === '') {
    renderError('エラー：無効なアクセスです。URLをご確認ください。', 400, 'app', 'WARNING', null, 'Invalid Access');
}
//====================================
// ① 集計データ取得（グラフ用）
//====================================

$survey = get_survey_by_key($question_key, 'question_key');

if ($survey === null) {
    renderError('エラー：指定されたアンケートが見つかりません。', 404, 'app', 'WARNING', null, 'Survey Not Found');
}

// 取得したデータから survey_id を抽出
$survey_id = (int)$survey['survey_id'];
// アンケートの仕様（質問の一覧など）を取得
$spec_data = $survey['survey_spec'];
// result_keyの抽出
$result_key = $survey['result_key'];
// 判明した survey_id を使って回答データを全件取得
$responses = get_responses_by_survey_id($survey_id);
$question_results = [];

// 仕様書に定義されている質問（q1, q2...）を順番に走査して、
// グラフ結果と自由記述結果を同じ配列にまとめる
if (isset($spec_data['questions']) && is_array($spec_data['questions'])) {
    foreach ($spec_data['questions'] as $index => $q) {
        if (!is_array($q)) {
            continue;
        }

        $q_id = "q" . $index;
        $q_title = $q['label'] ?? $q['title'] ?? $q_id;
        $q_type = $q['type'] ?? '';

        if ($q_type === 'text') {
            $answers = [];
            foreach ($responses as $response) {
                $response_answers = $response['answer_data'] ?? [];
                if (isset($response_answers[$q_id])) {
                    $value = $response_answers[$q_id];
                    if (is_array($value)) {
                        foreach ($value as $item) {
                            $item = trim((string)$item);
                            if ($item !== '') {
                                $answers[] = $item;
                            }
                        }
                    } else {
                        $value = trim((string)$value);
                        if ($value !== '') {
                            $answers[] = $value;
                        }
                    }
                }
            }

            $question_results[] = [
                'type' => 'text',
                'q_id' => $q_id,
                'title' => $q_title,
                'answers' => $answers,
            ];
            continue;
        }

        // この質問に対する選択肢ごとの票数を数える
        $counts = [];
        foreach ($responses as $response) {
            $answers = $response['answer_data'] ?? [];
            if (isset($answers[$q_id])) {
                $ans = $answers[$q_id];
                if (is_array($ans)) {
                    foreach ($ans as $a) {
                        $answer_key = $a ?? '無効な回答';
                        $counts[$answer_key] = isset($counts[$answer_key]) ? $counts[$answer_key] + 1 : 1;
                    }
                } else {
                    $answer_key = $ans ?? '無効な回答';
                    $counts[$answer_key] = isset($counts[$answer_key]) ? $counts[$answer_key] + 1 : 1;
                }
            }
        }

        $labels = [];
        $data = [];
        foreach ($counts as $answer_value => $count) {
            $labels[] = (string)$answer_value;
            $data[] = $count;
        }

        $chart_type = $q['result_display'] ?? 'bar';
        if ($chart_type === 'histogram') {
            $chart_type = 'bar';
        }

        $question_results[] = [
            'type' => 'chart',
            'q_id' => $q_id,
            'title' => $q_title,
            'chart_type' => $chart_type,
            'labels' => $labels,
            'data' => $data,
        ];
    }
}
//====================================
// ② コメント一覧取得
//====================================
$comment_list_data = get_comments_by_survey_id((int)$survey_id);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>アンケート結果 - オンラインアンケートサイト</title>
    <link rel="stylesheet" href="../css/reset.css">
    <link rel="stylesheet" href="../css/question.css">
    <link rel="stylesheet" href="../css/footer.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../css/readability.css">
<style>
        body {
            background-color: #1e2d5a;
            color: #ffffff;
        }
        .comment-box {
            background-color: #ffffff;
            color: #333333;
            border-radius: 8px;
            margin-bottom: 10px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .comment-item {
            background-color: #ffffff;
            color: #333333;
            border-radius: 8px;
            margin-bottom: 10px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .comment-item p {
            text-indent: 0;
            margin-bottom: 0.75rem;
        }
        /* 横並びレイアウト用のスタイル */
        .flex-container {
            display: flex;
            flex-wrap: wrap;
            gap: 40px;
            margin-bottom: 50px;
        }
        .flex-item {
            flex: 1;
            min-width: 400px;
        }
        .comment-section {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.25);
        }
        .comment-input-wrap {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<?php require_once 'header.php'; ?>
<main class="max-w-7xl mx-auto p-6">
    <input type="hidden" id="current-survey-id" value="<?= htmlspecialchars((string)$survey_id) ?>">
    <span id="save-status" style="color: gray; font-size: 0.9em; float: right;"></span>
    <h1 class="text-3xl font-bold my-6 text-white">アンケート結果</h1>
    <?php if (!empty($responses)): ?>
        <div id="survey-results-container">
            <?php foreach ($question_results as $result): ?>
                <div class="question-block" style="margin-bottom: 50px; border-bottom: 1px dashed #ccc; padding-bottom: 30px;">
                    <?php if ($result['type'] === 'chart'): ?>
                        <h2 class="text-xl font-semibold mb-4">📊 質問: <?= htmlspecialchars((string)$result['title']) ?></h2>
                        <div style="width: 400px; height: 300px;">
                            <canvas id="chart-<?= htmlspecialchars((string)$result['q_id']) ?>"></canvas>
                        </div>
                    <?php else: ?>
                        <h2 class="text-xl font-semibold mb-4">📝 質問: <?= htmlspecialchars((string)$result['title']) ?></h2>
                        <?php if (!empty($result['answers'])): ?>
                            <?php foreach ($result['answers'] as $answer): ?>
                                <p style="text-indent: 0; margin-bottom: 0.5rem;">・<?= nl2br(htmlspecialchars($answer)) ?></p>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="text-indent: 0; margin-bottom: 0;">回答はありません。</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="comment-section">
            <h2 class="text-xl font-semibold mb-4">💬 コメント</h2>
                    
            <!-- <div class="comment-input-wrap">
                <textarea id="comment-text-area" rows="3" class="w-full p-2 text-black rounded" placeholder="コメントを入力してください"></textarea><br>
                <button onclick="postComment()" class="mt-2 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded lift-button">送信</button>
            </div> -->
            <?php if(!is_null($user_id)):?>
                <form action="" method="post">
                    <input type="hidden" name="survey_id" value="<?php echo $survey_id ?>">
                    <textarea name="comment" id="" rows="3" class="w-full p-2 text-black rounded"></textarea>
                    <button type="submit" name="commentSend" class="mt-2 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded lift-button">送信</button>
                </form>
            <?php else:?>
                <p>コメント，いいねはログイン後に投稿できます</p>
            <?php endif?>
            <div id="comment-list" style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
            <?php foreach ($comment_list_data as $row) { 
                $name = $row["account_name"] ?? $row["username"] ?? 'ゲスト利用者';
                $comment = $row["comment"];
            ?>
                <div class="comment-item">
                    <p style="margin-top: 0;"><strong><?= htmlspecialchars($name) ?></strong></p>
                    <p><?= nl2br(htmlspecialchars($comment)) ?></p>
                    <?php if(!is_null($user_id)):?>
                        <button type="button" onclick="toggleLike(<?= (int)$row['comment_id'] ?>)" class="mt-2 border border-gray-300 px-3 py-1 rounded-full text-sm lift-button">
                            👍 <span id="like-count-<?= (int)$row['comment_id'] ?>"><?= $row["like_count"] ?? 0 ?></span>
                        </button>
                    <?php else:?>
                        <p class="mt-2 border border-gray-300 px-3 py-1 rounded-full text-sm lift-button">
                            👍 ログイン後有効 <span id="like-count-<?= (int)$row['comment_id'] ?>"><?= $row["like_count"] ?? 0 ?></span>
                        </p>
                    <?php endif?>
                </div>
            <?php } ?>
            </div>
        </section>
        <div class="mt-12 flex gap-4">
            <a href="download.php?key=<?= htmlspecialchars($result_key) ?>&format=csv" target="_blank" class="text-blue-300 hover:underline">CSV形式でダウンロード</a>
            <a href="download.php?key=<?= htmlspecialchars($result_key) ?>&format=pdf" target="_blank" class="text-blue-300 hover:underline">PDF形式でダウンロード</a>
        </div>
    <?php else:?>
        <p>回答はまだありません</p>
    <?php endif?>
    <?php if($commentError): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const dialog = document.getElementById('comment-error-dialog');
                if (dialog) {
                    if (typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    } else {
                        dialog.setAttribute('open', '');
                    }
                }
            });
        </script>
        <dialog id="comment-error-dialog" style="border:0; border-radius:16px; box-shadow:0 20px 50px rgba(0,0,0,0.35); padding:0; max-width:420px; width:min(90vw, 420px);">
            <form method="dialog" style="margin:0; padding:24px 24px 20px; text-align:center; background:linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);">
                <div style="font-size:28px; margin-bottom:10px;">⚠️</div>
                <h3 style="margin:0 0 8px; font-size:18px; color:#111827;">不適切な内容です</h3>
                <p style="margin:0 0 16px; color:#4b5563; line-height:1.6;">登録できない語が含まれています。<br>別の表現に直してください。</p>
                <button type="submit" style="border:0; border-radius:999px; padding:10px 18px; background:#2563eb; color:#fff; font-weight:600; cursor:pointer;">閉じる</button>
            </form>
        </dialog>
    <?php endif; ?>
</main>

<form id="main-form" style="display:none;"></form>

<script>
// Chart.jsの文字色をすべて「白」に統一
Chart.defaults.color = '#ffffff';

// 全質問のグラフ描画JS
<?php foreach ($question_results as $result) { ?>
<?php if ($result['type'] !== 'chart') continue; ?>
{
    const ctx = document.getElementById('chart-<?= htmlspecialchars((string)$result['q_id']) ?>');
    if (ctx) {
        new Chart(ctx, {
            type: '<?= htmlspecialchars((string)$result['chart_type']) ?>',
            data: {
                labels: <?= json_encode($result['labels']) ?>,
                datasets: [{
                    label: '回答数',
                    data: <?= json_encode($result['data']) ?>,
                    backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }
}
<?php } ?>
</script>

<script src="../js/api_manager.js"></script>

<?php require_once 'footer.php'; ?>
</body>
</html>