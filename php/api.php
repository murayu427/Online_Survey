<?php
/**
 * ------------------------------------------------------------------------
 * サービス・コアAPI (api.php)
 * ------------------------------------------------------------------------
 * 役割：フロントエンド（JS）からの非同期リクエストを受け、DB操作やセッション更新を行う。
 * 出力形式：常に JSON 形
 * ------------------------------------------------------------------------
 */

// 1. 外部依存ファイルの読み込み
require_once __DIR__ . '/error.php';
require_once __DIR__ . '/db.php'; // DB ヘルパー関数を利用
// auth.php / security.php を利用してセッション・CSRF・NGワードを扱う
if (file_exists(__DIR__ . '/auth.php')) {
    require_once __DIR__ . '/auth.php';
}
if (file_exists(__DIR__ . '/security.php')) {
    require_once __DIR__ . '/security.php';
}

// セッション開始（auth.php の start_sess があればそれを利用）
if (function_exists('start_sess')) {
    start_sess();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// エラーハンドラを登録（例外を JSON で整形して返す）
if (function_exists('registerErrorHandlers')) {
    registerErrorHandlers();
}

/**
 * 現在のユーザーIDを返す。未ログインなら null を返す。
 */
function get_current_user_id(): ?int
{
    if (session_status() === PHP_SESSION_NONE) {
        if (function_exists('start_sess')) start_sess(); else session_start();
    }
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * リクエストから CSRF トークンを取得する（ヘッダまたはフォームフィールドを探す）
 */
function get_request_csrf_token(): ?string
{
    $token = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach (['X-CSRF-Token', 'X-XSRF-TOKEN', 'x-csrf-token', 'x-xsrf-token'] as $h) {
            if (!empty($headers[$h])) { $token = $headers[$h]; break; }
        }
    }

    if (!$token) {
        foreach (['HTTP_X_CSRF_TOKEN', 'HTTP_X_XSRF_TOKEN', 'HTTP_X_CSRFTOKEN'] as $s) {
            if (!empty($_SERVER[$s])) { $token = $_SERVER[$s]; break; }
        }
    }

    if (!$token && isset($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
    }

    return ($token === null || $token === '') ? null : (string)$token;
}

/**
 * CSRF 検証。失敗したら JSON エラーで終了する。
 */
function validate_csrf(): void
{
    $token = get_request_csrf_token();
    if ($token === null) {
        renderApiError('CSRF token missing.', 400);
    }

    // セッションは start_sess() で既に開始されている前提
    if (!isset($_SESSION['csrf_token'])) {
        renderApiError('CSRF token missing in session.', 400);
    }

    // auth.php の check_csrf は非API 用に die() を行うためここでは直接比較する
    if (!hash_equals((string)$_SESSION['csrf_token'], (string)$token)) {
        renderApiError('Invalid CSRF token.', 400);
    }
}

/**
 * コンテンツの妥当性検査（security.php の checkWord を優先し、なければ簡易チェック）
 */
function isValidContent(?string $text): bool
{
    if ($text === null) return false;
    $trimmed = trim($text);
    if ($trimmed === '') return false;

    // テスト時にスキップ指示がある場合は OK とする
    if (session_status() === PHP_SESSION_NONE) {
        if (function_exists('start_sess')) start_sess(); else session_start();
    }
    if (!empty($_SESSION['bypass_checkword'])) return true;

    // security.php の checkWord が利用可能ならそれに委譲する（既存の制約を尊重）
    if (function_exists('checkWord')) {
        try {
            // security.php 側のデフォルト制約をそのまま使う
            return checkWord($trimmed);
        } catch (Throwable $e) {
            // フォールバックへ
        }
    }

    // 最低限のサイズチェック（非常に長い入力は拒否）
    if ((function_exists('mb_strlen') ? mb_strlen($trimmed) : strlen($trimmed)) > 2000) return false;

    // DB に禁則語テーブルがあれば照合
    if (function_exists('get_forbidden_words')) {
        try {
            $forbidden = get_forbidden_words();
            foreach ($forbidden as $w) {
                if ($w !== '' && mb_stripos($trimmed, $w) !== false) return false;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    return true;
}

// 2. 共通レスポンスヘッダーの設定
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 3. リクエストメソッドの検証 (POST以外は受け付けない)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    renderApiError('Invalid Request Method', 405);
}

// 4. アクションの分岐処理
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        // --- TEST SETUP: テスト専用、セッションとCSRFを作成（ローカル専用） ---
        case 'test_setup':
            // テスト用シークレットで保護する
            $secret = $_POST['test_secret'] ?? '';
            $envSecret = getenv('API_TEST_SECRET') ?: '';
            // 組み込みサーバ（cli-server）上で実行するローカルテストではシークレットチェックを緩和
            if (PHP_SAPI !== 'cli-server' && ($envSecret === '' || $secret === '' || $secret !== $envSecret)) {
                renderApiError('Test secret missing or invalid.', 403);
            }

            $username = $_POST['username'] ?? ('apitest_' . bin2hex(random_bytes(4)));

            // 既存ユーザーを取得、なければ作成
            if (function_exists('get_user_by_name') && function_exists('insert_user')) {
                $user = get_user_by_name($username);
                if ($user === null) {
                    $pw = bin2hex(random_bytes(8));
                    insert_user($username, password_hash($pw, PASSWORD_DEFAULT));
                    $user = get_user_by_name($username);
                }
            } else {
                renderApiError('User functions not available.', 500);
            }

            if (session_status() === PHP_SESSION_NONE) {
                if (function_exists('start_sess')) start_sess(); else session_start();
            }

            $_SESSION['user_id'] = (int)$user['user_id'];
            $_SESSION['username'] = $user['account_name'];
            $_SESSION['last_acc'] = time();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            // テスト専用フラグ：NGワードチェックをスキップ
            $_SESSION['bypass_checkword'] = true;

            echo json_encode(['status' => 'success', 'user_id' => $user['user_id'], 'csrf_token' => $_SESSION['csrf_token']]);
            break;
        // --- TEST: NGチェックの有効/無効を切り替える ---
        case 'test_set_check':
            $value = $_POST['value'] ?? null;
            // 許可条件: cli-server またはテストシークレット
            $secret = $_POST['test_secret'] ?? '';
            $envSecret = getenv('API_TEST_SECRET') ?: '';
            if (PHP_SAPI !== 'cli-server' && ($envSecret === '' || $secret === '' || $secret !== $envSecret)) {
                renderApiError('Test secret missing or invalid.', 403);
            }

            if (session_status() === PHP_SESSION_NONE) {
                if (function_exists('start_sess')) start_sess(); else session_start();
            }

            if ($value === null) {
                echo json_encode(['status' => 'success', 'bypass' => !empty($_SESSION['bypass_checkword'])]);
                break;
            }

            $_SESSION['bypass_checkword'] = empty($value) ? false : true;
            echo json_encode(['status' => 'success', 'bypass' => (bool)$_SESSION['bypass_checkword']]);
            break;
        // --- A. いいね処理 (Toggle Like) ---
        case 'like':
            // CSRF と認証の検証
            validate_csrf();

            $comment_id = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
            $user_id = get_current_user_id();

            if ($comment_id <= 0) throw new Exception("Comment ID is required.");
            if ($user_id === null) throw new Exception("Authentication required.");

            // db.php の toggle_like を使う (like_type は仮に 1)
            $res = toggle_like($user_id, $comment_id, 1);

            $total = isset($res['like_count']) ? (int)$res['like_count'] : 0;

            // $play_voice = (mt_rand(1, 10) === 1);

            $play_voice = !empty($res['liked']);

            echo json_encode([
                'status' => 'success',
                'total_likes' => $total,
                'liked' => $res['liked'] ?? false,
                'play_voice' => $play_voice
            ]);
            break;

        // --- B. コメント投稿処理 ---
        case 'comment':
            // CSRF と認証の検証
            validate_csrf();

            $survey_id = isset($_POST['survey_id']) ? (int)$_POST['survey_id'] : 0;
            $raw_text = $_POST['text'] ?? '';
            $user_id = get_current_user_id();

            if ($survey_id <= 0 || trim($raw_text) === '') throw new Exception("Invalid data.");
            if ($user_id === null) throw new Exception("Authentication required.");

            // セキュリティチェック
            if (!isValidContent($raw_text)) {
                renderApiError('不適切な内容が含まれています。', 400);
            }

            // DB ヘルパーを使って挿入（db.php の insert_comment を利用）
            insert_comment($survey_id, $user_id, $raw_text);

            // コメントIDは PDO の lastInsertId から取得（DB/ドライバ依存のためフォールバックあり）
            $newId = 0;
            try {
                $newId = (int)getPdo()->lastInsertId();
            } catch (Throwable $e) {
                // 取得できない場合は 0 のまま
            }

            // フロント用 HTML を構築（XSS 保護）
            $safe_text = htmlspecialchars($raw_text, ENT_QUOTES, 'UTF-8');
            $comment_html = "<div id=\"comment-{$newId}\" class=\"comment-item\">"
                . "<p style=\"margin-top: 0;\"><strong>" . htmlspecialchars($_SESSION['username'] ?? 'ゲスト利用者', ENT_QUOTES, 'UTF-8') . "</strong></p>"
                . "<p>{$safe_text}</p>"
                . "<button type=\"button\" onclick=\"toggleLike({$newId})\" class=\"mt-2 border border-gray-300 px-3 py-1 rounded-full text-sm lift-button\">"
                . "👍 <span id=\"like-count-{$newId}\">0</span>"
                . "</button>"
                . "</div>";

            echo json_encode([
                'status' => 'success',
                'comment_html' => $comment_html
            ]);
            break;

        // --- C. リアルタイム保存処理 (Session Update) ---
        case 'save':
            // CSRF の検証（サイレントセーブでもトークンは要求する設計）
            validate_csrf();

            $type = $_POST['type'] ?? 'draft'; // answer or survey
            $question_id = trim((string)($_POST['question_id'] ?? ''));
            $payload = json_decode($_POST['payload'] ?? '{}', true);

            if (session_status() === PHP_SESSION_NONE) {
                if (function_exists('start_sess')) start_sess(); else session_start();
            }

            // セッション変数に保存（DB負荷を避け、メモリ上で管理）
            if ($question_id === '') {
                $question_id = 'default';
            }

            $_SESSION['autosave'][$type][$question_id] = [
                'data' => $payload,
                'timestamp' => date('H:i:s')
            ];

            echo json_encode([
                'status' => 'success',
                'saved_at' => $_SESSION['autosave'][$type][$question_id]['timestamp']
            ]);
            break;

        default:
            throw new Exception("Unknown action: " . $action);
    }

} catch (Exception $e) {
    renderApiError($e->getMessage(), 400, $e);
}
