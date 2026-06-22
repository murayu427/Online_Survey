<?php
require_once __DIR__ . '/auth.php';
start_sess();
require_once __DIR__ . '/db.php';
// アンケートID
$survey_id = $_GET["id"] ?? 1;
$question_id = $_GET["question_id"] ?? 'q1';
$user_id = $_SESSION['user_id'] ?? 1;
//====================================
// ① 集計データ取得（グラフ用）
//====================================
$responses = get_responses_by_survey_id((int)$survey_id);
/*$sql = "SELECT a.answer_value, COUNT(a.*) as count, s.survey_spec
        FROM answers a
        JOIN surveys s ON a.survey_id = s.survey_id
        WHERE a.survey_id = :survey_id AND a.question_id = :question_id
        GROUP BY a.answer_value, s.survey_spec";

$stmt = executeQuery($sql, [
    ':survey_id'   => $survey_id,
    ':question_id' => $question_id
]);
*/
$sql_spec = "SELECT survey_spec FROM surveys WHERE survey_id = :survey_id";
$stmt_spec = executeQuery($sql_spec, [':survey_id' => $survey_id]);
$survey_row = $stmt_spec->fetch();
$survey_spec_str = $survey_row['survey_spec'] ?? '{}';
$spec_data = json_decode($survey_spec_str, true);

$counts = [];
foreach ($responses as $response) {
    // db.php 側で既に配列化されている回答データを取り出す
    $answers = $response['answer_data'] ?? [];
    
    // ユーザーがこの質問（$question_id）に回答している場合のみ処理
    if (isset($answers[$question_id])) {
        $ans = $answers[$question_id];
        
        // チェックボックスなどの複数回答（配列）の場合
        if (is_array($ans)) {
            foreach ($ans as $a) {
                // 既に箱があれば+1、無ければ1をセット
                $counts[$a] = isset($counts[$a]) ? $counts[$a] + 1 : 1;
            }
        } 
        // ラジオボタンやテキストなどの単一回答の場合
        else {
            $counts[$ans] = isset($counts[$ans]) ? $counts[$ans] + 1 : 1;
        }
    }
}
$labels = [];
$data = [];
foreach ($counts as $answer_value => $count) {
    $labels[] = $answer_value; // 選択肢の名前
    $data[] = $count;          // 獲得した票数
}


$chart_type = 'bar'; // 見つからなかったときの初期値

if (isset($spec_data['questions'])) {
    foreach ($spec_data['questions'] as $q) {
        // 今見ている質問ID（例: q1）の設定を探し出す
        if ($q['id'] === $question_id) {
            // 仕様書にある pie, histogram, bar などの形式を取得
            $chart_type = $q['result_display'] ?? 'bar'; 
            
            // Chart.jsで「histogram」は「bar(棒グラフ)」として描画するため変換
            if ($chart_type === 'histogram') {
                $chart_type = 'bar';
            }
            break;
        }
    }
}
// JSON化（Chart.js用）
$labels_json = json_encode($labels);
$data_json = json_encode($data);

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

</head>
<body>

<input type="hidden" id="current-survey-id" value="<?= htmlspecialchars((string)$survey_id) ?>">
<span id="save-status" style="color: gray; font-size: 0.9em; float: right;"></span>
<h1>アンケート結果</h1>
<div style="margin-bottom: 20px;">
    <strong>質問を切り替える：</strong>
    <a href="result.php?id=<?= $survey_id ?>&question_id=q1">🔌 質問1</a> | 
    <a href="result.php?id=<?= $survey_id ?>&question_id=q2">🎮 質問2</a> | 
    <a href="result.php?id=<?= $survey_id ?>&question_id=q3">💬 質問3</a>
</div>

<div style="width: 400px; height: 300px;">
    <canvas id="chart"></canvas>
</div>
<!-- ================================== -->
<!-- ③ グラフ表示 -->
<!-- ================================== -->
<script>
const ctx = document.getElementById('chart');
new Chart(ctx, {
    type: '<?= $chart_type ?>', 
    data: {
        labels: <?= $labels_json ?>,
        datasets: [{
            label: '回答数',
            data: <?= $data_json ?>,
            backgroundColor: [
                // 円グラフ（pie）などの場合は、棒ごとに色が変わるように
                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0'
            ]
        }]
    },
    options: {
        responsive: true
        // グラフの種類に応じた細かい設定（オプション）もここに書けます
    }
});
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

</body>
</html>