<?php
declare(strict_types=1);

$dsn = getenv('DB_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=mydb;options=--client_encoding=UTF8';  //修正必要
$db_user = getenv('DB_USER') ?: 'postgres';
$db_pass = getenv('DB_PASS') ?: '';
$pdo = null;

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    renderDbErrorModal($e);
    exit;
}

function renderDbErrorModal(PDOException $e): void
{
    $message = '通信が切れました。もう一度お試しください。';
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="UTF-8"><title>DB Error</title></head><body>';
    echo '<script>window.onload = function() { alert("' . $safeMessage . '"); };</script>';
    echo '</body></html>';
}

function getPdo(): PDO
{
    global $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    throw new RuntimeException('Database connection not available.');
}

function executeQuery(string $sql, array $params = []): PDOStatement
{
    $pdo = getPdo();

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        renderDbErrorModal($e);
        exit;
    }
}

function decodeJson(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }

    $result = json_decode($json, true);
    return is_array($result) ? $result : [];
}

function generateUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function insert_user(string $name, string $password_hash): bool
{
    $sql = 'INSERT INTO users (account_name, password_hash, created_at) VALUES (:name, :password_hash, NOW())';
    executeQuery($sql, [
        ':name' => $name,
        ':password_hash' => $password_hash,
    ]);

    return true;
}

function get_user_by_name(string $name): ?array
{
    $sql = 'SELECT * FROM users WHERE account_name = :name LIMIT 1';
    $stmt = executeQuery($sql, [':name' => $name]);
    $user = $stmt->fetch();
    return $user === false ? null : $user;
}

function delete_user(int $user_id): bool
{
    $pdo = getPdo();
    try {
        $pdo->beginTransaction();

        executeQuery('DELETE FROM likes WHERE user_id = :user_id', [':user_id' => $user_id]);
        executeQuery('DELETE FROM comments WHERE user_id = :user_id', [':user_id' => $user_id]);
        executeQuery('DELETE FROM responses WHERE user_id = :user_id', [':user_id' => $user_id]);
        executeQuery('DELETE FROM surveys WHERE creator_id = :user_id', [':user_id' => $user_id]);
        executeQuery('DELETE FROM users WHERE user_id = :user_id', [':user_id' => $user_id]);

        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        renderDbErrorModal($e);
        exit;
    }
}

function insert_survey(int $creator_id, string $title, array $spec, string $start, string $end): string
{
    $survey_spec = json_encode($spec, JSON_UNESCAPED_UNICODE);
    if ($survey_spec === false) {
        throw new RuntimeException('Failed to encode survey spec.');
    }

    $question_key = generateUuid();
    $result_key = generateUuid();

    $sql = 'INSERT INTO surveys (creator_id, title, survey_spec, start_at, end_at, question_key, result_key, is_notified, created_at) VALUES (:creator_id, :title, :survey_spec, :start_at, :end_at, :question_key, :result_key, false, NOW())';
    executeQuery($sql, [
        ':creator_id' => $creator_id,
        ':title' => $title,
        ':survey_spec' => $survey_spec,
        ':start_at' => $start,
        ':end_at' => $end,
        ':question_key' => $question_key,
        ':result_key' => $result_key,
    ]);

    return $question_key;
}

function get_surveys_list(int $limit, int $offset): array
{
    $sql = 'SELECT survey_id, creator_id, title, survey_spec, start_at, end_at, question_key, result_key, is_notified FROM surveys ORDER BY start_at ASC LIMIT :limit OFFSET :offset';
    $stmt = getPdo()->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['survey_spec'] = decodeJson($row['survey_spec'] ?? '');
    }

    return $rows;
}

function get_survey_by_key(string $key, string $type): ?array
{
    $column = $type === 'result' ? 'result_key' : 'question_key';
    $sql = "SELECT * FROM surveys WHERE {$column} = :key LIMIT 1";
    $stmt = executeQuery($sql, [':key' => $key]);
    $survey = $stmt->fetch();

    if ($survey === false) {
        return null;
    }

    $survey['survey_spec'] = decodeJson($survey['survey_spec'] ?? '');
    return $survey;
}

