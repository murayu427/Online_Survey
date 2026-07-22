<?php
ini_set('display_errors', 0);
error_reporting(0);

// ⭕️ 日本時間に設定（日時のズレを解消）
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

$key = trim($_GET['key']); // 前後の余計な空白を削除
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
// 4. アンケートIDの取得（判定をより柔軟に強化）
// =========================================================================
try {
    $sql = "SELECT survey_id FROM surveys 
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
} catch (Exception $e) {
    http_response_code(500);
    echo "500 Internal Server Error: アンケート情報の取得中にエラーが発生しました。";
    exit;
}

// =========================================================================
// 5. データ集計（LEFT JOINでアカウント名を取得）
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
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <title>アンケート結果レポート (ID: <?php echo $survey_id; ?>)</title>
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
            .meta-info {
                margin-bottom: 20px;
                font-size: 14px;
                color: #666;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            th, td {
                border: 1px solid #ccc;
                padding: 10px;
                text-align: left;
                font-size: 13px;
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
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 15px; padding: 10px; background: #eef6ff; border: 1px solid #b6d4fe; border-radius: 4px;">
            <strong>【PDF化の手順】</strong> 画面が開くと自動的に印刷画面が出ます。「送信先」または「プリンター」で <strong>「PDFに保存」</strong> を選択して保存してください。
            <button onclick="window.print()" style="margin-left: 10px; cursor: pointer;">再表示</button>
        </div>

        <h1>アンケート回答結果レポート (ID: <?php echo $survey_id; ?>)</h1>
        <div class="meta-info">出力日時: <?php echo date('Y年m月d日 H:i'); ?></div>

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

        <script>
            // ページ読み込み完了後、自動的に印刷ダイアログ（PDF保存）を起動
            window.onload = function() {
                window.print();
            };
        </script>
    </body>
    </html>
    <?php
    exit;
}