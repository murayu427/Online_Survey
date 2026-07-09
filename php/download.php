<?php
// 画面へのエラー表示を一時的にオフにし、PDFデータの汚染を防ぐ
ini_set('display_errors', 0);
error_reporting(0);

// =========================================================================
// 1. 関連モジュール（依存関係）の読み込み 
// =========================================================================
require_once __DIR__ . '/auth.php';     // セッション認証・ログインチェック用 
require_once __DIR__ . '/error.php';    // 共通エラー表示用
require_once __DIR__ . '/db.php';       // データベース操作・共通関数用（前田さんの共通関数）
require_once __DIR__ . '/security.php'; // パラメータのサニタイズ・セキュリティ用 

// =========================================================================
// 2. 認証チェックおよびパラメータの取得とバリデーション
// =========================================================================
start_sess();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!isset($_GET['key']) || empty($_GET['key'])) {
    renderError('400 Bad Request: 不正なアンケートキーです。', 400, 'app', 'WARNING');
}

if (!isset($_GET['format']) || !in_array($_GET['format'], ['csv', 'pdf'], true)) {
    renderError('400 Bad Request: 不正なフォーマット指定です。', 400, 'app', 'WARNING');
}

$key = $_GET['key'];
$format = $_GET['format'];
$current_user_id = (int)$_SESSION['user_id'];

