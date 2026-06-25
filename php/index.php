<?php
// =========================================================================
// 整理番号: 4 | ファイル名: index.php
// 担当者：酒匂 莉乃
// プログラム名：ホームページ制御プログラム
// =========================================================================

// セッション管理モジュールを読み込み
require_once 'auth.php';

// 共通DB接続・操作モジュールを読み込み
require_once 'db.php';

// セキュリティモジュールを読み込み
require_once 'security.php';

start_sess(); // セッション開始（auth.phpの関数を使用）

// 安全にHTMLエスケープを行うための共通関数
if (!function_exists('h')) {
    function h($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// ユーザー状態の検知
$is_logged_in = isset($_SESSION['user_id']); 
$current_user_id = $is_logged_in ? (int)$_SESSION['user_id'] : null;

// サインアウト処理のハンドリング
if (isset($_GET['action']) && $_GET['action'] === 'signout') {
    del_sess();
    header("Location: index.php");
    exit;
}

// 退会処理のハンドリング
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    if ($is_logged_in) {
        $delete_success = delete_user($current_user_id);
        if ($delete_success) {
            del_sess();
            header("Location: index.php");
            exit;
        } else {
            $withdraw_error = "退会処理に失敗しました。システム管理者に連絡してください。";
        }
    }
}

// 並べ替えパラメータ検知
$sort_type = isset($_GET['sort']) ? $_GET['sort'] : 'start';

// パラメータを新関数的 $sortOrder 用にマッピング
$sort_order = '新着'; 
if ($sort_type === 'deadline') {
    $sort_order = '開始期限'; 
} elseif ($sort_type === 'responses') {
    $sort_order = '回答数'; 
}

// =========================================================================
// JavaScriptからの延長リクエストを処理するAPIロジック
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api']) && $_GET['api'] === 'extend') {
    header('Content-Type: application/json; charset=utf-8');
    
    // JS側から送られてくるJSONデータをデコードして取得
    $raw_input = file_get_contents('php://input');
    $input_data = json_decode($raw_input, true);
    
    // 認証チェック、および必要なパラメータ（survey_id と new_end_at）の存在チェック
    if (!$is_logged_in || !isset($input_data['survey_id']) || !isset($input_data['new_end_at'])) {
        echo json_encode(['success' => false, 'message' => '認証エラーまたはパラメータ不足です。']);
        exit;
    }
    
    $target_survey_id = (int)$input_data['survey_id'];
    $new_end_at       = $input_data['new_end_at']; // JSから届いた日時文字列
    
    try {
        // db.php に追加された新関数を呼び出して期限を更新
        $updated_time = extend_survey_deadline($target_survey_id, $current_user_id, $new_end_at);
        
        if ($updated_time) {
            // 成功時：確定した日時（Y.m.d H:i などの形式）をJSへ返却
            echo json_encode([
                'success'  => true, 
                'new_time' => $updated_time,
                'message'  => "回答期限を {$updated_time} まで延長しました。"
            ]);
        } else {
            // 失敗時（他人のアンケート、またはバリデーションエラーなど）
            echo json_encode([
                'success' => false, 
                'message' => '期限の更新に失敗しました。'
            ]);
        }
        exit;
    } catch (Exception $e) {
        // 例外発生時のログ出力とエラー返却
        error_log("期限延長APIエラー: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'システムエラーが発生しました。'
        ]);
        exit;
    }
}

// ==========================================
// 100件ごとのページ分割制御ロジック
// ==========================================
$page_active = isset($_GET['p_act']) ? max(1, (int)$_GET['p_act']) : 1;
$page_result = isset($_GET['p_res']) ? max(1, (int)$_GET['p_res']) : 1;
$limit = 100; 

$offset_active = ($page_active - 1) * $limit;
$offset_result = ($page_result - 1) * $limit;

$created_surveys = [];
$answered_surveys = [];
$active_surveys = [];
$result_surveys = [];

