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

// パラメータを新関数の $sortOrder 用にマッピング
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
    $raw_input = file_get_contents('php://input');
    $input_data = json_decode($raw_input, true);
    
    if (!$is_logged_in || !isset($input_data['survey_id'])) {
        echo json_encode(['success' => false, 'message' => '認証エラーまたはパラメータ不足です。']);
        exit;
    }
    
    $target_survey_id = (int)$input_data['survey_id'];
    
    try {
        $pdo = isset($GLOBALS['db']) ? $GLOBALS['db'] : (function_exists('db_connect') ? db_connect() : null);
        if ($pdo) {
            $sql = "UPDATE surveys 
                    SET end_at = DATE_ADD(IF(end_at > NOW(), end_at, NOW()), INTERVAL 10 MINUTE) 
                    WHERE survey_id = :survey_id AND creator_id = :creator_id";
            
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([
                ':survey_id' => $target_survey_id,
                ':creator_id' => $current_user_id
            ]);
            
            if ($success && $stmt->rowCount() > 0) {
                $time_sql = "SELECT end_at FROM surveys WHERE survey_id = :survey_id";
                $time_stmt = $pdo->prepare($time_sql);
                $time_stmt->execute([':survey_id' => $target_survey_id]);
                $updated_survey = $time_stmt->fetch(PDO::FETCH_ASSOC);
                
                $new_time_formatted = date('Y.m.d H:i', strtotime($updated_survey['end_at']));
                
                echo json_encode([
                    'success' => true, 
                    'new_time' => $new_time_formatted,
                    'message' => '回答期限を10分間延長しました。'
                ]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'エラーが発生しました。']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
    if ($is_logged_in) {
        $created_surveys  = get_homepage_survey_list('作成したアンケート', $sort_order, $current_user_id);
        $answered_surveys = get_homepage_survey_list('回答したアンケート', $sort_order, $current_user_id);
    }
    
    $all_active_surveys = get_homepage_survey_list('アンケート', $sort_order, null);
    $all_result_surveys = get_homepage_survey_list('調査結果', $sort_order, null);

    $total_active = count($all_active_surveys);
    $total_pages_active = ceil($total_active / $limit);
    $active_surveys = array_slice($all_active_surveys, $offset_active, $limit);

    $total_result = count($all_result_surveys);
    $total_pages_result = ceil($total_result / $limit);
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
    <title>ホームページ - 村上製作所 アンケートシステム</title>
    <style>
        /* 設計書通りのネイビーを基調とした背景と白抜き文字を再現 */
        body { 
            font-family: 'Helvetica Neue', Arial, 'Hiragino Kaku Gothic ProN', sans-serif; 
            background-color: #1e2d5a; /* 設計書のメインネイビー */
            color: #ffffff; 
            margin: 0; 
            padding: 0; 
        }
        .container { 
            width: 100%; 
            max-width: 1024px; 
            margin: 0 auto; 
            padding: 20px; 
            box-sizing: border-box; 
        }
        
        /* 設計書内の四角い角丸白背景パネル */
        .white-panel {
            background-color: #ffffff;
            color: #333333;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
        }

        /* 設計書のシンプルな枠線付きボタン */
        .btn { 
            padding: 6px 16px; 
            font-size: 13px; 
            text-decoration: none; 
            color: #1e2d5a; 
            background-color: #ffffff; 
            border: 2px solid #1e2d5a; 
            border-radius: 6px;
            cursor: pointer; 
            display: inline-block; 
            text-align: center; 
            box-sizing: border-box; 
            font-weight: bold;
        }
        .btn:hover { 
            background-color: #eef2ff; 
        }
        
        /* ライブアラートバー */
        #liveAlertBar { 
            background-color: #fffbeb; 
            border: 2px solid #f59e0b; 
            color: #b45309;
            padding: 10px; 
            margin-bottom: 15px; 
            font-size: 13px; 
            border-radius: 8px;
        }

        /* GUIDE 利用方法 */
        .guide-section { 
            margin-bottom: 25px; 
        }
        .guide-section h2 { 
            margin: 0 0 12px 0; 
            font-size: 16px; 
            font-weight: bold; 
            color: #ffffff;
        }
        .guide-steps { 
            display: flex; 
            gap: 10px; 
            justify-content: space-between; 
        }
        .guide-step { 
            flex: 1; 
            background-color: #ffffff; 
            color: #333333;
            border-radius: 12px;
            padding: 12px; 
            font-size: 11px; 
            text-align: left; 
        }
        .guide-step-img { 
            font-size: 24px; 
            margin-bottom: 6px; 
            text-align: center; 
        }
        .guide-step strong { 
            display: block; 
            margin-bottom: 6px; 
            font-size: 12px; 
            text-align: center; 
            color: #1e2d5a;
            border-bottom: 1px dashed #cccccc; 
            padding-bottom: 4px; 
        }

        /* ログイン状態・各種操作パネル */
        .auth-control-panel { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background-color: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 12px; 
            margin-bottom: 25px; 
            border-radius: 8px;
        }
        .auth-buttons { 
            display: flex; 
            gap: 6px; 
        }

        /* 各アンケートセクションの縦並びリスト構造 */
        .survey-section { 
            margin-bottom: 30px; 
        }
        .section-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 2px solid rgba(255, 255, 255, 0.3); 
            padding-bottom: 6px; 
            margin-bottom: 12px; 
        }
        .section-header h3 { 
            margin: 0; 
            font-size: 15px; 
            font-weight: bold; 
            color: #ffffff;
        }
        
        /* リスト配置（設計書に合わせたフラットな1行ずつの縦並び） */
        .survey-list { 
            display: flex; 
            flex-direction: column; 
            gap: 8px; 
        }
        .survey-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background-color: #ffffff; 
            color: #333333;
            border-radius: 8px;
            padding: 10px 15px; 
        }
        
        .survey-info { 
            display: flex; 
            flex-direction: column; 
            gap: 4px; 
        }
        .survey-date { 
            font-size: 12px; 
            color: #666666; 
            font-weight: bold;
        }
        .survey-title { 
            font-size: 14px; 
            font-weight: bold; 
            margin: 0; 
            color: #1e2d5a;
        }
        .survey-creator { 
            font-size: 12px; 
            color: #555555; 
        }
        
        .survey-actions { 
            display: flex; 
            gap: 6px; 
        }

        /* ページネーション */
        .pagination { 
            display: flex; 
            justify-content: center; 
            list-style: none; 
            padding: 0; 
            margin: 15px 0; 
            gap: 6px; 
        }
        .page-link { 
            display: block; 
            padding: 4px 10px; 
            border-radius: 4px;
            text-decoration: none; 
            color: #ffffff; 
            font-size: 12px; 
            background-color: rgba(255,255,255,0.1); 
        }
        .page-item.active .page-link { 
            background-color: #ffffff; 
            color: #1e2d5a; 
            font-weight: bold;
        }

        /* 並べ替えポップアップ */
        .sort-container { 
            position: relative; 
        }
        .sort-popup { 
            position: absolute; 
            top: 100%; 
            right: 0; 
            background-color: #ffffff; 
            color: #333333;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 100; 
            display: none; 
            padding: 4px 0; 
            margin-top: 4px; 
        }
        .sort-popup.show-popup { 
            display: block; 
        }
        .sort-option { 
            display: block; 
            width: 150px; 
            padding: 8px 12px; 
            font-size: 12px; 
            color: #333333; 
            text-align: left; 
            background: none; 
            border: none; 
            cursor: pointer; 
        }
        .sort-option:hover { 
            background-color: #f3f4f6; 
        }

        /* 退会モーダル */
        .withdraw-overlay { 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background-color: rgba(0,0,0,0.5); 
            z-index: 200; 
            display: <?php echo (isset($_GET['view']) && $_GET['view'] === 'withdraw') ? 'flex' : 'none'; ?>; 
            align-items: center; 
            justify-content: center; 
        }
        .withdraw-popup { 
            background-color: #ffffff; 
            color: #333333;
            padding: 20px; 
            border-radius: 12px; 
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

        /* 設計書（PAGE 3）記載通りのピンクの四角いページトップボタン */
        .page-top-pink-btn { 
            position: fixed; 
            bottom: 20px; 
            right: 20px; 
            background-color: #ff4a8d; /* 設計書準拠のピンク */
            color: #ffffff; 
            border: none; 
            width: 45px; 
            height: 45px; 
            border-radius: 8px; 
            font-size: 12px; 
            font-weight: bold; 
            cursor: pointer; 
            z-index: 150; 
            text-align: center; 
            line-height: 1.2; 
            box-sizing: border-box; 
            padding-top: 6px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        /* MEMBER メンバー（設計書のテキスト配置を完全再現） */
        .member-section { 
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 15px; 
            margin-top: 35px; 
            margin-bottom: 20px; 
        }
        .member-leader { 
            font-weight: bold; 
            font-size: 14px; 
            margin-bottom: 8px; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.2); 
            padding-bottom: 4px; 
        }
        .member-list { 
            font-size: 12px; 
            line-height: 1.8; 
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>

    <?php echo "<link rel='stylesheet' href='../css/footer.css'>"; ?>

    <?php include 'header.php'; ?>

    <div class="container">
        
        <div id="liveAlertBar" style="display: none;">
            ✓ <span id="liveAlertText">メッセージ</span>
        </div>
        
        <section class="guide-section">
            <h2>GUIDE 利用方法</h2>
            <div class="guide-steps">
                <div class="guide-step">
                    <div class="guide-step-img">📋</div>
                    <strong>1. 調査要件の策定</strong>
                    <div>アンケートの起案者は、事前に本システムへログインを完了した上で、調査目的の定義、設問数および選択肢の内部フォーマットの設計を厳密に行わなければなりません。収集すべきデータの属性を考慮し、対象ユーザーに不必要な負担を与えないよう、あらかじめ質問内容を精査・準備することが義務付けされています。</div>
                </div>
                <div class="guide-step">
                    <div class="guide-step-img">📢</div>
                    <strong>2. 電磁的公示の実行</strong>
                    <div>準備された調査要件に基づき、「アンケートフォーム作成」機能を使用してシステムへの登録処理を執り行います。タイトルや回答に要する想定所要時間を電算システムに入力し、確定操作を完了させた時点で、当システムを閲覧するすべての構成員に対して電磁的な公示および告知が自動的に執行されます。</div>
                </div>
                <div class="guide-step">
                    <div class="guide-step-img">👥</div>
                    <strong>3. 回答権限の監視</strong>
                    <div>公示されたアンケート案件は、システムによって設定された有効期限（end_at）に至るまで自動的にステータスが監視され、データ受付可能状態が維持されます。対象となる構成員は、それぞれの権限に基づいて該当レコードへアクセスし、付与された有効期限の枠内においてのみ電子的送信を行う権利を有します。</div>
                </div>
                <div class="guide-step">
                    <div class="guide-step-img">💻</div>
                    <strong>4. 応答データの送信</strong>
                    <div>各案件カードに配置された「回答する」リンクを押下すると、専用の応答データ入力フォームが展開されます。利用者は、展開されたフォームの所定記述欄に対し、客観的事実および自身の真実に基づいた適切なデータを遅滞なく入力した上で、送信シグナルをホストサーバーへ向けて実行してください。</div>
                </div>
                <div class="guide-step">
                    <div class="guide-step-img">📊</div>
                    <strong>5. 統計情報の電算処理</strong>
                    <div>データベースへ正常に格納され蓄積された応答レコードおよびデータログは、システム内部の集計モジュールによってリアルタイムに電算処理されます。処理された統計データは、「結果を見る」ボタンを経由することで、グラフおよび視覚的統計情報としていつでも安全に閲覧・検証を行うことが可能です。</div>
                </div>
            </div>
        </section>

        <div class="auth-control-panel">
            <div>
                <?php if ($is_logged_in): ?>
                    <span>ログイン中: <strong><?php echo h($_SESSION['account_name'] ?? '会員ユーザー'); ?></strong> 様</span>
                <?php else: ?>
                    <span style="font-size: 12px; color: #cccccc;">ゲストユーザー様</span>
                <?php endif; ?>
            </div>
            <div class="auth-buttons">
                <?php if (!$is_logged_in): ?>
                    <a href="signup.php" class="btn">ユーザー登録</a>
                    <a href="signin.php" class="btn">サインイン</a>
                <?php else: ?>
                    <a href="survey_form.php" class="btn">アンケートフォーム作成</a>
                    <a href="profile.php" class="btn">ユーザー情報の変更</a>
                    <a href="index.php?view=withdraw" class="btn">退会→</a>
                    <a href="index.php?action=signout" class="btn">サインアウト</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_logged_in): ?>
            <section class="survey-section">
                <div class="section-header">
                    <h3>MY SURVEY 作成したアンケート</h3>
                    <div class="sort-container">
                        <button class="btn sort-trigger-btn" style="background-color: transparent; color: #fff; border-color: #fff;">並べ替え ▾</button>
                        <div class="sort-popup">
                            <button class="sort-option" data-sort-type="start">新着順</button>
                            <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                            <button class="sort-option" data-sort-type="responses">回答数が多い順</button>
                        </div>
                    </div>
                </div>
                <div class="survey-list">
                    <?php if (empty($created_surveys)): ?>
                        <div class="survey-row"><span style="font-size:12px;">作成したアンケートはありません。</span></div>
                    <?php else: ?>
                        <?php foreach ($created_surveys as $survey): ?>
                            <div class="survey-row" id="survey-card-<?php echo h($survey['survey_id']); ?>">
                                <div class="survey-info">
                                    <div class="survey-date">
                                        <span id="date-box-<?php echo h($survey['survey_id']); ?>">
                                            <?php echo h(date('Y.m.d', strtotime($survey['end_at']))); ?>
                                        </span>
                                        (回答: <?php echo (int)($survey['response_count'] ?? 0); ?>件)
                                    </div>
                                    <h4 class="survey-title">「<?php echo h($survey['title']); ?>〜」</h4>
                                </div>
                                <div class="survey-actions">
                                    <button type="button" class="btn js-extend-btn" 
                                            data-survey-id="<?php echo h($survey['survey_id']); ?>"
                                            data-survey-title="<?php echo h($survey['title']); ?>">延長</button>
                                    <a href="result.php?id=<?php echo h($survey['survey_id']); ?>" class="btn">結果</a>
                                    <a href="survey_form.php?id=<?php echo h($survey['survey_id']); ?>" class="btn">編集</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="survey-section">
                <div class="section-header">
                    <h3>MY SURVEY 回答したアンケート</h3>
                    <div class="sort-container">
                        <button class="btn sort-trigger-btn" style="background-color: transparent; color: #fff; border-color: #fff;">並べ替え ▾</button>
                        <div class="sort-popup">
                            <button class="sort-option" data-sort-type="start">新着順</button>
                            <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                        </div>
                    </div>
                </div>
                <div class="survey-list">
                    <?php if (empty($answered_surveys)): ?>
                        <div class="survey-row"><span style="font-size:12px;">過去に回答したアンケートはありません。</span></div>
                    <?php else: ?>
                        <?php foreach ($answered_surveys as $survey): ?>
                            <div class="survey-row">
                                <div class="survey-info">
                                    <div class="survey-date"><?php echo h(date('Y.m.d', strtotime($survey['end_at']))); ?></div>
                                    <h4 class="survey-title">「<?php echo h($survey['title']); ?>〜」</h4>
                                    <div class="survey-creator">作成: <?php echo h($survey['creator_name'] ?? '不明'); ?></div>
                                </div>
                                <div class="survey-actions">
                                    <a href="result.php?id=<?php echo h($survey['survey_id']); ?>" class="btn">結果</a>
                                    <a href="question.php?id=<?php echo h($survey['survey_id']); ?>&mode=edit" class="btn">編集</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="survey-section">
            <div class="section-header">
                <h3>SURVEY アンケート (回答受付中)</h3>
                <div class="sort-container">
                    <button class="btn sort-trigger-btn" style="background-color: transparent; color: #fff; border-color: #fff;">並べ替え ▾</button>
                    <div class="sort-popup">
                        <button class="sort-option" data-sort-type="start">新着順</button>
                        <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                        <button class="sort-option" data-sort-type="responses">回答数が多い順</button>
                    </div>
                </div>
            </div>
            <div class="survey-list">
                <?php if (empty($active_surveys)): ?>
                    <div class="survey-row"><span style="font-size:12px;">現在、受付中のアンケートはありません。</span></div>
                <?php else: ?>
                    <?php foreach ($active_surveys as $survey): ?>
                        <?php 
                            $spec = !empty($survey['survey_spec']) ? json_decode($survey['survey_spec'], true) : [];
                            $required_time = isset($spec['Estimated_time']) ? (int)$spec['Estimated_time'] : 0; 
                        ?>
                        <div class="survey-row">
                            <div class="survey-info">
                                <div class="survey-date">
                                    <span id="public-date-box-<?php echo h($survey['survey_id']); ?>">
                                        <?php echo h(date('Y.m.d', strtotime($survey['end_at']))); ?>
                                    </span>
                                    <?php if ($required_time > 0): ?>
                                        <span> 安時間:<?php echo $required_time; ?>分) 10m</span>
                                    <?php endif; ?>
                                </div>
                                <h4 class="survey-title">「<?php echo h($survey['title']); ?>〜」</h4>
                                <div class="survey-creator">作成: <?php echo h($survey['creator_name'] ?? '不明'); ?></div>
                            </div>
                            <div class="survey-actions">
                                <a href="question.php?id=<?php echo h($survey['survey_id']); ?>" class="btn">回答(○月○日~)</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php if ($total_pages_active > 1): ?>
                <ul class="pagination">
                    <?php for ($i = 1; $i <= $total_pages_active; $i++): ?>
                        <li class="page-item <?php echo $page_active === $i ? 'active' : ''; ?>">
                            <a class="page-link" href="index.php?p_act=<?php echo $i; ?>&p_res=<?php echo $page_result; ?>&sort=<?php echo h($sort_type); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="survey-section">
            <div class="section-header">
                <h3>Results 調査結果</h3>
                <div class="sort-container">
                    <button class="btn sort-trigger-btn" style="background-color: transparent; color: #fff; border-color: #fff;">並べ替え ▾</button>
                    <div class="sort-popup">
                        <button class="sort-option" data-sort-type="start">新着順</button>
                        <button class="sort-option" data-sort-type="deadline">回答期限が近い順</button>
                        <button class="sort-option" data-sort-type="responses">回答数が多い順</button>
                    </div>
                </div>
            </div>
            <div class="survey-list">
                <?php if (empty($result_surveys)): ?>
                    <div class="survey-row"><span style="font-size:12px;">過去ログデータはありません。</span></div>
                <?php else: ?>
                    <?php foreach ($result_surveys as $survey): ?>
                        <div class="survey-row">
                            <div class="survey-info">
                                <div class="survey-date"><?php echo h(date('Y.m.d', strtotime($survey['end_at']))); ?></div>
                                <h4 class="survey-title">「<?php echo h($survey['title']); ?>〜」</h4>
                                <div class="survey-creator">作成: <?php echo h($survey['creator_name'] ?? '不明'); ?></div>
                            </div>
                            <div class="survey-actions">
                                <a href="result.php?id=<?php echo h($survey['survey_id']); ?>" class="btn">結果(○月○日~)</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($total_pages_result > 1): ?>
                <ul class="pagination">
                    <?php for ($i = 1; $i <= $total_pages_result; $i++): ?>
                        <li class="page-item <?php echo $page_result === $i ? 'active' : ''; ?>">
                            <a class="page-link" href="index.php?p_act=<?php echo $page_active; ?>&p_res=<?php echo $i; ?>&sort=<?php echo h($sort_type); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="member-section">
            <div class="member-leader">MEMBER メンバー 社長 村上悠</div>
            <div class="member-list">
                吉守祥 中城大志 野元悠惺 香 湯場崎啓心 前田凱南 折本敢太 酒匂莉乃 丸山夕渚 用貝有基
            </div>
        </section>
    </div>

    <div class="withdraw-overlay" id="withdrawOverlay">
        <div class="withdraw-popup">
            <p class="withdraw-message">本当に退会しますか？</p>
            <form action="index.php" method="POST" class="withdraw-buttons">
                <input type="hidden" name="action" value="delete_account">
                <a href="index.php" class="btn">戻る</a>
                <button type="submit" class="btn" style="background-color: #efefef;">退会</button>
            </form>
        </div>
    </div>

    <button class="page-top-pink-btn">▲<br>TOP</button>

    <footer>
        &copy; 2026 村上製作所 アンケート管理システム 
        <a href="terms.php">利用規約</a> | <a href="privacy.php">プライバシーポリシー</a>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // ==========================================
            // 非同期通信の延長ロジック
            // ==========================================
            const extendButtons = document.querySelectorAll('.js-extend-btn');
            const alertBar = document.getElementById('liveAlertBar');
            const alertText = document.getElementById('liveAlertText');

            extendButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const surveyId = this.dataset.surveyId;
                    const surveyTitle = this.dataset.surveyTitle;
                    
                    fetch('index.php?api=extend&sort=<?php echo urlencode($sort_type); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ survey_id: surveyId })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const dateBox = document.getElementById(`date-box-${surveyId}`);
                            if (dateBox) dateBox.textContent = data.new_time;
                            
                            const publicDateBox = document.getElementById(`public-date-box-${surveyId}`);
                            if (publicDateBox) publicDateBox.textContent = data.new_time;

                            alertText.textContent = `「${surveyTitle}」の回答期限を10分間延長しました。`;
                            alertBar.style.display = 'block';
                        } else {
                            alert('延長処理に失敗しました。');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                    });
                });
            });

            // 並べ替えポップアップの制御
            const sortTriggerButtons = document.querySelectorAll('.sort-trigger-btn');
            sortTriggerButtons.forEach(button => {
                button.addEventListener('click', (event) => {
                    document.querySelectorAll('.sort-popup').forEach(p => {
                        if (p !== button.nextElementSibling) p.classList.remove('show-popup');
                    });
                    const activeSortPopup = button.nextElementSibling;
                    activeSortPopup.classList.toggle('show-popup');
                    event.stopPropagation();
                });
            });

            const sortOptions = document.querySelectorAll('.sort-option');
            sortOptions.forEach(option => {
                option.addEventListener('click', function() {
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
                scrollTopButton.addEventListener('click', () => {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            }
        });
    </script>
    <?php require_once "footer.php"; ?>
</body>
</html>