<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/error.php';
start_sess();
require_once __DIR__ . '/db.php';

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
$all_chart_data = [];
$text_question_results = [];

// 仕様書に定義されている質問（q1, q2...）をすべてループして集計
if (isset($spec_data['questions']) && is_array($spec_data['questions'])) {
    foreach ($spec_data['questions'] as $index => $q) {
        // $q が配列ではない、または 'id' が存在しない不正なデータなら無視して次へ進む
        if (!is_array($q)){# || !isset($q['id'])) {
            continue;
        }
        $q_id = "q".$index;                // 例: 'q1', 'q2'
        $q_title = $q['label'] ?? $q['title'] ?? $q_id; // 質問のタイトル
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

            $text_question_results[] = [
                'title' => $q_title,
                'answers' => $answers,
            ];
            continue;
        }
        // この質問に対する選択肢ごとの票数を数える
        $counts = [];
        foreach ($responses as $index => $response) {
            $answers = $response['answer_data'] ?? [];
            if (isset($answers[$q_id])) {
                $ans = $answers[$q_id];
                // チェックボックスなどの複数回答（配列）の場合
                if (is_array($ans)) {
                    foreach ($ans as $a) {
                        // null（空っぽ）を配列のキー（名前）にしようとするとエラーになるため、「未回答」などの文字に変換する
                        $answer_key = $a ?? '無効な回答';
                        $counts[$answer_key] = isset($counts[$answer_key]) ? $counts[$answer_key] + 1 : 1;
                    }
                } 
                // ラジオボタンなどの単一回答の場合
                else {
                    $answer_key = $ans ?? '無効な回答';
                    $counts[$answer_key] = isset($counts[$answer_key]) ? $counts[$answer_key] + 1 : 1;
                }
            }
        }
        // Chart.jsで扱えるようにラベルとデータ（票数）に分解
        $labels = [];
        $data = [];
        foreach ($counts as $answer_value => $count) {
            $labels[] = (string)$answer_value; // 確実に文字列にする
            $data[] = $count;          
        }

        // グラフ種類の判定（仕様書の設定を反映）
        $chart_type = $q['result_display'] ?? 'bar'; 
        if ($chart_type === 'histogram') {
            $chart_type = 'bar'; // Chart.js用変換
        }

        // 質問ID（q1など）をキーにして、集計結果をまとめて保存
        $all_chart_data[$q_id] = [
            'title'      => $q_title,
            'chart_type' => $chart_type,
            'labels'     => $labels,
            'data'       => $data
        ];
    }
}
//====================================
// ② コメント一覧取得
//====================================
$comment_list_data = get_comments_by_survey_id((int)$survey_id);
$chart_keys = array_keys($all_chart_data);
$last_chart_key = end($chart_keys);
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
    <?php if(!empty($responses)):?>
        <div id="survey-results-container">
        <?php foreach ($all_chart_data as $q_id => $info) { 
            if ($q_id === $last_chart_key) continue; 
        ?>
            <div class="question-block" style="margin-bottom: 50px; border-bottom: 1px dashed #ccc; padding-bottom: 30px;">
                <h2 class="text-xl font-semibold mb-4">📊 質問: <?= htmlspecialchars((string)$info['title']) ?></h2>
                <div style="width: 400px; height: 300px;">
                    <canvas id="chart-<?= htmlspecialchars((string)$q_id) ?>"></canvas>
                </div>
            </div>
        <?php } ?>
    </div>

    <?php if ($last_chart_key !== false) { 
        $last_info = $all_chart_data[$last_chart_key];
    ?>
    <div class="flex-container">
        <div class="flex-item">
            <h2 class="text-xl font-semibold mb-4">📊 質問: <?= htmlspecialchars((string)$last_info['title']) ?></h2>
            <div style="width: 100%; max-width: 500px; height: 300px;">
                <canvas id="chart-<?= htmlspecialchars((string)$last_chart_key) ?>"></canvas>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($text_question_results)): ?>
        <section class="comment-section">
            <h2 class="text-xl font-semibold mb-4">📝 自由記述の結果</h2>
            <?php foreach ($text_question_results as $textResult): ?>
                <div class="comment-box" style="margin-bottom: 16px;">
                    <h3 class="text-lg font-semibold mb-2">質問: <?= htmlspecialchars((string)$textResult['title']) ?></h3>
                    <?php if (!empty($textResult['answers'])): ?>
                        <?php foreach ($textResult['answers'] as $answer): ?>
                            <p style="text-indent: 0; margin-bottom: 0.5rem;">・<?= nl2br(htmlspecialchars($answer)) ?></p>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-indent: 0; margin-bottom: 0;">回答はありません。</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <section class="comment-section">
            <h2 class="text-xl font-semibold mb-4">💬 コメント</h2>

            <div class="comment-input-wrap">
                <textarea id="comment-text-area" rows="3" class="w-full p-2 text-black rounded" placeholder="コメントを入力してください"></textarea><br>
                <button onclick="postComment()" class="mt-2 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded lift-button">送信</button>
            </div>

            <div id="comment-list" style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
            <?php foreach ($comment_list_data as $row) { 
                $name = $row["account_name"] ?? $row["username"] ?? 'ゲスト利用者';
                $comment = $row["comment"];
            ?>
                <div class="comment-item">
                    <p style="margin-top: 0;"><strong><?= htmlspecialchars($name) ?></strong></p>
                    <p><?= nl2br(htmlspecialchars($comment)) ?></p>
                    <button type="button" onclick="toggleLike(<?= (int)$row['comment_id'] ?>)" class="mt-2 border border-gray-300 px-3 py-1 rounded-full text-sm lift-button">
                        👍 <span id="like-count-<?= (int)$row['comment_id'] ?>"><?= $row["like_count"] ?? 0 ?></span>
                    </button>
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
</main>

<form id="main-form" style="display:none;"></form>

<script>
// Chart.jsの文字色をすべて「白」に統一
Chart.defaults.color = '#ffffff';

// 全質問のグラフ描画JS
<?php foreach ($all_chart_data as $q_id => $info) { ?>
{
    const ctx = document.getElementById('chart-<?= htmlspecialchars((string)$q_id) ?>');
    if (ctx) {
        new Chart(ctx, {
            type: '<?= $info['chart_type'] ?>', 
            data: {
                labels: <?= json_encode($info['labels']) ?>,
                datasets: [{
                    label: '回答数',
                    data: <?= json_encode($info['data']) ?>,
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