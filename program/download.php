<?php
// =========================================================================
// 1. 関連モジュール（依存関係）の読み込み 
// =========================================================================
require_once 'auth.php';     // セッション認証・権限チェック用 
require_once 'db.php';       // 前田さんのデータ操作用共通関数 
require_once 'security.php'; // パラメータのサニタイズ・セキュリティ用 

// =========================================================================
// 2. 例外・エラーハンドリング（パラメータの取得とバリデーション） 
// =========================================================================
// パラメータ名：survey_id(INT) と format(VARCHAR) 
if (!isset($_GET['survey_id']) || !filter_var($_GET['survey_id'], FILTER_VALIDATE_INT)) {
    http_response_code(400); // 400 Bad Request 
    exit("400 Bad Request: 不正なアンケートIDです。");
}

if (!isset($_GET['format']) || !in_array($_GET['format'], ['csv', 'pdf'], true)) {
    http_response_code(400); // 400 Bad Request 
    exit("400 Bad Request: 不正なフォーマット指定です。");
}

$survey_id = (int)$_GET['survey_id'];
$format = $_GET['format'];

// 【安全対策】セッション認証チェック 
// ※auth.phpで未ログインを弾く処理、または作成者権限のチェックが実装されている想定 

// =========================================================================
// 3. データベースからのデータ集計（前田さんの関数を活用） 
// =========================================================================
// 前田さんが用意してくれた「アンケート回答一覧を取得する」関数を呼び出す 
$results = get_responses_by_survey_id($survey_id); 

// 該当データが1件も存在しない場合は 404 Not Found 
if (empty($results)) {
    http_response_code(404);
    exit("404 Not Found: 該当する回答データが見つかりません。");
}

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
    // ※実際のカラム名（回答ID、ユーザーID、回答日時など）に合わせて調整
    fputcsv($output, ['回答ID', 'ユーザーID', '回答データ(JSONB)', '年齢', '性別', '回答日時']);

    // データ整形：ループ処理で1回答1行ずつ書き出し 
    foreach ($results as $row) {
        // answer_data（JSONB型）をPHPの連想配列にデコード
        // ※仕様書の指示通り、必要に応じてここでq1, q2などの配列を展開（平坦化）してもOKです
        $answer_json = json_decode($row['answer_data'], true); 
        
        fputcsv($output, [
            $row['response_id'] ?? '',
            $row['user_id'] ?? '匿名(未ログイン)',
            json_encode($answer_json, JSON_UNESCAPED_UNICODE), // JSONを文字列化してセルに格納 
            $row['respondent_age'] ?? '未回答',
            $row['respondent_gender'] ?? '未回答',
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
    // 外部ライブラリ TCPDF の読み込みとインスタンス化 
    require_once 'vendor/tcpdf/tcpdf.php'; 
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false); 

    // フォント・レイアウト設定（日本語文字化け・豆腐化対策） 
    $pdf->SetFont('kozminproregular', '', 10); // 仕様書指定の日本語フォントを設定 
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetTitle('アンケート回答集計レポート');
    $pdf->AddPage();

    // HTMLテンプレートによる綺麗な表（<table>）の描画データ作成 
    $html = '<h1>アンケート回答一覧 (アンケートID: ' . htmlspecialchars($survey_id) . ')</h1>'; 
    $html .= '<table border="1" cellpadding="5">'; 
    $html .= '<thead><tr style="background-color:#eee;">';
    $html .= '<th>回答ID</th><th>ユーザーID</th><th>年齢</th><th>性別</th><th>回答日時</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($results as $row) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($row['response_id'] ?? '') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['user_id'] ?? '匿名') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['respondent_age'] ?? '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['respondent_gender'] ?? '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['answered_at'] ?? '') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    // TCPDFの writeHTML() メソッドを用いて美しく動的レンダリング 
    $pdf->writeHTML($html, true, false, true, false, ''); 

    // PDF用 HTTPヘッダー制御（TCPDFのOutputメソッドの引数 'D' で直接強制ダウンロード）
    $pdf->Output("survey_report_{$survey_id}.pdf", 'D'); 
    exit;
}