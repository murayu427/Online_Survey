<?php
ini_set('display_errors', 0);
error_reporting(0);

// 日本時間に設定（日時のズレを解消）
date_default_timezone_set('Asia/Tokyo');

// =========================================================================
// 1. 独立した認証チェック
// =========================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: signin.php');
    exit;
}

// =========================================================================
// 2. パラメータの取得とバリデーション
// =========================================================================
if (!isset($_GET['key']) || empty($_GET['key'])) {
    http_response_code(400);
    echo "400 Bad Request: 不正なアンケートキーです。";
    exit;
}

if (!isset($_GET['format']) || !in_array($_GET['format'], ['csv', 'pdf'], true)) {
    http_response_code(400);
    echo "400 Bad Request: 不正なフォーマット指定です。";
    exit;
}

$key = trim($_GET['key']);
$format = $_GET['format'];
$current_user_id = (int)$_SESSION['user_id'];

// =========================================================================
// 3. 独立したデータベース接続
// =========================================================================
$dsn = getenv('DB_DSN') ?: 'pgsql:dbname=group1db;options=--client_encoding=UTF8';
$db_user = getenv('DB_USER') ?: 'group1';
$db_pass = getenv('DB_PASS') ?: 'Group1';

try {
    $db = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo "500 Internal Server Error: データベース接続に失敗しました。";
    exit;
}

// =========================================================================
// 4. アンケートIDおよびタイトルの取得
// =========================================================================
try {
    $sql = "SELECT survey_id, title FROM surveys 
            WHERE question_key::text = :key 
               OR result_key::text = :key 
               OR question_key::text LIKE :key_like
               OR result_key::text LIKE :key_like 
            LIMIT 1";
            
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':key' => $key,
        ':key_like' => '%' . $key . '%'
    ]);
    $survey = $stmt->fetch();

    if (!$survey) {
        http_response_code(400);
        echo "400 Bad Request: 該当するアンケートが見つかりません。(送信されたキー: " . htmlspecialchars($key) . ")";
        exit;
    }

    $survey_id = (int)$survey['survey_id'];
    $survey_title = !empty($survey['title']) ? $survey['title'] : 'アンケート';
} catch (Exception $e) {
    http_response_code(500);
    echo "500 Internal Server Error: アンケート情報の取得中にエラーが発生しました。";
    exit;
}

// =========================================================================
// 5. データ集計
// =========================================================================
try {
    $sql = 'SELECT r.response_id, r.user_id, r.answer_data, r.answered_at, u.account_name 
            FROM responses r 
            LEFT JOIN users u ON r.user_id = u.user_id 
            WHERE r.survey_id = :survey_id 
            ORDER BY r.answered_at ASC';
    
    $stmt = $db->prepare($sql);
    $stmt->execute([':survey_id' => $survey_id]);
    $results = $stmt->fetchAll();

    if (empty($results)) {
        header('Content-Description: File Transfer'); 
        header('Content-Type: text/csv; charset=utf-8'); 
        header('Content-Disposition: attachment; filename="survey_result_' . $survey_id . '_empty.csv"'); 
        echo "\xEF\xBB\xBF回答データがまだありません。";
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "500 Internal Server Error: データ取得中にエラーが発生しました。";
    exit;
}

// -------------------------------------------------------------------------
// 5.5. PDF用グラフ集計ロジック（回答値の出現度数をカウント）
// -------------------------------------------------------------------------
$summary_counts = [];
foreach ($results as $row) {
    $answer_array = [];
    if (!empty($row['answer_data'])) {
        if (is_string($row['answer_data'])) {
            $answer_array = json_decode($row['answer_data'], true) ?? [];
        } else {
            $answer_array = $row['answer_data'];
        }
    }
    if (is_array($answer_array)) {
        foreach ($answer_array as $val) {
            if (is_array($val)) {
                $val = implode(', ', $val);
            }
            $val = trim((string)$val);
            if ($val !== '') {
                $summary_counts[$val] = ($summary_counts[$val] ?? 0) + 1;
            }
        }
    }
}

// =========================================================================
// 6. フォーマット別の出力制御（CSV）
// =========================================================================
if ($format === 'csv') {
    header('Content-Description: File Transfer'); 
    header('Content-Transfer-Encoding: binary'); 
    header('Content-Type: text/csv; charset=utf-8'); 
    header('Content-Disposition: attachment; filename="survey_result_' . $survey_id . '.csv"'); 

    echo "\xEF\xBB\xBF"; 
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['回答番号', 'ユーザーID', '回答内容', '回答日時'], ",", '"', "");

    $count = 1;
    foreach ($results as $row) {
        $answer_array = [];
        if (!empty($row['answer_data'])) {
            if (is_string($row['answer_data'])) {
                $answer_array = json_decode($row['answer_data'], true) ?? [];
            } else {
                $answer_array = $row['answer_data'];
            }
        }

        $readable_answers = [];
        if (is_array($answer_array)) {
            foreach ($answer_array as $q_key => $a_val) {
                if (is_array($a_val)) {
                    $a_val = implode(', ', $a_val);
                }
                $a_val = str_replace(["\r\n", "\r", "\n"], " ", (string)$a_val);
                $readable_answers[] = $a_val;
            }
        }
        
        $answer_text = !empty($readable_answers) ? implode(' | ', $readable_answers) : '回答なし';
        $formatted_date = !empty($row['answered_at']) ? date('Y/m/d H:i', strtotime($row['answered_at'])) : '未回答';
        $user_display_id = !empty($row['account_name']) ? $row['account_name'] : '匿名(未ログイン)';
            
        fputcsv($output, [
            $count,
            $user_display_id,
            $answer_text, 
            $formatted_date
        ], ",", '"', "");

        $count++;
    }
    fclose($output);
    exit;
}

