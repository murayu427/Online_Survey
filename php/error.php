<?php
/**
 * ------------------------------------------------------------------------
 * 共通エラー処理 (error.php)
 * ------------------------------------------------------------------------
 * 役割：アプリ全体で発生したエラーを logger.php の writeLog で記録し、
 *       呼び出し元（API / ダウンロード / 通常画面）に応じたレスポンスを返す。
 * 参考：db.php にあった renderDbErrorModal の処理を共通化したもの。
 * 利用側：require_once __DIR__ . '/error.php'; のうえ、各関数を呼び出す。
 * ------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/logger.php';

// ========================================
// 1. リクエスト種別の判定
// ========================================

/**
 * API（JSON / XHR / api.php）からの呼び出しかどうかを判定する
 */
function isApiRequest(): bool
{
    if (php_sapi_name() === 'cli') {
        return false;
    }

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');

    if (stripos($accept, 'application/json') !== false) {
        return true;
    }
    if (strcasecmp($xhr, 'XMLHttpRequest') === 0) {
        return true;
    }
    if ($script === 'api.php') {
        return true;
    }

    return false;
}

/**
 * ダウンロード系スクリプト（download.php）からの呼び出しかどうかを判定する
 */
function isDownloadRequest(): bool
{
    if (php_sapi_name() === 'cli') {
        return false;
    }

    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return $script === 'download.php';
}

// ========================================
// 2. ログ・出力ユーティリティ
// ========================================

/**
 * 例外情報をログ用の1行文字列に整形する
 */
function formatThrowableMessage(Throwable $e): string
{
    return sprintf(
        '%s in %s on line %d [code=%d]',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getCode()
    );
}

/**
 * 出力バッファをすべて破棄する（HTMLやバイナリ混入を防ぐ）
 */
function clearOutputBuffers(): void
{
    while (ob_get_level()) {
        @ob_end_clean();
    }
}

/**
 * エラーを logger.php の writeLog で記録する
 *
 * @param string $category  ログカテゴリ（db, api, auth など）
 * @param string $level     ログレベル（ERROR, WARNING, CRITICAL など）
 * @param string $message   ログメッセージ
 */
function logError(string $category, string $level, string $message): void
{
    writeLog($category, $level, $message);
}

// ========================================
// 3. 共通エラーレスポンス
// ========================================

/**
 * エラーをログ出力し、リクエスト種別に応じたレスポンスを返して終了する
 *
 * @param string         $userMessage  ユーザー向けメッセージ
 * @param int            $httpCode     HTTPステータスコード
 * @param string         $logCategory  ログカテゴリ
 * @param string         $logLevel     ログレベル
 * @param Throwable|null $e            詳細ログ用の例外（省略可）
 * @param string         $pageTitle    HTML表示時のページタイトル
 */
function renderError(
    string $userMessage,
    int $httpCode = 500,
    string $logCategory = 'app',
    string $logLevel = 'ERROR',
    ?Throwable $e = null,
    string $pageTitle = 'Error'
): never {
    $logMessage = $e !== null
        ? formatThrowableMessage($e)
        : $userMessage;

    logError($logCategory, $logLevel, $logMessage);
    clearOutputBuffers();
    http_response_code($httpCode);

    if (isApiRequest()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status' => 'error',
            'message' => $userMessage,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (isDownloadRequest()) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $userMessage;
        exit;
    }

    $safeMessage = htmlspecialchars($userMessage, ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="UTF-8"><title>' . $safeTitle . '</title>';
    echo '<script>setTimeout(function() {
            window.location.href = "./index.php";
        }, 5000); // 5秒後に遷移
    </script></head><body>';
    echo '<script>window.onload = function() { alert("' . $safeMessage . '"); };</script>';
    echo '</body></html>';   
    exit;
}

/**
 * DB接続・SQL実行エラー時の専用ハンドラ（db.php から呼び出す）
 */
function renderDbError(PDOException $e): never
{
    $userMessage = isDownloadRequest()
        ? '通信が切れました。ダウンロードを中止しました。'
        : '通信が切れました。もう一度お試しください。';

    renderError($userMessage, 500, 'db', 'ERROR', $e, 'DB Error');
}

/**
 * db.php 旧関数名との互換用エイリアス
 */
function renderDbErrorModal(PDOException $e): never
{
    renderDbError($e);
}

/**
 * API向けのエラーレスポンス（JSON固定）
 */
function renderApiError(
    string $message,
    int $httpCode = 400,
    ?Throwable $e = null,
    string $logLevel = 'WARNING'
): never {
    if ($e !== null) {
        logError('api', $logLevel, formatThrowableMessage($e));
    } else {
        logError('api', $logLevel, $message);
    }

    clearOutputBuffers();
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=UTF-8');

    $payload = [
        'status' => 'error',
        'message' => $message,
    ];

    if ($e !== null) {
        $payload['detail'] = formatThrowableMessage($e);
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * バリデーション失敗など、処理は継続可能な警告レベルのエラー
 */
function renderWarning(
    string $userMessage,
    string $logCategory = 'app',
    ?Throwable $e = null
): never {
    renderError($userMessage, 400, $logCategory, 'WARNING', $e);
}

// ========================================
// 4. グローバルハンドラ（任意で登録）
// ========================================

/**
 * 未捕捉の例外・PHPエラーを共通処理へ委譲するハンドラを登録する
 * エントリポイント（api.php など）の先頭で呼び出す。
 */
function registerErrorHandlers(): void
{
    set_exception_handler(static function (Throwable $e): void {
        renderError(
            '予期しないエラーが発生しました。もう一度お試しください。',
            500,
            'app',
            'ERROR',
            $e
        );
    });

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        renderError(
            '予期しないエラーが発生しました。もう一度お試しください。',
            500,
            'app',
            'ERROR',
            new ErrorException($message, 0, $severity, $file, $line)
        );

        return true;
    });
}
