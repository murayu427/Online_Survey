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


// //!!!!!模擬ログイン(消すやつ)!!!!!
// if (!isset($_SESSION['user_id'])){
//     $_SESSION['user_id'] = 1;
//     $_SESSION['account_name'] = 'a';
// }

// 安全にHTMLエスケープを行うための共通関数
if (!function_exists('h')) {
    function h($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// 補助関数の定義
if (!function_exists('login_has_session_cookie_configured')) {
    function login_has_session_cookie_configured() {
        return isset($_COOKIE[session_name()]);
    }
}

$is_logged_in = (isset($_SESSION) && isset($_SESSION['user_id'])); 
$current_user_id = $is_logged_in ? (int)$_SESSION['user_id'] : null;

// サインアウト処理のハンドリング
// if (isset($_GET['action']) && $_GET['action'] === 'signout') {
//     $_SESSION = array(); 
//     if (login_has_session_cookie_configured()) {
//         setcookie(session_name(), '', time() - 42000, '/');
//     }
//     del_sess();
//     header("Location: index.php");
//     exit;
// }

// // 退会処理のハンドリング
// if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
//     if ($is_logged_in && $current_user_id !== null) {
//         $delete_success = delete_user($current_user_id);
//         if ($delete_success) {
//             $_SESSION = array();
//             del_sess();
//             header("Location: index.php");
//             exit;
//         } else {
//             $alert_message = "退会処理に失敗しました。システム管理者に連絡してください。";
//         }
//     }
// }

// 新しくホームページに戻った時は必ず新着順にする
$has_any_sort_param = isset($_GET['s_cre']) || isset($_GET['s_ans']) || isset($_GET['s_act']) || isset($_GET['s_res']);
if (!$has_any_sort_param) {
    $sort_cre = 'start';
    $sort_ans = 'start';
    $sort_act = 'start';
    $sort_res = 'start';
} else {
    $sort_cre = isset($_GET['s_cre']) ? $_GET['s_cre'] : 'start';
    $sort_ans = isset($_GET['s_ans']) ? $_GET['s_ans'] : 'start';
    $sort_act = isset($_GET['s_act']) ? $_GET['s_act'] : 'start';
    $sort_res = isset($_GET['s_res']) ? $_GET['s_res'] : 'start';
}

function get_sort_order_text($type) {
    if ($type === 'deadline') return '開始期限'; 
    if ($type === 'responses') return '回答数';
    return '新着';
}

$order_cre = get_sort_order_text($sort_cre);
$order_ans = get_sort_order_text($sort_ans);
$order_act = get_sort_order_text($sort_act);
$order_res = get_sort_order_text($sort_res);

$scroll_pos = isset($_GET['scroll']) ? (float)$_GET['scroll'] : 0;

// 延長APIロジック
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api']) && $_GET['api'] === 'extend') {
    header('Content-Type: application/json; charset=utf-8');
    $raw_input = file_get_contents('php://input');
    $input_data = json_decode($raw_input, true);
    
    if (!$is_logged_in || !isset($input_data['survey_id']) || !isset($input_data['new_end_at'])) {
        echo json_encode(['success' => false, 'message' => '認証エラーまたはパラメータ不足です。']);
        exit;
    }
    
    $target_survey_id = (int)$input_data['survey_id'];
    $new_end_at       = $input_data['new_end_at'];
    
    try {
        $updated_time = extend_survey_deadline($target_survey_id, $current_user_id, $new_end_at);
        if ($updated_time) {
            echo json_encode([
                'success'  => true, 
                'new_time' => $updated_time,
                'message'  => "回答期限を {$updated_time} まで延長しました。"
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '期限の更新に失敗しました。']);
        }
        exit;
    } catch (Exception $e) {
        error_log("期限延長APIエラー: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'システムエラーが発生しました。']);
        exit;
    }
}

// 100件ごとのページ分割制御ロジック
$page_created  = isset($_GET['p_cre']) ? max(1, (int)$_GET['p_cre']) : 1;
$page_answered = isset($_GET['p_ans']) ? max(1, (int)$_GET['p_ans']) : 1;
$page_active   = isset($_GET['p_act']) ? max(1, (int)$_GET['p_act']) : 1;
$page_result   = isset($_GET['p_res']) ? max(1, (int)$_GET['p_res']) : 1;
$limit = 100; 

$offset_created  = ($page_created - 1) * $limit;
$offset_answered = ($page_answered - 1) * $limit;
$offset_active   = ($page_active - 1) * $limit;
$offset_result   = ($page_result - 1) * $limit;

$created_surveys = [];
$answered_surveys = [];
$active_surveys = [];
$result_surveys = [];

$total_created = 0;
$total_answered = 0;
$total_active = 0;
$total_result = 0;

$total_pages_created  = 1;
$total_pages_answered = 1;
$total_pages_active   = 1;
$total_pages_result   = 1;

try {
    // 1. ログインユーザー専用データ（MY SURVEY）
    if ($is_logged_in && $current_user_id !== null) {
        // 作成したアンケート
        $all_created = get_homepage_survey_list('作成したアンケート', $order_cre, $current_user_id);
        $total_created = count($all_created);
        $total_pages_created = (int)ceil($total_created / $limit);
        if ($total_pages_created < 1) $total_pages_created = 1;
        $created_surveys = array_slice($all_created, $offset_created, $limit);

        // 回答したアンケート
        $all_answered = get_homepage_survey_list('回答したアンケート', $order_ans, $current_user_id);
        $total_answered = count($all_answered);
        $total_pages_answered = (int)ceil($total_answered / $limit);
        if ($total_pages_answered < 1) $total_pages_answered = 1;
        $answered_surveys = array_slice($all_answered, $offset_answered, $limit);
    }
    
    // 2. 全体公開用アンケート（SURVEY）
    $all_active_surveys = get_homepage_survey_list('アンケート', $order_act, null);
    $total_active = count($all_active_surveys);
    $total_pages_active = (int)ceil($total_active / $limit);
    if ($total_pages_active < 1) $total_pages_active = 1;
    $active_surveys = array_slice($all_active_surveys, $offset_active, $limit);

    // 3. 全体公開用調査結果（RESULTS）
    $all_result_surveys = get_homepage_survey_list('調査結果', $order_res, null);
    $total_result = count($all_result_surveys);
    $total_pages_result = (int)ceil($total_result / $limit);
    if ($total_pages_result < 1) $total_pages_result = 1;
    $result_surveys = array_slice($all_result_surveys, $offset_result, $limit);

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

    <link rel="stylesheet" href="css/question.css">
    <link rel="stylesheet" href="css/footer.css">
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
        .btn-signin { background-color: #b7e9f9; } 
        .btn-withdraw { background-color: #ff3333; } 
        .btn-signout { background-color: #fb8b85; } 
        .btn-create { background-color: #b0f5b0; } 
        .btn-profile { background-color: #cfa3f8; } 
        
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
            padding: 0; 
            box-sizing: border-box;
            height: 400px;
            max-height: 400px;
            display: flex;
            flex-direction: column;
            position: relative;
            margin-left: auto; 
            overflow: hidden;
        }
        
        .scroll-box-header {
            padding: 15px 20px 5px 20px;
            flex-shrink: 0;
            background-color: #ffffff;
            position: relative;
            z-index: 20;
        }

        .scroll-box-content {
            padding: 0 20px 20px 20px;
            overflow-y: auto;
            flex-grow: 1;
        }

        .sort-trigger-btn {
            position: absolute;
            top: 12px;
            right: 15px;
            background-color: #cccccc; 
            border: 1px solid #000000; 
            color: #000000; 
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
            border-radius: 3px;
            font-weight: bold;
            z-index: 30;
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
        .btn-extend { background-color: #b7e9f9; } 
        .btn-result-orange { background-color: #fb8b85; } 
        .btn-result-red { background-color: #fb8b85; color: #000000; } 
        .btn-edit-green { background-color: #d2f9d2; } 
        .btn-answer { background-color: #b7e9f9; } 

        .oval-btn.lift-button,
        .action-inline-btn.lift-button,
        .sort-trigger-btn.lift-button,
        .sort-option.lift-button,
        .page-top-pink-btn.lift-button,
        .withdraw-buttons .btn-back.lift-button,
        .withdraw-buttons .btn-submit.lift-button {
            transition: transform 0.18s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.18s ease, filter 0.18s ease;
            transform: translateY(0);
        }

        .oval-btn.lift-button:hover,
        .action-inline-btn.lift-button:hover,
        .sort-trigger-btn.lift-button:hover,
        .sort-option.lift-button:hover,
        .page-top-pink-btn.lift-button:hover,
        .withdraw-buttons .btn-back.lift-button:hover,
        .withdraw-buttons .btn-submit.lift-button:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.22);
            filter: brightness(1.05);
        }

        .oval-btn.lift-button:active,
        .action-inline-btn.lift-button:active,
        .sort-trigger-btn.lift-button:active,
        .sort-option.lift-button:active,
        .page-top-pink-btn.lift-button:active,
        .withdraw-buttons .btn-back.lift-button:active,
        .withdraw-buttons .btn-submit.lift-button:active {
            transform: translateY(-1px) scale(0.99);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.16);
        }

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
            z-index: 1000; 
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
            top: 40px; 
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
        .section-meta-info {
            font-size: 11px;
            color: #888888;
            margin-bottom: 8px;
            border-bottom: 1px dashed #e5e7eb;
            padding-bottom: 4px;
            width: 80%;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen" style="padding-top: 64px !important;">
    <?php include 'header.php'; ?>

    <div class="container flex-grow">
        
        <div id="liveAlertBar" style="display: <?php echo !empty($alert_message) ? 'block' : 'none'; ?>;">
            ✓ <span id="liveAlertText"><?php echo h($alert_message); ?></span>
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
                        ログイン中: <strong><?php echo h($_SESSION['username'] ?? '会員ユーザー'); ?></strong> 様
                    <?php else: ?>
                        ゲストユーザー様
                    <?php endif; ?>
                </div>
                
                <?php if (!$is_logged_in): ?>
                    <a href="signup.php" class="oval-btn btn-signup lift-button">ユーザー登録 →</a>
                    <a href="signin.php" class="oval-btn btn-signin lift-button">サインイン →</a>
                <?php else: ?>
                    <a href="unsubscription.php" class="oval-btn btn-withdraw lift-button">退会 →</a>
                    <form action="signout.php" method="post" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token'] ?? ''); ?>">
                        <button type="submit" class="oval-btn btn-signout lift-button">サインアウト →</button>
                    </form>
                    <a href="survey_form.php" class="oval-btn btn-create lift-button">アンケートフォーム作成 →</a>
                    <a href="profile.php" class="oval-btn btn-profile lift-button">ユーザ情報の変更 →</a>
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
                        <div class="scroll-box-header">
                            <button type="button" class="sort-trigger-btn">⇄ 並べ替え</button>
                            <div class="sort-popup">
                                <div class="sort-popup-close">×</div>
                                <div class="sort-option-list">
                                    <button class="sort-option" data-sort-param="s_cre" data-page-param="p_cre" data-sort-type="start">新着順</button>
                                    <button class="sort-option" data-sort-param="s_cre" data-page-param="p_cre" data-sort-type="responses">回答数が多い順</button>
                                </div>
                            </div>
                            <?php 
                                $start_num_cre = $total_created > 0 ? $offset_created + 1 : 0;
                                $end_num_cre = min($offset_created + $limit, $total_created);
                            ?>
                            <div class="section-meta-info">
                                並び順: <?php echo h($order_cre); ?>順 ｜ 合計: <?php echo $total_created; ?>件 （<?php echo $start_num_cre; ?>-<?php echo $end_num_cre; ?>件表示中）
                            </div>
                        </div>

                        <div class="scroll-box-content">
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
                                                <button type="button" class="action-inline-btn btn-extend js-extend-btn lift-button" 
                                                        data-survey-id="<?php echo h($survey['survey_id']); ?>"
                                                        data-survey-title="<?php echo h($survey['title']); ?>">延長</button>
                                                <a href="result.php?id=<?php echo h($survey['question_key']); ?>" class="action-inline-btn btn-result-orange lift-button">結果</a>
                                                <a href="survey_form.php?id=<?php echo h($survey['survey_id']); ?>" class="action-inline-btn btn-edit-green lift-button">編集</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($total_pages_created > 1): ?>
                    <ul class="pagination" style="display:flex; justify-content:center; list-style:none; gap:6px; margin-top:15px; padding:0;">
                        <?php for ($i = 1; $i <= $total_pages_created; $i++): ?>
                            <li>
                                <a href="index.php?p_cre=<?php echo $i; ?>&p_ans=<?php echo $page_answered; ?>&p_act=<?php echo $page_active; ?>&p_res=<?php echo $page_result; ?>&s_cre=<?php echo h($sort_cre); ?>&s_ans=<?php echo h($sort_ans); ?>&s_act=<?php echo h($sort_act); ?>&s_res=<?php echo h($sort_res); ?>" class="js-page-link" style="color:#fff; text-decoration:none; padding:4px 8px; background:<?php echo ($i === $page_created) ? '#33ccff' : 'rgba(255,255,255,0.1)'; ?>; border-radius:4px; font-size:12px;"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                <?php endif; ?>
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
                        <div class="scroll-box-header">
                            <button type="button" class="sort-trigger-btn">⇄ 並べ替え</button>
                            <div class="sort-popup">
                                <div class="sort-popup-close">×</div>
                                <div class="sort-option-list">
                                    <button class="sort-option" data-sort-param="s_ans" data-page-param="p_ans" data-sort-type="start">新着順</button>
                                    <button class="sort-option" data-sort-param="s_ans" data-page-param="p_ans" data-sort-type="responses">回答数が多い順</button>
                                </div>
                            </div>
                            <?php 
                                $start_num_ans = $total_answered > 0 ? $offset_answered + 1 : 0;
                                $end_num_ans = min($offset_answered + $limit, $total_answered);
                            ?>
                            <div class="section-meta-info">
                                並び順: <?php echo h($order_ans); ?>順 ｜ 合計: <?php echo $total_answered; ?>件 （<?php echo $start_num_ans; ?>-<?php echo $end_num_ans; ?>件表示中）
                            </div>
                        </div>

                        <div class="scroll-box-content">
                            <div class="survey-list">
                                <?php if (empty($answered_surveys)): ?>
                                    <div class="survey-row" style="background-color:#f3f4f6; color:#333;"><span style="font-size:12px;">過去に回答したアンケートはありません。</span></div>
                                <?php else: ?>
                                    <?php foreach ($answered_surveys as $survey): ?>
                                        <div class="survey-row">
                                            <div class="survey-info">
                                                <div class="survey-date">終了日: <?php echo h(date('Y.m.d', strtotime($survey['deadline'] ?? ''))); ?></div>
                                                <h4 class="survey-title">「<?php echo h($survey['title']); ?>〜」</h4>
                                                <div class="survey-creator">作成者: <?php echo h($survey['creator'] ?? '不明'); ?></div>
                                            </div>
                                            <div class="survey-actions">
                                                <a href="result.php?id=<?php echo h($survey['question_key']); ?>" class="action-inline-btn btn-result-orange lift-button">結果</a>
                                                <a href="question.php?id=<?php echo h($survey['question_key']); ?>&mode=edit" class="action-inline-btn btn-edit-green lift-button">編集</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($total_pages_answered > 1): ?>
                    <ul class="pagination" style="display:flex; justify-content:center; list-style:none; gap:6px; margin-top:15px; padding:0;">
                        <?php for ($i = 1; $i <= $total_pages_answered; $i++): ?>
                            <li>
                                <a href="index.php?p_cre=<?php echo $page_created; ?>&p_ans=<?php echo $i; ?>&p_act=<?php echo $page_active; ?>&p_res=<?php echo $page_result; ?>&s_cre=<?php echo h($sort_cre); ?>&s_ans=<?php echo h($sort_ans); ?>&s_act=<?php echo h($sort_act); ?>&s_res=<?php echo h($sort_res); ?>" class="js-page-link" style="color:#fff; text-decoration:none; padding:4px 8px; background:<?php echo ($i === $page_answered) ? '#33ccff' : 'rgba(255,255,255,0.1)'; ?>; border-radius:4px; font-size:12px;"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                <?php endif; ?>
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
                    <div class="scroll-box-header">
                        <button type="button" class="sort-trigger-btn">⇄ 並べ替え</button>
                        <div class="sort-popup">
                            <div class="sort-popup-close">×</div>
                            <div class="sort-option-list">
                                <button class="sort-option" data-sort-param="s_act" data-page-param="p_act" data-sort-type="start">新着順</button>
                                <button class="sort-option" data-sort-param="s_act" data-page-param="p_act" data-sort-type="responses">回答数が多い順</button>
                            </div>
                        </div>
                        <?php 
                            $start_num = $total_active > 0 ? $offset_active + 1 : 0;
                            $end_num = min($offset_active + $limit, $total_active);
                        ?>
                        <div class="section-meta-info">
                            並び順: <?php echo h($order_act); ?>順 ｜ 合計: <?php echo $total_active; ?>件 （<?php echo $start_num; ?>-<?php echo $end_num; ?>件表示中）
                        </div>
                    </div>

                    <div class="scroll-box-content">
                        <div class="survey-list">
                            <?php if (empty($active_surveys)): ?>
                                <div class="survey-row" style="background-color:#f3f4f6; color:#333;"><span style="font-size:12px;">現在、受付中のアンケートはありません。</span></div>
                            <?php else: ?>
                                <?php foreach ($active_surveys as $survey): ?>
                                    <?php 
                                        $required_time = isset($survey['duration']) ? (int)$survey['duration'] : 0; 
                                        $start_date_str = isset($survey['created_at']) ? date('m月d日', strtotime($survey['created_at'])) : date('m月d日', strtotime($survey['start_date'] ?? 'now'));
                                    ?>
                                    <div class="survey-row">
                                        <div class="survey-info">
                                            <div class="survey-date">
                                                終了時刻: 
                                                <span id="public-date-box-<?php echo h($survey['survey_id']); ?>">
                                                    <?php echo h(date('Y.m.d H:i', strtotime($survey['deadline'] ?? ''))); ?>
                                                </span>
                                                <?php if ($required_time > 0): ?>
                                                    <span class="alert-time-text"> (目安時間: <?php echo h($required_time); ?>分)</span>
                                                <?php endif; ?>
                                            </div>
                                            <h4 class="survey-title">「<?php echo h($survey['title']); ?>〜」</h4>
                                            <div class="survey-creator">作成者: <?php echo h($survey['creator'] ?? '不明'); ?></div>
                                        </div>
                                        <div class="survey-actions">
                                            <a href="result.php?id=<?php echo h($survey['question_key']); ?>" class="action-inline-btn btn-result-orange lift-button">結果</a>
                                            <a href="question.php?id=<?php echo h($survey['question_key']); ?>" class="action-inline-btn btn-answer lift-button">回答(<?php echo h($start_date_str); ?>~)</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($total_pages_active > 1): ?>
                <ul class="pagination" style="display:flex; justify-content:center; list-style:none; gap:6px; margin-top:15px; padding:0;">
                    <?php for ($i = 1; $i <= $total_pages_active; $i++): ?>
                        <li>
                            <a href="index.php?p_cre=<?php echo $page_created; ?>&p_ans=<?php echo $page_answered; ?>&p_act=<?php echo $i; ?>&p_res=<?php echo $page_result; ?>&s_cre=<?php echo h($sort_cre); ?>&s_ans=<?php echo h($sort_ans); ?>&s_act=<?php echo h($sort_act); ?>&s_res=<?php echo h($sort_res); ?>" class="js-page-link" style="color:#fff; text-decoration:none; padding:4px 8px; background:<?php echo ($i === $page_active) ? '#33ccff' : 'rgba(255,255,255,0.1)'; ?>; border-radius:4px; font-size:12px;"><?php echo $i; ?></a>
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
                    <div class="scroll-box-header">
                        <button type="button" class="sort-trigger-btn">⇄ 並べ替え</button>
                        <div class="sort-popup">
                            <div class="sort-popup-close">×</div>
                            <div class="sort-option-list">
                                <button class="sort-option" data-sort-param="s_res" data-page-param="p_res" data-sort-type="start">新着順</button>
                                <button class="sort-option" data-sort-param="s_res" data-page-param="p_res" data-sort-type="responses">回答数が多い順</button>
                            </div>
                        </div>
                        <?php 
                            $start_num_res = $total_result > 0 ? $offset_result + 1 : 0;
                            $end_num_res = min($offset_result + $limit, $total_result);
                        ?>
                        <div class="section-meta-info">
                            並び順: <?php echo h($order_res); ?>順 ｜ 合計: <?php echo $total_result; ?>件 （<?php echo $start_num_res; ?>-<?php echo $end_num_res; ?>件表示中）
                        </div>
                    </div>

                    <div class="scroll-box-content">
                        <div class="survey-list">
                            <?php if (empty($result_surveys)): ?>
                                <div class="survey-row" style="background-color:#f3f4f6; color:#333;"><span style="font-size:12px;">過去ログデータはありません。</span></div>
                            <?php else: ?>
                                <?php foreach ($result_surveys as $survey): ?>
                                    <?php 
                                        $deadline_str = isset($survey['deadline']) ? date('m月d日', strtotime($survey['deadline'])) : '○月○日';
                                    ?>
                                    <div class="survey-row">
                                        <div class="survey-info">
                                            <div class="survey-date">終了日: <?php echo h(date('Y.m.d', strtotime($survey['deadline'] ?? ''))); ?></div>
                                            <h4 class="survey-title">「<?php echo h($survey['title']); ?>〜」</h4>
                                            <div class="survey-creator">作成者: <?php echo h($survey['creator'] ?? '不明'); ?></div>
                                        </div>
                                        <div class="survey-actions">
                                            <a href="result.php?id=<?php echo h($survey['question_key']); ?>" class="action-inline-btn btn-result-red lift-button">結果(<?php echo h($deadline_str); ?>~)</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($total_pages_result > 1): ?>
                <ul class="pagination" style="display:flex; justify-content:center; list-style:none; gap:6px; margin-top:15px; padding:0;">
                    <?php for ($i = 1; $i <= $total_pages_result; $i++): ?>
                        <li>
                            <a href="index.php?p_cre=<?php echo $page_created; ?>&p_ans=<?php echo $page_answered; ?>&p_act=<?php echo $page_active; ?>&p_res=<?php echo $i; ?>&s_cre=<?php echo h($sort_cre); ?>&s_ans=<?php echo h($sort_ans); ?>&s_act=<?php echo h($sort_act); ?>&s_res=<?php echo h($sort_res); ?>" class="js-page-link" style="color:#fff; text-decoration:none; padding:4px 8px; background:<?php echo ($i === $page_result) ? '#33ccff' : 'rgba(255,255,255,0.1)'; ?>; border-radius:4px; font-size:12px;"><?php echo $i; ?></a>
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
                            <span>折元 敢太</span>
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

    <!-- <div class="withdraw-overlay" id="withdrawOverlay">
        <div class="withdraw-popup">
            <p class="withdraw-message">本当に退会しますか？</p>
            <form action="index.php" method="POST" class="withdraw-buttons">
                <input type="hidden" name="action" value="delete_account">
                <a href="index.php" class="btn-back">戻る</a>
                <button type="submit" class="btn-submit">退会</button>
            </form>
        </div>
    </div> -->

    <button type="button" class="page-top-pink-btn lift-button">▲<br> <br>TOP</button>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('scroll')) {
                window.scrollTo(0, parseFloat(urlParams.get('scroll')));
            }

            function appendScrollParam(url) {
                const currentScroll = window.scrollY || document.documentElement.scrollTop;
                const targetUrl = new URL(url, window.location.origin);
                targetUrl.searchParams.set('scroll', currentScroll);
                return targetUrl.pathname + targetUrl.search;
            }

            document.querySelectorAll('.js-page-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    window.location.href = appendScrollParam(this.href);
                });
            });

            const liveAlertBar = document.getElementById('liveAlertBar');
            const liveAlertText = document.getElementById('liveAlertText');

            function showLiveAlert(message) {
                if (liveAlertBar && liveAlertText) {
                    liveAlertText.textContent = message;
                    liveAlertBar.style.display = 'block';
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    setTimeout(() => { 
                        if (!message.includes('失敗')) {
                            liveAlertBar.style.display = 'none'; 
                        }
                    }, 5000);
                }
            }

            <?php if (!empty($alert_message)): ?>
                showLiveAlert(<?php echo json_encode($alert_message); ?>);
            <?php endif; ?>

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

            // 並び替え処理のハンドリング
            const sortOptions = document.querySelectorAll('.sort-option');
            sortOptions.forEach(option => {
                option.addEventListener('click', function(event) {
                    event.stopPropagation();
                    const sortParamName = this.dataset.sortParam; 
                    const sortType = this.dataset.sortType;      
                    const pageParamName = this.dataset.pageParam;
                    
                    const currentUrlParams = new URLSearchParams(window.location.search);
                    currentUrlParams.set(sortParamName, sortType);
                    
                    // 並べ替えたら、該当のリストは必ず「1ページ目」が表示されるようにリセット
                    if (pageParamName) {
                        currentUrlParams.set(pageParamName, '1');
                    }
                    
                    const currentScroll = window.scrollY || document.documentElement.scrollTop;
                    currentUrlParams.set('scroll', currentScroll);
                    
                    window.location.href = 'index.php?' + currentUrlParams.toString();
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