// =========================================================================
// 7. フォーマット別の出力制御（PDF用プリント画面）
// =========================================================================
if ($format === 'pdf') {
    $chart_labels = array_keys($summary_counts);
    $chart_data = array_values($summary_counts);
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($survey_title, ENT_QUOTES, 'UTF-8'); ?>_回答結果レポート</title>
        <!-- Chart.js（グラフ描写ライブラリ）の読み込み -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <style>
            body {
                font-family: "Helvetica Neue", Arial, "Hiragino Kaku Gothic ProN", "Hiragino Sans", MeiRyo, sans-serif;
                margin: 20px;
                color: #333;
            }
            h1 {
                font-size: 20px;
                border-bottom: 2px solid #333;
                padding-bottom: 8px;
            }
            h2 {
                font-size: 16px;
                margin-top: 30px;
                border-left: 4px solid #1F4E78;
                padding-left: 8px;
                color: #1F4E78;
            }
            .meta-info {
                margin-bottom: 15px;
                font-size: 14px;
                color: #666;
            }
            .chart-box {
                width: 100%;
                max-width: 650px;
                height: 280px;
                margin: 15px auto 30px auto;
                padding: 15px;
                background: #f9f9f9;
                border: 1px solid #e0e0e0;
                border-radius: 6px;
                page-break-inside: avoid; /* グラフの途中でページ跨ぎを防止 */
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
                page-break-inside: auto;
            }
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            th, td {
                border: 1px solid #ccc;
                padding: 8px 10px;
                text-align: left;
                font-size: 12px;
                word-break: break-all;
            }
            th {
                background-color: #f2f2f2;
                font-weight: bold;
            }
            tr:nth-child(even) {
                background-color: #fafafa;
            }
            @media print {
                .no-print {
                    display: none;
                }
                body {
                    margin: 0;
                }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 15px; padding: 10px; background: #eef6ff; border: 1px solid #b6d4fe; border-radius: 4px;">
            <strong>【PDF保存の手順】</strong> グラフ描画後に印刷ダイアログが開きます。「送信先」で <strong>「PDFに保存」</strong> を選択してください。
            <button onclick="window.print()" style="margin-left: 10px; cursor: pointer;">再表示</button>
        </div>

        <h1>【<?php echo htmlspecialchars($survey_title, ENT_QUOTES, 'UTF-8'); ?>】回答結果レポート</h1>
        <div class="meta-info">
            総回答数: <strong><?php echo count($results); ?>件</strong> | 出力日時: <?php echo date('Y年m月d日 H:i'); ?>
        </div>

        <!-- ⭕️ 1. 回答データ一覧テーブル（先） -->
        <h2>回答データ一覧</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 8%;">No.</th>
                    <th style="width: 22%;">ユーザー名</th>
                    <th style="width: 50%;">回答内容</th>
                    <th style="width: 20%;">回答日時</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $count = 1;
                foreach ($results as $row) {
                    $user_display_id = !empty($row['account_name']) ? $row['account_name'] : '匿名(未ログイン)';
                    $formatted_date = !empty($row['answered_at']) ? date('Y/m/d H:i', strtotime($row['answered_at'])) : '未回答';

                    $answer_array = [];
                    if (!empty($row['answer_data'])) {
                        if (is_string($row['answer_data'])) {
                            $answer_array = json_decode($row['answer_data'], true) ?? [];
                        } else {
                            $answer_array = $row['answer_data'];
                        }
                    }

                    $readable_answers = [];
                    if (is_array($answer_array)) {
                        foreach ($answer_array as $q_key => $a_val) {
                            if (is_array($a_val)) {
                                $a_val = implode(', ', $a_val);
                            }
                            $readable_answers[] = htmlspecialchars((string)$a_val, ENT_QUOTES, 'UTF-8');
                        }
                    }
                    $answer_text = !empty($readable_answers) ? implode(' <br> ', $readable_answers) : '回答なし';
                ?>
                    <tr>
                        <td><?php echo $count; ?></td>
                        <td><?php echo htmlspecialchars($user_display_id, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $answer_text; ?></td>
                        <td><?php echo $formatted_date; ?></td>
                    </tr>
                <?php
                    $count++;
                }
                ?>
            </tbody>
        </table>

        <!-- ⭕️ 2. 回答集計グラフ（後） -->
        <h2>回答集計グラフ</h2>
        <div class="chart-box">
            <canvas id="summaryChart"></canvas>
        </div>

        <script>
            // グラフ用データの受渡し
            const labels = <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>;
            const dataValues = <?php echo json_encode($chart_data); ?>;

            // Chart.js グラフ描画設定
            const ctx = document.getElementById('summaryChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar', // 棒グラフ
                data: {
                    labels: labels,
                    datasets: [{
                        label: '回答件数',
                        data: dataValues,
                        backgroundColor: 'rgba(31, 78, 120, 0.75)',
                        borderColor: 'rgba(31, 78, 120, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false, // 印刷時にズレないようアニメーションオフ
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        }
                    }
                }
            });

            // 描画完了を少しだけ待ってから自動印刷（PDF化）を実行
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 300);
            };
        </script>
    </body>
    </html>
    <?php
    exit;
}