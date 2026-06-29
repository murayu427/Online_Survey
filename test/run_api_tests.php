<?php
// run_api_tests.php
// 実行方法: php run_api_tests.php
// テスト内容:
// - comment 成功/NG
// - like トグル
// - save 自動保存

declare(strict_types=1);

// 設定
$HOST = PHP_OS_FAMILY === 'Windows' ? '127.0.0.1' : '127.0.0.1';
$PORT = 8000;
$ROOT = dirname(__DIR__);
$BASE = "http://{$HOST}:{$PORT}";

// DB 設定（db.php と同じデフォルト）
$dbHost = PHP_OS_FAMILY === 'Windows' ? 'localhost' : '172.18.10.28';
$dsn = getenv('DB_DSN') ?: "pgsql:host={$dbHost};port=5432;dbname=group1db;options=--client_encoding=UTF8";
$dbUser = getenv('DB_USER') ?: 'group1';
$dbPass = getenv('DB_PASS') ?: 'Group1';

function curl_request(string $url, array $opts = []): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if (!empty($opts['method']) && strtoupper($opts['method']) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['post'] ?? []);
    }
    if (!empty($opts['headers'])) curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
    if (!empty($opts['cookiejar'])) curl_setopt($ch, CURLOPT_COOKIEJAR, $opts['cookiejar']);
    if (!empty($opts['cookiefile'])) curl_setopt($ch, CURLOPT_COOKIEFILE, $opts['cookiefile']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'error' => $err];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($resp, 0, $header_size);
    $body = substr($resp, $header_size);
    curl_close($ch);
    return ['status' => 1, 'code' => $code, 'header' => $header, 'body' => $body];
}

function start_php_server(string $root, string $host, int $port) {
    $cmd = sprintf('php -S %s:%d -t %s', $host, $port, escapeshellarg($root));
    $des = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $des, $pipes, $root);
    if (!is_resource($proc)) return null;
    // give server time to start
    usleep(300000);
    return $proc;
}

function stop_php_server($proc) {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
}

echo "Starting tests...\n";

// 1) DB:  テスト用ユーザーとアンケートを作成
try {
    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        echo "DB connection failed: " . $e->getMessage() . "\n";
        exit(1);
    }
} catch (PDOException $e) {
    echo "DB connection failed: " . $e->getMessage() . "\n";
    exit(1);
}


// unique suffix
$suffix = bin2hex(random_bytes(4));

// create survey (we'll create an owner later via test_setup)
$surveyOwnerPlaceholder = null;
$question_key = bin2hex(random_bytes(8));
$result_key = bin2hex(random_bytes(8));
$spec = json_encode(['title' => 'Test Survey']);
// Insert a placeholder survey with creator_id = NULL temporarily if DB allows, otherwise use 0 then update later
$stmt = $pdo->prepare('INSERT INTO surveys (creator_id, question_key, result_key, title, survey_spec) VALUES (:creator, :qk, :rk, :title, :spec)');
// use creator 0 may violate FK, so create a lightweight temporary user owner id=-1 is invalid; instead we'll insert later after creating user
// For simplicity, create survey after test_setup creates user. We'll defer survey creation.
echo "DB connection OK\n";

// 2) Start built-in PHP server
// prepare test secret and export to environment so built-in server sees it
$testSecret = bin2hex(random_bytes(12));
putenv('API_TEST_SECRET=' . $testSecret);

$proc = start_php_server($ROOT, $HOST, $PORT);
if ($proc === null) {
    echo "Failed to start PHP built-in server\n";
    exit(1);
}

echo "PHP server started on {$BASE}\n";


$cookieJar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'api_test_cookies_' . $suffix . '.jar';

// helper to POST to API
function post_api($base, $cookieJar, $params) {
    $url = $base . '/php/api.php';
    return curl_request($url, ['method' => 'POST', 'post' => $params, 'cookiejar' => $cookieJar, 'cookiefile' => $cookieJar]);
}

// Start server after setting env

// 4) Test setup: create test user and get CSRF
$testUser = 'apitest_' . $suffix;
$resp = post_api($BASE, $cookieJar, ['action' => 'test_setup', 'test_secret' => $testSecret, 'username' => $testUser]);
if (!$resp['status'] || $resp['code'] !== 200) { echo "test_setup failed HTTP {$resp['code']}\n"; stop_php_server($proc); exit(1); }
$j = json_decode($resp['body'], true);
$csrf = $j['csrf_token'] ?? null;
$userId = $j['user_id'] ?? null;
if (!$csrf || !$userId) { echo "test_setup did not return csrf/user_id\n"; stop_php_server($proc); exit(1); }
echo "Test user={$testUser} id={$userId} csrf={$csrf}\n";

// create survey owned by this user
$stmt = $pdo->prepare('INSERT INTO surveys (creator_id, question_key, result_key, title, survey_spec) VALUES (:creator, :qk, :rk, :title, :spec)');
$stmt->execute([':creator' => $userId, ':qk' => bin2hex(random_bytes(8)), ':rk' => bin2hex(random_bytes(8)), ':title' => 'API Test Survey', ':spec' => json_encode(['title'=>'API Test Survey'])]);
$surveyId = (int)$pdo->lastInsertId('surveys_survey_id_seq');
echo "Created survey id={$surveyId}\n";

