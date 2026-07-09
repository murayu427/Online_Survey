<?php
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
// 【安全対策】auth.phpの関数を呼び出し
// 未ログイン、または30分以上放置されている場合は自動的にログイン画面へリダイレクトされます
start_sess();

// パラメータ名：survey_id(INT) と format(VARCHAR) のバリデーション
if (!isset($_GET['survey_id']) || !filter_var($_GET['survey_id'], FILTER_VALIDATE_INT)) {
    renderError('400 Bad Request: 不正なアンケートIDです。', 400, 'app', 'WARNING');
}

if (!isset($_GET['format']) || !in_array($_GET['format'], ['csv', 'pdf'], true)) {
    renderError('400 Bad Request: 不正なフォーマット指定です。', 400, 'app', 'WARNING');
}

$survey_id = (int)$_GET['survey_id'];
$format = $_GET['format'];
$current_user_id = (int)$_SESSION['user_id']; // login_check()を通過しているため確実に取得可能

// =========================================================================
// 3. データベースからのデータ集計（db.phpの関数を活用） 
// =========================================================================
try {
    // db.phpで定義されている共通関数を呼び出し、回答データを取得
    $results = get_responses_by_survey_id($survey_id); 

} catch (Throwable $e) {
    renderError('500 Internal Server Error: データ取得中にエラーが発生しました。', 500, 'db', 'ERROR', $e);
}

// 該当データが1件も存在しない場合は 404 Not Found 
if (empty($results)) {
    renderError('404 Not Found: 該当する回答データが見つかりません。', 404, 'app', 'WARNING');
}

// =========================================================================
// 【重要】ダウンロード権限（作成者チェック）の追加バリデーション
// =========================================================================
// ※他人のアンケート結果を不正にURL直叩きでダウンロードされるのを防ぎます。
// もし、全ユーザーがどのアンケート結果でも落として良い仕様なら、このブロックは削除してください。
/*
$db = getPdo();
$stmt = $db->prepare('SELECT creator_id FROM surveys WHERE survey_id = :survey_id LIMIT 1');
$stmt->execute([':survey_id' => $survey_id]);
$survey = $stmt->fetch();

if (!$survey || (int)$survey['creator_id'] !== $current_user_id) {
    http_response_code(403); // 403 Forbidden
    exit("403 Forbidden: このアンケート結果をダウンロードする権限がありません。");
}
*/

// =========================================================================
// 4. フォーマット別のデータ加工とHTTPヘッダー制御（ダウンロード強制出力） 
// =========================================================================

// -------------------------------------------------------------------------
// 形式 A：format=csv の場合 
// -------------------------------------------------------------------------
if ($format === 'csv') {
    // CSV用 HTTPヘッダー制御 
    header('Content-Description: File Transfer'); 
    header('Content-Transfer-Encoding: binary'); 
    header('Content-Type: text/csv; charset=utf-8'); 
    header('Content-Disposition: attachment; filename="survey_result_' . $survey_id . '.csv"'); 

    // 文字化け対策：Excel用のBOM（\xEF\xBB\xBF）を最先頭に出力 
    echo "\xEF\xBB\xBF";

    // 出力ストリームを開く
    $output = fopen('php://output', 'w');

    // CSVのヘッダー行（列名）を書き込み
    fputcsv($output, ['回答ID', 'ユーザーID', '回答データ(JSON)', '回答日時']);

    // データ整形：ループ処理で1回答1行ずつ書き出し 
    foreach ($results as $row) {
        // db.php側で既に配列にデコードされているため、そのまま再エンコードしてセルに格納
        $answer_array = $row['answer_data'] ?? [];
        
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
    // 外部ライブラリ TCPDF の読み込み
    require_once __DIR__ . '/vendor/tcpdf/tcpdf.php'; 
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false); 

    // フォント・レイアウト設定（日本語文字化け対策） 
    $pdf->SetFont('kozminproregular', '', 10); 
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetTitle('アンケート回答集計レポート');
    $pdf->AddPage();

    // HTMLテンプレートによる表の作成 
    $html = '<h1>アンケート回答一覧 (アンケートID: ' . htmlspecialchars((string)$survey_id) . ')</h1>'; 
    $html .= '<table border="1" cellpadding="5">'; 
    $html .= '<thead><tr style="background-color:#eee;">';
    $html .= '<th>回答ID</th><th>ユーザーID</th><th>回答日時</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($results as $row) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars((string)($row['response_id'] ?? '')) . '</td>';
        $html .= '<td>' . htmlspecialchars((string)($row['user_id'] ?? '匿名')) . '</td>';
        $html .= '<td>' . htmlspecialchars((string)($row['answered_at'] ?? '')) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    // TCPDFを用いてレンダリング 
    $pdf->writeHTML($html, true, false, true, false, ''); 

    // PDFを直接強制ダウンロード
    $pdf->Output("survey_report_{$survey_id}.pdf", 'D'); 
    exit;
}

renderError('400 Bad Request: 不正なフォーマット指定です。', 400, 'app', 'WARNING');