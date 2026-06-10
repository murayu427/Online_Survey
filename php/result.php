<?php
require_once __DIR__ . '/auth.php';
start_sess();
// DB接続
$conn = pg_connect("host=localhost dbname=test user=postgres password=pass");

// アンケートID（例）
$survey_id = $_GET["id"] ?? 1;

//====================================
// ① 集計データ取得（グラフ用）
//====================================

// どの基準で集計するか（例：gender or age）
$group_by = "gender"; // 本来はDB設定から取得

$sql = "SELECT a.{$group_by}, COUNT(a.*) as count, s.survey_spec
        FROM answers a
        JOIN surveys s ON a.survey_id = s.survey_id
        WHERE a.survey_id = $1 
        GROUP BY a.{$group_by}, s.survey_spec";

$result = pg_query_params($conn, $sql, [$survey_id]);

$labels = [];
$data = [];

while ($row = pg_fetch_assoc($result)) {
    $labels[] = $row[$group_by];
    $data[] = $row["count"];
    $survey_spec_str = $row["survey_spec"]; 
}

$spec_data = json_decode($survey_spec_str, true);
$chart_type = $spec_data['questions'][0]['result_display'] ?? 'bar';

// JSON化（Chart.js用）
$labels_json = json_encode($labels);
$data_json = json_encode($data);

//====================================
// ② コメント一覧取得
//====================================

$sql = "SELECT c.comment_id, c.comment, u.account_name, COUNT(l.like_id) as like_count
        FROM comments c
        LEFT JOIN users u ON c.user_id = u.user_id
        LEFT JOIN likes l ON c.comment_id = l.comment_id
        WHERE c.survey_id = $1
        GROUP BY c.comment_id, u.account_name
        ORDER BY c.created_at DESC";
$comments = pg_query_params($conn, $sql, [$survey_id]);

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>アンケート結果</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</head>
<body>

<h1>アンケート結果</h1>

<!-- ================================== -->
<!-- ③ グラフ表示 -->
<!-- ================================== -->
<canvas id="chart"></canvas>

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
                // 複数の色を配列で用意しておくと綺麗に表示されます
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

<!-- ================================== -->
<!-- ④ コメント投稿フォーム -->
<!-- ================================== -->
<h2>コメント投稿</h2>

<textarea id="comment"></textarea>
<button onclick="sendComment()">送信</button>

<hr>

<!-- ================================== -->
<!-- ⑤ コメント一覧 -->
<!-- ================================== -->
<h2>コメント一覧</h2>

<div id="comment-list">

<?php while ($row = pg_fetch_assoc($comments)) { ?>
    <div style="border:1px solid #000; margin:10px; padding:10px">
        <p><?= htmlspecialchars($row["account_name"]) ?></p>
        <p><?= nl2br(htmlspecialchars($row["comment"])) ?></p>

        <!-- いいねボタン -->
        <button onclick="likeComment(<?= $row['comment_id'] ?>)">
            👍 <span id="like-<?= $row['comment_id'] ?>">
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

---

# ✅ Ajax（JS）
<script>

//====================================
// コメント投稿
//====================================
function sendComment() {

    const comment = document.getElementById("comment").value;

    fetch("api.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({
            action: "comment",
            survey_id: <?= $survey_id ?>,
            comment: comment
        })
    })
    .then(res => res.json())
    .then(data => {

        if (data.success) {

            // 一覧を再描画（初心者向け）
            renderComments(data.comments);

            document.getElementById("comment").value = "";
        }
    });
}

//====================================
// いいね処理
//====================================
function likeComment(commentId) {

    fetch("api.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({
            action: "like",
            comment_id: commentId
        })
    })
    .then(res => res.json())
    .then(data => {

        if (data.success) {
            document.getElementById("like-" + commentId).innerText = data.like_count;
        }
    });
}

//====================================
// コメント再描画
//====================================
function escapeHTML(str) {
    if (!str) return "";
    return str.replace(/&/g, "&amp;")
              .replace(/</g, "&lt;")
              .replace(/>/g, "&gt;")
              .replace(/"/g, "&quot;")
              .replace(/'/g, "&#039;");
}

function renderComments(comments) {

    let html = "";

    comments.forEach(c => {
        // 投稿直後のバグ・セキュリティ漏れを防ぐためにエスケープ処理を適用
        const safeName = escapeHTML(c.account_name || 'ゲスト利用者');
        const safeComment = escapeHTML(c.comment).replace(/\n/g, '<br>');
        const commentId = parseInt(c.comment_id);
        const likeCount = parseInt(c.like_count || 0);

        html += `
        <div style="border:1px solid #000; margin:10px; padding:10px">
            <p><strong>${safeName}</strong></p>
            <p>${safeComment}</p>
            <button onclick="likeComment(${commentId})">
                👍 <span id="like-${commentId}">${likeCount}</span>
            </button>
        </div>
        `;
    });

    document.getElementById("comment-list").innerHTML = html;
}

</script>

</body>
</html>