function update_survey(int $survey_id, array $data): bool
{
    $allowed = ['title', 'survey_spec', 'start_at', 'end_at'];
    $setClauses = [];
    $params = [':survey_id' => $survey_id];

    foreach ($allowed as $column) {
        if (!array_key_exists($column, $data)) {
            continue;
        }

        $value = $data[$column];
        if ($column === 'survey_spec') {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            if ($value === false) {
                throw new RuntimeException('Failed to encode survey spec.');
            }
        }

        $setClauses[] = "{$column} = :{$column}";
        $params[":{$column}"] = $value;
    }

    if ($setClauses === []) {
        return false;
    }

    $sql = 'UPDATE surveys SET ' . implode(', ', $setClauses) . ' WHERE survey_id = :survey_id';
    executeQuery($sql, $params);
    return true;
}

function update_notification_flag(int $survey_id): bool
{
    $sql = 'UPDATE surveys SET is_notified = true WHERE survey_id = :survey_id';
    executeQuery($sql, [':survey_id' => $survey_id]);
    return true;
}

function get_responses_by_survey_id(int $survey_id): array
{
    $sql = 'SELECT response_id, survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at FROM responses WHERE survey_id = :survey_id ORDER BY answered_at ASC';
    $stmt = executeQuery($sql, [':survey_id' => $survey_id]);
    $responses = $stmt->fetchAll();

    foreach ($responses as &$response) {
        $response['answer_data'] = decodeJson($response['answer_data'] ?? '');
    }

    return $responses;
}

function upsert_response(int $survey_id, ?int $user_id, array $answer_data): bool
{
    $payload = json_encode($answer_data, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Failed to encode answer data.');
    }
    if ($user_id === null) {
        $sql = 'INSERT INTO responses (survey_id, user_id, answer_data, answered_at) 
                VALUES (:survey_id, NULL, :answer_data, NOW())';
        executeQuery($sql, [':survey_id' => $survey_id, ':answer_data' => $payload]);
        return true;
    }
    $sql = 'INSERT INTO responses (survey_id, user_id, answer_data, answered_at) 
            VALUES (:survey_id, :user_id, :answer_data, NOW()) 
            ON CONFLICT (survey_id, user_id) 
            DO UPDATE SET answer_data = EXCLUDED.answer_data, answered_at = NOW()';
    
    executeQuery($sql, [':survey_id' => $survey_id, ':user_id' => $user_id, ':answer_data' => $payload]);
    return true;
}

function insert_comment(int $survey_id, int $user_id, string $content): bool
{
    $sql = 'INSERT INTO comments (survey_id, user_id, content, created_at) VALUES (:survey_id, :user_id, :content, NOW())';
    executeQuery($sql, [
        ':survey_id' => $survey_id,
        ':user_id' => $user_id,
        ':content' => $content,
    ]);

    return true;
}

function toggle_like(int $user_id, int $comment_id, int $like_type): array
{
    $sql = 'SELECT like_id FROM likes WHERE user_id = :user_id AND comment_id = :comment_id AND like_type = :like_type LIMIT 1';
    $stmt = executeQuery($sql, [':user_id' => $user_id, ':comment_id' => $comment_id, ':like_type' => $like_type]);
    $like = $stmt->fetch();

    if ($like !== false) {
        executeQuery('DELETE FROM likes WHERE like_id = :like_id', [':like_id' => $like['like_id']]);
        $liked = false;
    } else {
        executeQuery('INSERT INTO likes (user_id, comment_id, like_type, created_at) VALUES (:user_id, :comment_id, :like_type, NOW())', [
            ':user_id' => $user_id,
            ':comment_id' => $comment_id,
            ':like_type' => $like_type,
        ]);
        $liked = true;
    }

    $countStmt = executeQuery('SELECT COUNT(*) AS total FROM likes WHERE comment_id = :comment_id AND like_type = :like_type', [':comment_id' => $comment_id, ':like_type' => $like_type]);
    $countRow = $countStmt->fetch();
    $like_count = isset($countRow['total']) ? (int)$countRow['total'] : 0;

    return ['liked' => $liked, 'like_count' => $like_count];
}