// 5) Test: comment success
$commentText = 'Hello API test ' . bin2hex(random_bytes(3));
$resp = post_api($BASE, $cookieJar, ['action' => 'comment', 'survey_id' => $surveyId, 'text' => $commentText, 'csrf_token' => $csrf]);
echo "Test comment success: HTTP {$resp['code']}\n";
echo "Body: {$resp['body']}\n";
$ok1 = false;
if ($resp['status'] && $resp['code'] === 200) {
    $j = json_decode($resp['body'], true);
    if (isset($j['status']) && $j['status'] === 'success') {
        $ok1 = true;
    }
}

// verify DB has the comment
$stmt = $pdo->prepare('SELECT comment_id, content, user_id FROM comments WHERE survey_id = :sid AND content = :c');
$stmt->execute([':sid' => $surveyId, ':c' => $commentText]);
$found = $stmt->fetch(PDO::FETCH_ASSOC);
$dbHas = $found !== false;
echo "DB comment present: " . ($dbHas ? 'yes (id='.$found['comment_id'].')' : 'no') . "\n";

// 6) Test: comment NG (contains forbidden word 'コーヒー')
$ngText = 'これはコーヒーを含むコメントです';
// 有効な NG 検査を試すため、まず NG チェックをオンにする
$resp = post_api($BASE, $cookieJar, ['action' => 'test_set_check', 'value' => 0, 'test_secret' => $testSecret]);
$resp = post_api($BASE, $cookieJar, ['action' => 'comment', 'survey_id' => $surveyId, 'text' => $ngText, 'csrf_token' => $csrf]);
echo "Test comment NG: HTTP {$resp['code']} Body: {$resp['body']}\n";
$j = json_decode($resp['body'], true);
$ok2 = ($resp['status'] && isset($j['status']) && $j['status'] === 'error');

// ensure DB did not add the NG comment
$stmt = $pdo->prepare('SELECT comment_id FROM comments WHERE survey_id = :sid AND content = :c');
$stmt->execute([':sid' => $surveyId, ':c' => $ngText]);
$ngFound = $stmt->fetch(PDO::FETCH_ASSOC);
echo "DB NG comment present: " . ($ngFound ? 'yes' : 'no') . "\n";

// 7) Test: like toggle
$likeOk = false;
if ($dbHas) {
    $commentId = (int)$found['comment_id'];
    // like
    $resp = post_api($BASE, $cookieJar, ['action' => 'like', 'comment_id' => $commentId, 'csrf_token' => $csrf]);
    echo "Like response: HTTP {$resp['code']} Body: {$resp['body']}\n";
    $j = json_decode($resp['body'], true);
    if (isset($j['status']) && $j['status'] === 'success' && isset($j['liked']) && $j['liked'] === true && isset($j['total_likes']) && $j['total_likes'] >= 1) {
        // unlike
        $resp2 = post_api($BASE, $cookieJar, ['action' => 'like', 'comment_id' => $commentId, 'csrf_token' => $csrf]);
        echo "Unlike response: HTTP {$resp2['code']} Body: {$resp2['body']}\n";
        $j2 = json_decode($resp2['body'], true);
        if (isset($j2['status']) && $j2['status'] === 'success' && $j2['liked'] === false) {
            $likeOk = true;
        }
    }
}

// 8) Test: save autosave
$payload = ['foo' => 'bar', 'num' => 123];
$resp = post_api($BASE, $cookieJar, ['action' => 'save', 'type' => 'answer', 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'csrf_token' => $csrf]);
echo "Save response: HTTP {$resp['code']} Body: {$resp['body']}\n";
$saveOk = false;
if ($resp['status'] && $resp['code'] === 200) {
    $j = json_decode($resp['body'], true);
    if (isset($j['status']) && $j['status'] === 'success') {
        // read session via test endpoint
        $resp2 = curl_request($BASE . '/test/read_session.php', ['method' => 'GET', 'cookiefile' => $cookieJar, 'cookiejar' => $cookieJar]);
        echo "Read session: HTTP {$resp2['code']} Body: {$resp2['body']}\n";
        $jj = json_decode($resp2['body'], true);
        if (!empty($jj['autosave']['answer']['data']) && $jj['autosave']['answer']['data']['foo'] === 'bar') {
            $saveOk = true;
        }
    }
}

// Summary
echo "\n=== Test Summary ===\n";
echo "Comment success endpoint: " . ($ok1 ? 'PASS' : 'FAIL') . "\n";
echo "Comment persisted in DB: " . ($dbHas ? 'YES' : 'NO') . "\n";
echo "Comment NG rejected: " . ($ok2 && !$ngFound ? 'PASS' : 'FAIL') . "\n";
echo "Like toggle: " . ($likeOk ? 'PASS' : 'FAIL') . "\n";
echo "Autosave save/read: " . ($saveOk ? 'PASS' : 'FAIL') . "\n";

// Cleanup
echo "Cleaning up test data...\n";
$pdo->prepare('DELETE FROM likes WHERE comment_id IN (SELECT comment_id FROM comments WHERE survey_id = :sid)')->execute([':sid' => $surveyId]);
$pdo->prepare('DELETE FROM comments WHERE survey_id = :sid')->execute([':sid' => $surveyId]);
$pdo->prepare('DELETE FROM surveys WHERE survey_id = :sid')->execute([':sid' => $surveyId]);
$pdo->prepare('DELETE FROM users WHERE user_id = :uid')->execute([':uid' => $userId]);

stop_php_server($proc);
echo "Server stopped.\n";

// exit code
$allOk = $ok1 && $dbHas && $ok2 && !$ngFound && $likeOk && $saveOk;
exit($allOk ? 0 : 2);
