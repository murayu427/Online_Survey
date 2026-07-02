<?php
require_once __DIR__ . '/auth.php';
start_sess();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';
// アンケートID
$result_key = $_GET["key"] ?? '';
$user_id = $_SESSION['user_id'] ?? 1;
if ($result_key === '') {
    die("エラー：無効なアクセスです。URLをご確認ください。");
}
//====================================
// ① 集計データ取得（グラフ用）
//====================================
//$responses = get_responses_by_survey_id((int)$survey_id);

$survey = get_survey_by_key($result_key, 'result');

if ($survey === null) {
    die("エラー：指定されたアンケートが見つかりません。");
}

// 取得したデータから survey_id を抽出
$survey_id = (int)$survey['survey_id'];

// アンケートの仕様（質問の一覧など）を取得
$spec_data = $survey['survey_spec'];

// 判明した survey_id を使って回答データを全件取得
$responses = get_responses_by_survey_id($survey_id);
$all_chart_data = [];

// 仕様書に定義されている質問（q1, q2...）をすべてループして集計
if (isset($spec_data['questions']) && is_array($spec_data['questions'])) {
    foreach ($spec_data['questions'] as $q) {
        $q_id = $q['id'];                // 例: 'q1', 'q2'
        $q_title = $q['title'] ?? $q_id; // 質問のタイトル

        // この質問に対する選択肢ごとの票数を数える
        $counts = [];
        foreach ($responses as $response) {
            $answers = $response['answer_data'] ?? [];
            
            if (isset($answers[$q_id])) {
                $ans = $answers[$q_id];
                
                // チェックボックスなどの複数回答（配列）の場合
                if (is_array($ans)) {
                    foreach ($ans as $a) {
                        $counts[$a] = isset($counts[$a]) ? $counts[$a] + 1 : 1;
                    }
                } 
                // ラジオボタンなどの単一回答の場合
                else {
                    $counts[$ans] = isset($counts[$ans]) ? $counts[$ans] + 1 : 1;
                }
            }
        }

        // Chart.jsで扱えるようにラベルとデータ（票数）に分解
        $labels = [];
        $data = [];
        foreach ($counts as $answer_value => $count) {
            $labels[] = $answer_value; // 選択肢の名前
            $data[] = $count;          // 獲得した票数
        }

        // グラフ種類の判定（仕様書の設定を反映）
        $chart_type = $q['result_display'] ?? 'bar'; 
        if ($chart_type === 'histogram') {
            $chart_type = 'bar'; // Chart.js用変換
        }

        // 💡 質問ID（q1など）をキーにして、集計結果をまとめて保存
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

?>

<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>アンケート結果</title>
<meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    body {
        background-color: #1e2d5a; /* 濃い紺色 */
        color: #ffffff;            /* 文字を白色に */
    }

    /* リンクの色も見やすいように調整 */
    a {
        color: #add8e6;            /* 薄い青色 */
    }
</style>
</head>
<body>

<input type="hidden" id="current-survey-id" value="<?= htmlspecialchars((string)$survey_id) ?>">
<span id="save-status" style="color: gray; font-size: 0.9em; float: right;"></span>
<h1>アンケート結果</h1>
<div id="survey-results-container">
<?php foreach ($all_chart_data as $q_id => $info) { ?>
    <div class="question-block" style="margin-bottom: 50px; border-bottom: 1px dashed #ccc; padding-bottom: 30px;">
        <h2>📊 <?= htmlspecialchars($info['title']) ?></h2>
        
        <div style="width: 400px; height: 300px;">
            <canvas id="chart-<?= htmlspecialchars($q_id) ?>"></canvas>
        </div>
    </div>
<?php } ?>
</div>


<script>
// PHP側で準備した全質問の集計データをループさせて、Chart.jsをそれぞれ実行する
<?php foreach ($all_chart_data as $q_id => $info) { ?>
{
    const ctx = document.getElementById('chart-<?= htmlspecialchars($q_id) ?>');
    new Chart(ctx, {
        type: '<?= $info['chart_type'] ?>', 
        data: {
            labels: <?= json_encode($info['labels']) ?>,
            datasets: [{
                label: '回答数',
                data: <?= json_encode($info['data']) ?>,
                backgroundColor: [
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0'
                ]
            }]
        },
        options: {
            responsive: true
        }
    });
}
<?php } ?>
</script>
<hr>

<form id="main-form" style="display:none;"></form>

<!-- ================================== -->
<!-- ④ コメント投稿フォーム -->
<!-- ================================== -->
<h2>コメント投稿</h2>

<textarea id="comment-text-area" rows="4" cols="50" placeholder="コメントを入力してください"></textarea><br>
<button onclick="postComment()">送信</button>

<hr>

<!-- ================================== -->
<!-- ⑤ コメント一覧 -->
<!-- ================================== -->
<h2>コメント一覧</h2>

<div id="comment-list">

<?php foreach ($comment_list_data as $row) { ?>
    <div style="border:1px solid #000; margin:10px; padding:10px">
        <p><strong><?= htmlspecialchars($row["account_name"] ?? $row["username"] ?? 'ゲスト利用者') ?></strong></p>
        <p><?= nl2br(htmlspecialchars($row["comment"])) ?></p>

        <button onclick="toggleLike(<?= $row['comment_id'] ?>)">
            👍 <span id="like-count-<?= $row['comment_id'] ?>">
                <?= $row["like_count"] ?? 0 ?>
            </span>
        </button>
    </div>
<?php } ?>

</div>

<hr>

<!-- ================================== -->
<!-- ⑥ CSV / PDF ダウンロード -->
<!-- ================================== -->

<a href="download.php?survey_id=<?= $survey_id ?>&format=csv" target="_blank">
    CSV形式でダウンロード
</a>
<a href="download.php?survey_id=<?= $survey_id ?>&format=pdf" target="_blank">
    PDF形式でダウンロード
</a>

<script src="api_manager.js"></script>
<?php require_once __DIR__ . '/footer.php'; ?>

</body>
</html>