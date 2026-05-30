<?php
/**
 * ------------------------------------------------------------------------
 * サービス・コアAPI (api.php)
 * ------------------------------------------------------------------------
 * 役割：フロントエンド（JS）からの非同期リクエストを受け、DB操作やセッション更新を行う。
 * 出力形式：常に JSON 形式。
 * ------------------------------------------------------------------------
 */

// 1. 外部依存ファイルの読み込み
require_once 'db.php'; // DB ヘルパー関数を利用


// Minimal inline fallback for session / auth. Replace with real `auth.php` later.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function get_current_user_id(): ?int
{
    // If a real auth layer provides `$_SESSION['user_id']`, use it.
    // Otherwise return null (anonymous). db.php expects null for unauthenticated users.
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

// Minimal inline fallback for content validation. Replace with real `security.php` later.
function isValidContent(?string $text): bool
{
    if ($text === null) return false;
    $trimmed = trim($text);
    if ($trimmed === '') return false;
    if (mb_strlen($trimmed) > 2000) return false;
    // Project-specific NG words / filters should be added to security.php.
    return true;
}

// 2. 共通レスポンスヘッダーの設定
header('Content-Type: application/json; charset=utf-8');

// 3. リクエストメソッドの検証 (POST以外は受け付けない)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method']);
    exit;
}

// 4. アクションの分岐処理
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // --- A. いいね処理 (Toggle Like) ---
        case 'like':
            $comment_id = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;

            $user_id = get_current_user_id();

            if ($comment_id <= 0) throw new Exception("Comment ID is required.");
            if ($user_id === null) throw new Exception("Authentication required.");

            // db.php の toggle_like を使う (like_type は仮に 1)
            $res = toggle_like($user_id, $comment_id, 1);

            $total = isset($res['like_count']) ? (int)$res['like_count'] : 0;

            echo json_encode([
                'status' => 'success',
                'total_likes' => $total,
                'liked' => $res['liked'] ?? false,
                'play_voice' => ($total > 0 && $total % 10 === 0)
            ]);
            break;

        // --- B. コメント投稿処理 ---
        case 'comment':
            $survey_id = isset($_POST['survey_id']) ? (int)$_POST['survey_id'] : 0;
            $raw_text = $_POST['text'] ?? '';
            $user_id = get_current_user_id();

            if ($survey_id <= 0 || trim($raw_text) === '') throw new Exception("Invalid data.");
            if ($user_id === null) throw new Exception("Authentication required.");

            // セキュリティチェック
            if (!isValidContent($raw_text)) {
                echo json_encode(['status' => 'error', 'message' => '不適切な内容が含まれています。']);
                exit;
            }

            // DB ヘルパーを使って挿入
            insert_comment($survey_id, $user_id, $raw_text);

            // 新規コメントの ID を取得（AUTO_INCREMENT を想定）
            $newId = (int)getPdo()->lastInsertId();

            // フロント用 HTML を構築（XSS 保護）
            $safe_text = htmlspecialchars($raw_text, ENT_QUOTES, 'UTF-8');
            $comment_html = "<div id=\"comment-{$newId}\" class=\"comment-item\">"
                . "<p>{$safe_text}</p>"
                . "<span class=\"likes-count\" id=\"like-count-{$newId}\">0</span>"
                . "<button type=\"button\" onclick=\"toggleLike('{$newId}')\">いいね</button>"
                . "</div>";

            echo json_encode([
                'status' => 'success',
                'comment_html' => $comment_html
            ]);
            break;

        // --- C. リアルタイム保存処理 (Session Update) ---
        case 'save':
            $type = $_POST['type'] ?? 'draft'; // answer or survey
            $payload = json_decode($_POST['payload'] ?? '{}', true);

            // セッション変数に保存（DB負荷を避け、メモリ上で管理）
            $_SESSION['autosave'][$type] = [
                'data' => $payload,
                'timestamp' => date('H:i:s')
            ];

            echo json_encode([
                'status' => 'success',
                'saved_at' => $_SESSION['autosave'][$type]['timestamp']
            ]);
            break;

        default:
            throw new Exception("Unknown action: " . $action);
    }

} catch (Exception $e) {
    // 予期せぬエラーはすべてここで捕捉し、JSON形式で返す
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