try {
    // 1. ログインユーザー専用データ（第4引数に $limit, 第5引数に $offset_active を渡す）
    if ($is_logged_in) {
        $created_surveys  = get_homepage_survey_list('作成したアンケート', $sort_order, $current_user_id, $limit, $offset_active);
        $answered_surveys = get_homepage_survey_list('回答したアンケート', $sort_order, $current_user_id, $limit, $offset_active);
    }
    
    // 2. 全体公開用アンケート
    // ① ページ総数を計算するために、まずは上限なし（PHPの最大整数値）で全件数を数える
    $all_active_surveys = get_homepage_survey_list('アンケート', $sort_order, null, PHP_INT_MAX, 0);
    $total_active = count($all_active_surveys);
    $total_pages_active = ceil($total_active / $limit);
    
    // ② 表示用データの取得で、第4引数に $limit, 第5引数に $offset_active を渡す
    $active_surveys = get_homepage_survey_list('アンケート', $sort_order, null, $limit, $offset_active);

    // 3. 全体公開用調査結果
    // ① 同様にページ総数を計算するために、上限なしで全件数を数える
    $all_result_surveys = get_homepage_survey_list('調査結果', $sort_order, null, PHP_INT_MAX, 0);
    $total_result = count($all_result_surveys);
    $total_pages_result = ceil($total_result / $limit);
    
    // ② 表示用データの取得で、第4引数に $limit, 第5引数に $offset_result を渡す
    $result_surveys = get_homepage_survey_list('調査結果', $sort_order, null, $limit, $offset_result);

} catch (Exception $e) {
    error_log("データ抽出エラー: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ホームページ - 村上製作所</title>

    <link rel="stylesheet" href="../css/question.css">
    <link rel="stylesheet" href="../css/footer.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
        
    <style>
        body { 
            font-family: 'Hiragino Kaku Gothic ProN', 'Segoe UI', Meiryo, sans-serif; 
            background-color: #1e2d5a;
            color: #ffffff; 
            margin: 0; 
            padding: 0; 
            writing-mode: horizontal-tb;
        }
        .container { 
            width: 100%; 
            max-width: 1024px; 
            margin: 0 auto; 
            padding: 20px; 
            box-sizing: border-box; 
        }
        .mincho {
            font-family: 'Hiragino Mincho ProN', Georgia, 'MS Mincho', serif;
        }
        #liveAlertBar { 
            background-color: #fffbeb; 
            border: 2px solid #f59e0b; 
            color: #b45309;
            padding: 10px; 
            margin-bottom: 20px; 
            font-size: 13px; 
            border-radius: 6px;
        }
        .top-hero-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            position: relative;
        }
        .brand-title-area {
            margin-top: 40px;
            width: auto;
            font-size: 120px; 
            font-weight: bold;
            display: flex;
            flex-direction: column;
            text-align: left;
            line-height: 1.1;
            white-space: nowrap;
        }
        .brand-title-area .brand-top {
            color: #ffffff;
        }
        .brand-title-area .brand-bottom {
            color: #1e2d5a;
            -webkit-text-stroke: 3px #ffffff; 
            text-shadow: 
                2px 2px 0 #ffffff,
               -2px 2px 0 #ffffff,
                2px -2px 0 #ffffff,
               -2px -2px 0 #ffffff;
        }
        .illustration-placeholder img {
            width: 100%;
            height: auto;
            object-fit: contain;
            display: block;
        }
        .hero-left-illustration {
            margin-top: 30px;
            width: 180px;
            height: 180px;
        }
        .auth-control-panel {
            margin-top: 50px;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
            width: 40%;
        }
        .auth-status-text {
            font-size: 12px;
            color: #ffffff;
            margin-bottom: 4px;
        }
        
        /* 💡 楕円ボタンのサイズと配置スタイルを一律固定化（サイズばらつき防止） */
        .oval-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 240px;
            height: 42px; 
            padding: 0 20px;
            font-size: 13px;
            font-weight: bold;
            text-decoration: none;
            text-align: center;
            border-radius: 50px; 
            box-sizing: border-box;
            border: 2px solid #ffffff; 
            color: #000000 !important; 
        }
        .btn-signup { background-color: #33ccff; } 
        .btn-signin { background-color: #33ccff; } 
        .btn-withdraw { background-color: #ff3333; } 
        /* 💡 サインアウトボタンの色合いを目立ちすぎない薄めのオレンジに変更 */
        .btn-signout { background-color: #ff9d66; } 
        .btn-create { background-color: #d2f9d2; } 
        .btn-profile { background-color: #e6ccff; } 
        
        .guide-section h2,
        .survey-section .section-title-area h3,
        .member-section h3 { 
            margin: 0; 
            font-size: 44px;
            font-weight: bold; 
            color: #ffffff; 
            line-height: 1.1;
        }
        .guide-section { 
            margin-bottom: 40px; 
            max-width: 100%; 
        }
        .guide-section .subtitle {
            color: #33ccff; 
            display: block;
            margin-top: 5px;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .guide-container {
            display: flex;
            justify-content: space-between;
            align-items: center; 
            gap: 20px;
        }
        .guide-steps-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 65%; 
        }
        .guide-step-item {
            background-color: rgba(255, 255, 255, 0.08);
            border-radius: 6px;
            padding: 12px 15px;
            font-size: 13px;
            line-height: 1.5;
        }
        .guide-step-item strong {
            color: #33ccff;
            margin-right: 5px;
        }
        .guide-illustration {
            width: 280px;
            height: 280px;
            margin-top: 0;
            flex-shrink: 0;
        }
        .survey-section { 
            margin-bottom: 40px; 
        }
        .survey-section .section-title-area .subtitle {
            color: #33ccff; 
            display: block;
            margin-top: 5px;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .survey-block-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
        }
        .survey-side-illustration {
            width: 320px;
            height: 320px;
            margin-top: 10px; 
            flex-shrink: 0;
        }
        .survey-scroll-box {
            width: 64%; 
            background-color: #ffffff; 
            border-radius: 8px;
            padding: 40px 20px 20px 20px; 
            box-sizing: border-box;
            max-height: 400px;
            overflow-y: auto; 
            position: relative;
            margin-left: auto; 
        }
        .sort-trigger-btn {
            position: absolute;
            top: 10px;
            right: 15px;
            background-color: #cccccc; 
            border: 1px solid #000000; 
            color: #000000; 
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
            border-radius: 3px;
            font-weight: bold;
            z-index: 10;
        }

        .survey-list { 
            display: flex; 
            flex-direction: column; 
            gap: 10px; 
            margin-top: 10px;
        }
        
        .survey-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background-color: #1e2d5a; 
            color: #ffffff;
            border-radius: 6px;
            padding: 12px 15px; 
        }
        
        .survey-info { 
            display: flex; 
            flex-direction: column; 
            gap: 4px; 
            width: 60%;
        }
        .survey-date { 
            font-size: 12px; 
            color: #ffffff; 
        }
        .survey-title { 
            font-size: 14px; 
            font-weight: bold; 
            margin: 0; 
            color: #ffffff; 
        }
        .survey-creator { 
            font-size: 11px; 
            color: rgba(255, 255, 255, 0.7); 
        }
        
        .survey-actions { 
            display: flex; 
            gap: 6px; 
        }

        .action-inline-btn {
            display: inline-block;
            padding: 6px 14px;
            font-size: 12px;
            border: 1px solid #000000; 
            color: #000000; 
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
        }
        .btn-extend { background-color: #33ccff; } 
        .btn-result-orange { background-color: #ff5500; } 
        .btn-result-red { background-color: #ff3333; color: #ffffff; } 
        .btn-edit-green { background-color: #d2f9d2; } 
        .btn-answer { background-color: #33ccff; } 

        .alert-time-text {
            color: #ff3333; 
            font-weight: bold;
        }
        .member-section { 
            margin-top: 40px;
            margin-bottom: 40px;
        }
        .member-section .subtitle {
            color: #33ccff; 
            display: block;
            margin-top: 5px;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .member-content-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center; 
            gap: 20px;
        }
        .member-table-area {
            display: flex;
            align-items: flex-start;
            gap: 20px;
        }
        .member-leader-label {
            color: #ff3333; 
            font-size: 18px;
            font-weight: bold;
            width: 50px;
            margin-top: 2px;
        }
        .member-columns {
            display: flex;
            gap: 40px;
            color: #ffffff; 
        }
        .member-col {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 18px;
        }
        .member-illustration {
            width: 380px;
            height: 380px;
            margin-top: 0;
            flex-shrink: 0;
        }
        .page-top-pink-btn { 
            position: fixed; 
            bottom: 25px; 
            right: 25px; 
            background: linear-gradient(135deg, #e6ccff, #ff4a8d); 
            color: #ffffff; 
            border: none; 
            width: 65px; 
            height: 65px; 
            border-radius: 50px; 
            font-size: 20px; 
            font-weight: bold; 
            cursor: pointer; 
            z-index: 150; 
            text-align: center; 
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            line-height: 0.6;
        }
        .sort-popup { 
            position: absolute; 
            top: 38px; 
            right: 15px; 
            background-color: #ffffff; 
            color: #000000; 
            border-radius: 8px; 
            box-shadow: 0 4px 16px rgba(0,0,0,0.35);
            z-index: 2000;
            display: none; 
            padding: 12px; 
            border: 1px solid #bbbbbb;
        }
        .sort-popup::before {
            content: "";
            position: absolute;
            bottom: 100%;
            right: 20px;
            border: 8px solid transparent;
            border-bottom-color: #ffffff;
        }
        .sort-popup.show-popup { 
            display: block; 
        }
        .sort-popup-close {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 18px;
            height: 18px;
            background-color: #eeeeee;
            color: #000000;
            border-radius: 50%; 
            font-size: 11px;
            line-height: 16px;
            text-align: center;
            cursor: pointer;
            border: 1px solid #999999;
        }
        .sort-option-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 10px;
        }
        .sort-option { 
            display: block; 
            width: 160px; 
            padding: 6px 10px; 
            font-size: 12px; 
            color: #000000; 
            text-align: left; 
            background: none; 
            border: none; 
            cursor: pointer; 
        }
        .sort-option:hover { 
            background-color: #f3f4f6; 
        }
        .withdraw-overlay { 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background-color: rgba(0,0,0,0.6); 
            z-index: 200; 
            display: <?php echo (isset($_GET['view']) && $_GET['view'] === 'withdraw') ? 'flex' : 'none'; ?>; 
            align-items: center; 
            justify-content: center; 
        }
        .withdraw-popup { 
            background-color: #ffffff; 
            color: #333333;
            padding: 20px; 
            border-radius: 8px; 
            width: 280px; 
            text-align: center; 
        }
        .withdraw-message { 
            font-size: 14px; 
            font-weight: bold; 
            margin-bottom: 15px; 
        }
        .withdraw-buttons { 
            display: flex; 
            gap: 8px; 
            justify-content: center; 
        }
        .withdraw-buttons .btn-back {
            background-color: #cccccc;
            color: #000000;
            padding: 6px 12px;
            text-decoration: none;
            font-size: 12px;
            border-radius: 4px;
        }
        .withdraw-buttons .btn-submit {
            background-color: #ff3333;
            color: #ffffff;
            border: none;
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 4px;
            cursor: pointer;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen" style="padding-top: 64px !important;">
    <?php include 'header.php'; ?>

    <div class="container flex-grow">
        
        <div id="liveAlertBar" style="display: none;">
            ✓ <span id="liveAlertText">メッセージ</span>
        </div>

        <section class="top-hero-section">
            <div>
                <div class="brand-title-area">
                    <div class="brand-top">村上</div>
                    <div class="brand-bottom">製作所</div>
                </div>
                
                <div class="illustration-placeholder hero-left-illustration">
                    <img src="../assets/PICTURE_HOME.png" alt="チェックボックスにチェックをつける人">
                </div>
            </div>

            <div class="auth-control-panel">
                <div class="auth-status-text">
                    <?php if ($is_logged_in): ?>
                        ログイン中: <strong><?php echo h($_SESSION['account_name'] ?? '会員ユーザー'); ?></strong> 様
                    <?php else: ?>
                        ゲストユーザー様
                    <?php endif; ?>
                </div>
                
                <?php if (!$is_logged_in): ?>
                    <a href="signup.php" class="oval-btn btn-signup">ユーザー登録 →</a>
                    <a href="signin.php" class="oval-btn btn-signin">サインイン →</a>
                <?php else: ?>
                    <a href="index.php?view=withdraw" class="oval-btn btn-withdraw">退会 →</a>
                    <a href="index.php?action=signout" class="oval-btn btn-signout">サインアウト →</a>
                    <a href="survey_form.php" class="oval-btn btn-create">アンケートフォーム作成 →</a>
                    <a href="profile.php" class="oval-btn btn-profile">ユーザ情報の変更 →</a>
                <?php endif; ?>
            </div>
        </section>

        <section class="guide-section">
            <h2>GUIDE</h2>
            <span class="subtitle mincho">ご利用方法</span>
            
            <div class="guide-container">
                <div class="guide-steps-list">
                    <div class="guide-step-item"><strong>1.</strong> 起案者は事前に本システムへログインを完了した上で、調査目的の定義、設問数および選択肢の内部フォーマットの設計を厳密に行わなければなりません。</div>
                    <div class="guide-step-item"><strong>2.</strong> 準備された調査要件に基づき、「アンケートフォーム作成」機能を使用してシステムへの登録処理を執り行います。</div>
                    <div class="guide-step-item"><strong>3.</strong> 公示されたアンケート案件は、システムによって設定された有効期限（end_at）に至るまで自動的にステータスが監視されます。</div>
                    <div class="guide-step-item"><strong>4.</strong> 各案件カードに配置された「回答する」リンクを押下すると、専用の応答データ入力フォームが展開されます。</div>
                    <div class="guide-step-item"><strong>5.</strong> データベースへ正常に格納され蓄積された応答レコードおよびデータログは、システム内部の集計モジュールによってリアルタイムに電算処理されます。</div>
                </div>

                <div class="illustration-placeholder guide-illustration">
                    <img src="../assets/PICTURE_GUIDE.png" alt="資料を説明する男性">
                </div>
            </div>
        </section>

        <?php if ($is_logged_in): ?>
            <section class="survey-section">
                <div class="section-title-area">
                    <h3>MY SURVEY</h3>
                    <span class="subtitle mincho">作成したアンケート</span>
                </div>
                
                <div class="survey-block-container">
                    <div class="illustration-placeholder survey-side-illustration">
                        <img src="../assets/PICTURE_MYSURVEY_1.png" alt="会議をするメンバー">
                    </div>

                    <div class="survey-scroll-box">
                        <button type="button" class="sort-trigger-btn">⇄ 並べ替え</button>
                        
                        <div class="sort-popup">
                            <div class="sort-popup-close">×</div>
                            <div class="sort-option-list">
                                <button class="sort-option" data-sort-type="start">新着順</button>
                                <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                                <button class="sort-option" data-sort-type="responses">回答数が多い順</button>
                            </div>
                        </div>

                        <div class="survey-list">
                            <?php if (empty($created_surveys)): ?>
                                <div class="survey-row" style="background-color:#f3f4f6; color:#333;"><span style="font-size:12px;">作成したアンケートはありません。</span></div>
                            <?php else: ?>
                                <?php foreach ($created_surveys as $survey): ?>
                                    <div class="survey-row" id="survey-card-<?php echo h($survey['survey_id']); ?>">
                                        <div class="survey-info">
                                            <div class="survey-date">
                                                締め切り: 
                                                <span id="date-box-<?php echo h($survey['survey_id']); ?>">
                                                    <?php echo h(date('Y.m.d H:i', strtotime($survey['deadline'] ?? ''))); ?>
                                                </span>
                                                (回答: <?php echo (int)($survey['response_count'] ?? 0); ?>件)
                                            </div>
                                            <h4 class="survey-title">「<?php echo h($survey['title']); ?>〜」</h4>
                                        </div>
                                        <div class="survey-actions">
                                            <button type="button" class="action-inline-btn btn-extend js-extend-btn" 
                                                    data-survey-id="<?php echo h($survey['survey_id']); ?>"
                                                    data-survey-title="<?php echo h($survey['title']); ?>">延長</button>
                                            <a href="result.php?id=<?php echo h($survey['survey_id']); ?>" class="action-inline-btn btn-result-orange">結果</a>
                                            <a href="survey_form.php?id=<?php echo h($survey['survey_id']); ?>" class="action-inline-btn btn-edit-green">編集</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="survey-section">
                <div class="section-title-area">
                    <h3>MY SURVEY</h3>
                    <span class="subtitle mincho">回答したアンケート</span>
                </div>
                
                <div class="survey-block-container">
                    <div class="illustration-placeholder survey-side-illustration">
                        <img src="../assets/PICTURE_MYSURVEY_2.png" alt="PC作業をする女性とグラフ">
                    </div>

                    <div class="survey-scroll-box">
                        <button type="button" class="sort-trigger-btn">⇄ 並べ替え</button>
                        
                        <div class="sort-popup">
                            <div class="sort-popup-close">×</div>
                            <div class="sort-option-list">
                                <button class="sort-option" data-sort-type="start">新着順</button>
                                <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                            </div>
                        </div>

                        <div class="survey-list">
                            <?php if (empty($answered_surveys)): ?>
                                <div class="survey-row" style="background-color:#f3f4f6; color:#333;"><span style="font-size:12px;">過去に回答したアンケートはありません。</span></div>
                            <?php else: ?>
                                <?php foreach ($answered_surveys as $survey): ?>
                                    <div class="survey-row">
                                        <div class="survey-info">
                                            <div class="survey-date">完了日: <?php echo h(date('Y.m.d', strtotime($survey['deadline'] ?? ''))); ?></div>
                                            <h4 class="survey-title">「<?php echo h($survey['title']); ?>〜」</h4>
                                            <div class="survey-creator">作成: <?php echo h($survey['creator'] ?? '不明'); ?></div>
                                        </div>
                                        <div class="survey-actions">
                                            <a href="result.php?id=<?php echo h($survey['survey_id']); ?>" class="action-inline-btn btn-result-orange">結果</a>
                                            <a href="question.php?id=<?php echo h($survey['question_key']); ?>&mode=edit" class="action-inline-btn btn-edit-green">編集</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section class="survey-section">
            <div class="section-title-area">
                <h3>SURVEY</h3>
                <span class="subtitle mincho">アンケート</span>
            </div>
            
            <div class="survey-block-container">
                <div class="illustration-placeholder survey-side-illustration">
                    <img src="../assets/PICTURE_SURVEY.png" alt="話し合いをしている二人の男性">
                </div>

                <div class="survey-scroll-box">
                    <button type="button" class="sort-trigger-btn">⇄ 並べ替え</button>
                    
                    <div class="sort-popup">
                        <div class="sort-popup-close">×</div>
                        <div class="sort-option-list">
                            <button class="sort-option" data-sort-type="start">新着順</button>
                            <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                            <button class="sort-option" data-sort-type="responses">回答数が多い順</button>
                        </div>
                    </div>

                    <div class="survey-list">
                        <?php if (empty($active_surveys)): ?>
                            <div class="survey-row" style="background-color:#f3f4f6; color:#333;"><span style="font-size:12px;">現在、受付中のアンケートはありません。</span></div>
                        <?php else: ?>
                            <?php foreach ($active_surveys as $survey): ?>
                                <?php 
                                    $required_time = isset($survey['duration']) ? (int)$survey['duration'] : 0; 
                                ?>
                                <div class="survey-row">
                                    <div class="survey-info">
                                        <div class="survey-date">
                                            期限: 
                                            <span id="public-date-box-<?php echo h($survey['survey_id']); ?>">
                                                <?php echo h(date('Y.m.d H:i', strtotime($survey['deadline'] ?? ''))); ?>
                                            </span>
                                            <?php if ($required_time > 0): ?>
                                                <span class="alert-time-text"> (目安時間: <?php echo h($required_time); ?>分)</span>
                                            <?php endif; ?>
                                        </div>
                                        <h4 class="survey-title">「<?php echo h($survey['title']); ?>〜」</h4>
                                        <div class="survey-creator">作成: <?php echo h($survey['creator'] ?? '不明'); ?></div>
                                    </div>
                                    <div class="survey-actions">
                                        <a href="question.php?id=<?php echo h($survey['survey_id']); ?>" class="action-inline-btn btn-answer">回答(○月○日~)</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($total_pages_active > 1): ?>
                <ul class="pagination" style="display:flex; justify-content:center; list-style:none; gap:6px; margin-top:15px; padding:0;">
                    <?php for ($i = 1; $i <= $total_pages_active; $i++): ?>
                        <li>
                            <a href="index.php?p_act=<?php echo $i; ?>&p_res=<?php echo $page_result; ?>&sort=<?php echo h($sort_type); ?>" style="color:#fff; text-decoration:none; padding:4px 8px; background:rgba(255,255,255,0.1); border-radius:4px; font-size:12px;"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="survey-section">
            <div class="section-title-area">
                <h3>RESULTS</h3>
                <span class="subtitle mincho">調査結果</span>
            </div>
            
            <div class="survey-block-container">
                <div class="illustration-placeholder survey-side-illustration">
                    <img src="../assets/PICTURE_RESULTS.png" alt="グラフをもとに発表する男性">
                </div>

                <div class="survey-scroll-box">
                    <button type="button" class="sort-trigger-btn">⇄ 並べ替え</button>
                    
                    <div class="sort-popup">
                        <div class="sort-popup-close">×</div>
                        <div class="sort-option-list">
                            <button class="sort-option" data-sort-type="start">新着順</button>
                            <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                            <button class="sort-option" data-sort-type="responses">回答数が多い順</button>
                        </div>
                    </div>

                    <div class="survey-list">
                        <?php if (empty($result_surveys)): ?>
                            <div class="survey-row" style="background-color:#f3f4f6; color:#333;"><span style="font-size:12px;">過去ログデータはありません。</span></div>
                        <?php else: ?>
                            <?php foreach ($result_surveys as $survey): ?>
                                <div class="survey-row">
                                    <div class="survey-info">
                                        <div class="survey-date">終了日: <?php echo h(date('Y.m.d', strtotime($survey['deadline'] ?? ''))); ?></div>
                                        <h4 class="survey-title">「<?php echo h($survey['title']); ?>〜」</h4>
                                        <div class="survey-creator">作成: <?php echo h($survey['creator'] ?? '不明'); ?></div>
                                    </div>
                                    <div class="survey-actions">
                                        <a href="result.php?id=<?php echo h($survey['survey_id']); ?>" class="action-inline-btn btn-result-red">結果(○月○日~)</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($total_pages_result > 1): ?>
                <ul class="pagination" style="display:flex; justify-content:center; list-style:none; gap:6px; margin-top:15px; padding:0;">
                    <?php for ($i = 1; $i <= $total_pages_result; $i++): ?>
                        <li>
                            <a href="index.php?p_act=<?php echo $page_active; ?>&p_res=<?php echo $i; ?>&sort=<?php echo h($sort_type); ?>" style="color:#fff; text-decoration:none; padding:4px 8px; background:rgba(255,255,255,0.1); border-radius:4px; font-size:12px;"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="member-section">
            <h3>MEMBER</h3>
            <span class="subtitle mincho">メンバー</span>
            
            <div class="member-content-wrapper">
                <div class="member-table-area">
                    <div class="member-leader-label mincho">社長</div>
                    
                    <div class="member-columns mincho">
                        <div class="member-col">
                            <span>村上 悠</span>
                            <span>吉守 祥</span>
                            <span>湯場崎 啓心</span>
                            <span>折本 敢太</span>
                            <span>酒匂 莉乃</span>
                        </div>
                        <div class="member-col">
                            <span>中城 大志</span>
                            <span>野元 悠惺</span>
                            <span>前田 凱南</span>
                            <span>丸山 夕渚</span>
                            <span>用貝 有基</span>
                        </div>
                    </div>
                </div>

                <div class="illustration-placeholder member-illustration">
                    <img src="../assets/PICTURE_MEMBER.png" alt="男女複数人のメンバーイラスト">
                </div>
            </div>
        </section>
    </div>

    <div class="withdraw-overlay" id="withdrawOverlay">
        <div class="withdraw-popup">
            <p class="withdraw-message">本当に退会しますか？</p>
            <form action="index.php" method="POST" class="withdraw-buttons">
                <input type="hidden" name="action" value="delete_account">
                <a href="index.php" class="btn-back">戻る</a>
                <button type="submit" class="btn-submit">退会</button>
            </form>
        </div>
    </div>

    <button type="button" class="page-top-pink-btn">▲<br> <br>TOP</button>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const liveAlertBar = document.getElementById('liveAlertBar');
            const liveAlertText = document.getElementById('liveAlertText');

            function showLiveAlert(message) {
                if (liveAlertBar && liveAlertText) {
                    liveAlertText.textContent = message;
                    liveAlertBar.style.display = 'block';
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    setTimeout(() => { liveAlertBar.style.display = 'none'; }, 5000);
                }
            }

            const extendButtons = document.querySelectorAll('.js-extend-btn');
            extendButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const surveyId = this.dataset.surveyId;
                    const surveyTitle = this.dataset.surveyTitle;
                    const activeToken = typeof csrfToken !== 'undefined' ? csrfToken : '';
                    
                    fetch('index.php?api=extend', {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': activeToken
                        },
                        body: JSON.stringify({  survey_id: surveyId, new_end_at: '2026-06-30T23:59'})
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showLiveAlert(data.message || `「${surveyTitle}」の回答期限を延長しました。`);
                            const dateBox = document.getElementById(`date-box-${surveyId}`);
                            if (dateBox) dateBox.textContent = data.new_time;
                            const publicDateBox = document.getElementById(`public-date-box-${surveyId}`);
                            if (publicDateBox) publicDateBox.textContent = data.new_time;
                        } else {
                            alert(data.message || '延長処理に失敗しました。');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('通信エラーが発生しました。');
                    });
                });
            });

            const sortTriggerBtns = document.querySelectorAll('.sort-trigger-btn');
            sortTriggerBtns.forEach(button => {
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    const scrollBox = button.closest('.survey-scroll-box');
                    const targetPopup = scrollBox ? scrollBox.querySelector('.sort-popup') : null;
                    
                    document.querySelectorAll('.sort-popup').forEach(p => {
                        if (p !== targetPopup) p.classList.remove('show-popup');
                    });

                    if (targetPopup) {
                        targetPopup.classList.toggle('show-popup');
                    }
                });
            });

            const closeBtns = document.querySelectorAll('.sort-popup-close');
            closeBtns.forEach(btn => {
                btn.addEventListener('click', (event) => {
                    event.stopPropagation();
                    const popup = btn.closest('.sort-popup');
                    if (popup) popup.classList.remove('show-popup');
                });
            });

            const sortOptions = document.querySelectorAll('.sort-option');
            sortOptions.forEach(option => {
                option.addEventListener('click', function(event) {
                    event.stopPropagation();
                    const sortType = this.dataset.sortType;
                    const urlParams = new URLSearchParams(window.location.search);
                    urlParams.set('sort', sortType);
                    window.location.href = 'index.php?' + urlParams.toString();
                });
            });

            document.addEventListener('click', () => {
                document.querySelectorAll('.sort-popup').forEach(p => p.classList.remove('show-popup'));
            });

            const scrollTopButton = document.querySelector('.page-top-pink-btn');
            if (scrollTopButton) {
                scrollTopButton.addEventListener('click', (e) => {
                    e.preventDefault();
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                });
            }
        });
    </script>

    <style>
        footer {
            display: block !important;
            width: 100% !important;
            background-color: #1e3a8a !important; 
            color: #ffffff !important;           
            text-align: center !important;       
            padding: 24px 0 !important;          
            margin-top: 48px !important;         
            position: relative !important;
            z-index: 999 !important;
        }
        footer * {
            color: #ffffff !important;
            text-align: center !important;
            margin-left: auto !important;
            margin-right: auto !important;
        }
    </style>
    
    <div class="h-8"></div>
    <?php require_once "footer.php"; ?>
</body>
</html>