try {
    $db = getPdo();
    $stmt = $db->prepare('SELECT survey_id, creator_id FROM surveys WHERE question_key = :key OR result_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $survey = $stmt->fetch();

    if (!$survey) {
        renderError('400 Bad Request: 該当するアンケートが見つかりません。', 400, 'app', 'WARNING');
    }

    $survey_id = (int)$survey['survey_id'];

} catch (Throwable $e) {
    renderError('500 Internal Server Error: アンケート情報の取得中にエラーが発生しました。', 500, 'db', 'ERROR', $e);
}

// =========================================================================
// 3. データベースからのデータ集計（db.phpの関数を活用） 
// =========================================================================
try {
    $results = get_responses_by_survey_id($survey_id); 

} catch (Throwable $e) {
    renderError('500 Internal Server Error: データ取得中にエラーが発生しました。', 500, 'db', 'ERROR', $e);
}

if (empty($results)) {
    renderError('404 Not Found: 該当する回答データが見つかりません。', 404, 'app', 'WARNING');
}

// =========================================================================
// 4. フォーマット別のデータ加工とHTTPヘッダー制御
// =========================================================================

// -------------------------------------------------------------------------
// 形式 A：format=csv の場合 
// -------------------------------------------------------------------------
if ($format === 'csv') {
    header('Content-Description: File Transfer'); 
    header('Content-Transfer-Encoding: binary'); 
    header('Content-Type: text/csv; charset=utf-8'); 
    header('Content-Disposition: attachment; filename="survey_result_' . $survey_id . '.csv"'); 

    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['回答ID', 'ユーザーID', '回答データ', '回答日時'], ",", '"', "");

    foreach ($results as $row) {
        $answer_array = $row['answer_data'] ?? [];
        $readable_answers = [];
        if (is_array($answer_array)) {
            foreach ($answer_array as $q_key => $a_val) {
                if (is_array($a_val)) {
                    $a_val = implode(', ', $a_val);
                }
                $a_val = str_replace(["\r\n", "\r", "\n"], " ", (string)$a_val);
                $readable_answers[] = "[{$q_key}: {$a_val}]";
            }
        }
        $answer_text = !empty($readable_answers) ? implode(' ', $readable_answers) : '回答なし';
        $formatted_date = !empty($row['answered_at']) ? date('Y/m/d H:i', strtotime($row['answered_at'])) : '未回答';
            
        fputcsv($output, [
            $row['response_id'] ?? '',
            $row['user_id'] ?? '匿名(未ログイン)',
            json_encode($answer_array, JSON_UNESCAPED_UNICODE), 
            $row['answered_at'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

// -------------------------------------------------------------------------
// 形式 B：format=pdf の場合 
// -------------------------------------------------------------------------
if ($format === 'pdf') {
    // テキストデータの組み立て
    $text = "Survey Report (Survey ID: " . $survey_id . ")\n";
    $text .= "---------------------------------------------------------------------------------\n";
    
    // 各列の文字幅：ユーザーID(10文字), 回答日時(16文字)
    $format_string = " %-10s | %-16s | %s\n";
    
    // ⭕️ ヘッダーを文字化けしない英字表記に変更
    $text .= sprintf($format_string, "USER ID", "ANSWER DATE", "ANSWERS");
    $text .= "---------------------------------------------------------------------------------\n";
    
    foreach ($results as $row) {
        // 1. IDの設定
        $user_display_id = 'Guest';
        if (!empty($row['user_id'])) {
            $user_display_id = (string)$row['user_id'];
        }
        
        // 4. 日時の変換（すでに数字なのでそのまま）
        $formatted_date = !empty($row['answered_at']) ? date('Y/m/d H:i', strtotime($row['answered_at'])) : 'Unknown';

        // 5. 回答内容のテキスト化（日本語による文字化けを防ぐため、英字表記 [Japanese Answer] に変換）
        $answer_array = $row['answer_data'] ?? [];
        $readable_answers = [];
        if (is_array($answer_array)) {
            foreach ($answer_array as $q_key => $a_val) {
                if (is_array($a_val)) { $a_val = implode(', ', $a_val); }
                $a_val = str_replace(["\r\n", "\r", "\n", "(", ")"], " ", (string)$a_val);
                
                // 全角日本語（ひらがな、カタカナ、漢字）が含まれている場合は [JP Answer] に置き換えてクラッシュを完全防止
                if (preg_match('/[ぁ-んァ-ヶー一-龠]/u', $a_val)) {
                    $a_val = '[JP Answer]';
                }
                
                $readable_answers[] = "{$q_key}:{$a_val}";
            }
        }
        $answer_text = !empty($readable_answers) ? implode('  ', $readable_answers) : 'None';

        $text .= sprintf(
            $format_string,
            $user_display_id,
            $formatted_date,
            $answer_text
        );
    }
    $text .= "---------------------------------------------------------------------------------\n";
    
    // PDFの等幅テキストストリーム生成（改行コードを \n に統一）
    $stream = "BT\n/F1 10 Tf\n40 800 Td\n14 TL\n";
    foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
        $stream .= "(" . addcslashes($line, '()\\') . ") Tj T*\n";
    }
    $stream .= "ET";
    
    // 各オブジェクトを配列にまとめ、正確にバイト数を自動計算
    $chunks = [];
    $chunks[0] = "%PDF-1.4\n";
    $chunks[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $chunks[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $chunks[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Courier >> >> >> >>\nendobj\n";
    $chunks[4] = "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n";

    // 各セクションの開始位置（バイト数）を動的に測定して結合
    $offsets = [];
    $pdf_data = $chunks[0];
    for ($i = 1; $i <= 4; $i++) {
        $offsets[$i] = strlen($pdf_data);
        $pdf_data .= $chunks[$i];
    }

    // クロスリファレンステーブル(xref)の正確な開始位置を記録
    $xref_pos = strlen($pdf_data);

    // クロスリファレンステーブルとトレイラーの生成
    $pdf_data .= "xref\n0 5\n";
    $pdf_data .= "0000000000 65535 f \n";
    $pdf_data .= sprintf("%010d 00000 n \n", $offsets[1]);
    $pdf_data .= sprintf("%010d 00000 n \n", $offsets[2]);
    $pdf_data .= sprintf("%010d 00000 n \n", $offsets[3]);
    $pdf_data .= sprintf("%010d 00000 n \n", $offsets[4]);
    $pdf_data .= "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" . $xref_pos . "\n%%EOF";

    // ブラウザに送信するHTTPヘッダー
    header('Content-Description: File Transfer');
    header('Content-Transfer-Encoding: binary');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="survey_report_' . $survey_id . '.pdf"');
    header('Content-Length: ' . strlen($pdf_data));

    // 出力バッファを一掃
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo $pdf_data;
    exit;
}

renderError('400 Bad Request: 不正なフォーマット指定です。', 400, 'app', 'WARNING');