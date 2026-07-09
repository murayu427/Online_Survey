<?php
/**
 * ------------------------------------------------------------------------
 * データベース操作ヘルパー (db.php)
 * ------------------------------------------------------------------------
 * 役割：PostgreSQLとの接続管理および各種CRUD処理を担当する。
 * 利用側：api.php や画面処理から呼び出される。
 * ------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/error.php';

// ========================================
// 1. DB接続設定
// ========================================

$dsn = getenv('DB_DSN') ?: 'pgsql:dbname=group1db;options=--client_encoding=UTF8';
$db_user = getenv('DB_USER') ?: 'group1';
$db_pass = getenv('DB_PASS') ?: 'Group1';
$pdo = null;

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    renderDbError($e);
}

// ========================================
// 2. DB接続・クエリ実行ユーティリティ
// ========================================

/**
 * 現在のPDO接続オブジェクトを取得する
 */
function getPdo(): PDO
{
    global $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    throw new RuntimeException('Database connection not available.');
}

/**
 * SQL文を実行し、実行結果(PDOStatement)を返す
 */
function executeQuery(string $sql, array $params = []): PDOStatement
{
    $pdo = getPdo();

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        renderDbError($e);
    }
}

/**
 * JSON文字列を配列へ変換する
 */
function decodeJson(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }

    $result = json_decode($json, true);
    return is_array($result) ? $result : [];
}

/**
 * UUID(v4)を生成する
 */
function generateUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ========================================
// 4. ユーザー関連処理
// ========================================

/**
 * ユーザーを新規登録する
 */
function insert_user(string $name, string $password_hash): bool
{
    $sql = 'INSERT INTO users (account_name, password_hash, created_at, updated_at) VALUES (:name, :password_hash, NOW(), NOW())';
    executeQuery($sql, [
        ':name' => $name,
        ':password_hash' => $password_hash,
    ]);

    return true;
}

/**
 * ユーザー名からユーザー情報を取得する
 */
function get_user_by_name(string $name): ?array
{
    $sql = 'SELECT * FROM users WHERE account_name = :name LIMIT 1';
    $stmt = executeQuery($sql, [':name' => $name]);
    $user = $stmt->fetch();
    return $user === false ? null : $user;
}

/**
 * ユーザ情報を変更する
 */

function update_user(string $userid, string $newname, string $newhashedpass): bool{
    $sql = "update users set account_name=:newname,password_hash=:newpass,updated_at=NOW() where user_id=:currentname";
    $stmt = executeQuery($sql, [':newname'=>$newname,':newpass'=>$newhashedpass,':currentname'=>$userid]);
    return $stmt->rowCount() > 0;
}

/**
 * ユーザーと関連データをまとめて削除する（トランザクション使用）
 */
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
        renderDbError($e);
    }
}

// ========================================
// 5. アンケート関連処理
// ========================================

/**
 * アンケートを新規作成し、公開用キーを返す
 */
function insert_survey(int $creator_id, string $title, array $spec, string $start, string $end): string
{
    $survey_spec = json_encode($spec, JSON_UNESCAPED_UNICODE);
    if ($survey_spec === false) {
        throw new RuntimeException('Failed to encode survey spec.');
    }

    $question_key = generateUuid();
    $result_key = generateUuid();

    $sql = 'INSERT INTO surveys (creator_id, title, survey_spec, start_at, end_at, question_key, result_key, is_notified, created_at, updated_at) VALUES (:creator_id, :title, :survey_spec, :start_at, :end_at, :question_key, :result_key, false, NOW(), NOW())';
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

/**
 * アンケート一覧を取得する（ページネーション対応）
 */
function get_surveys_list(int $limit, int $offset): array
{
    $sql = 'SELECT survey_id, creator_id, title, survey_spec, start_at, end_at, question_key, result_key, is_notified FROM surveys ORDER BY start_at ASC LIMIT :limit OFFSET :offset';
    $stmt = executeQuery($sql, [
        ':limit' => $limit,
        ':offset' => $offset,
    ]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['survey_spec'] = decodeJson($row['survey_spec'] ?? '');
    }

    return $rows;
}

/**
 * アンケートのタイトルとタグを全件取得する
 */

function get_all_survey_titles(): array
{
    $sql = "SELECT title, question_key, survey_spec->>'Survey_tag' AS tags FROM surveys";
    $stmt = executeQuery($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['tags'] = $row['tags'] ? json_decode($row['tags'], true) : [];
    }

    return $rows;
}

/**
 * ホームページのアンケート一覧を取得する
 *
 * @param string $listType  一覧の種類（作成したアンケート / 回答したアンケート / アンケート / 調査結果）
 * @param string $sortOrder 並び替え順（開始期限 / 新着 / 回答数）
 * @param int|null $user_id ユーザーID（作成・回答・調査結果表示時に必要）
 * @param int $limit 取得件数
 * @param int $offset 取得開始位置
 * @return array
 */
function get_homepage_survey_list(string $listType, string $sortOrder, ?int $user_id = null, int $limit = 100, int $offset = 0): array
{
    $typeMap = [
        '作成したアンケート' => 'created',
        '回答したアンケート' => 'answered',
        'アンケート' => 'all',
        '調査結果' => 'results',
    ];

    if (!isset($typeMap[$listType])) {
        return [];
    }

    $type = $typeMap[$listType];

    $sql = 'SELECT s.survey_id,
                   s.title,
                   s.end_at,
                   s.question_key,
                   s.result_key,
                   s.created_at,
                   u.account_name AS creator,
                   COALESCE(resp.response_count, 0) AS response_count,
                   s.survey_spec
            FROM surveys s
            JOIN users u ON u.user_id = s.creator_id
            LEFT JOIN (
                SELECT survey_id, COUNT(*) AS response_count
                FROM responses
                GROUP BY survey_id
            ) resp ON resp.survey_id = s.survey_id';

    $conditions = [];
    $params = [];

    switch ($type) {
        case 'created':
            if ($user_id === null) {
                return [];
            }
            $conditions[] = 's.creator_id = :user_id';
            $params[':user_id'] = $user_id;
            break;
        case 'answered':
            if ($user_id === null) {
                return [];
            }
            $conditions[] = 'EXISTS (SELECT 1 FROM responses r WHERE r.survey_id = s.survey_id AND r.user_id = :user_id)';
            $params[':user_id'] = $user_id;
            break;
        case 'results':
            $conditions[] = 's.end_at <= NOW()';
            if ($user_id !== null) {
                $conditions[] = 's.creator_id = :user_id';
                $params[':user_id'] = $user_id;
            }
            break;
        case 'all':
            $conditions[] = 's.end_at > NOW()';
            break;
        default:
            break;
    }

    if ($conditions !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $stmt = executeQuery($sql, $params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $surveySpec = decodeJson($row['survey_spec'] ?? '');
        $row['deadline'] = $row['end_at'];
        $row['duration'] = parse_survey_duration($surveySpec);
        $row['created_at'] = $row['created_at'] ?? null;
        unset($row['survey_spec'], $row['end_at']);
    }

    $limit = (int)$limit;
    $offset = (int)$offset;
    if ($limit <= 0) {
        $limit = 100;
    }
    if ($offset < 0) {
        $offset = 0;
    }

    usort($rows, static function (array $a, array $b) use ($sortOrder): int {
        $deadlineA = (string)($a['deadline'] ?? '');
        $deadlineB = (string)($b['deadline'] ?? '');
        $timeA = $deadlineA !== '' ? strtotime($deadlineA) : PHP_INT_MAX;
        $timeB = $deadlineB !== '' ? strtotime($deadlineB) : PHP_INT_MAX;

        $createdA = (string)($a['created_at'] ?? '');
        $createdB = (string)($b['created_at'] ?? '');
        $createdTimeA = $createdA !== '' ? strtotime($createdA) : 0;
        $createdTimeB = $createdB !== '' ? strtotime($createdB) : 0;

        switch ($sortOrder) {
            case 'deadline':
                return $timeA <=> $timeB;
            case 'duration':
                return ((int)$a['duration']) <=> ((int)$b['duration']) ?: $timeA <=> $timeB;
            case 'ended_recent':
                return $timeB <=> $timeA ?: $createdTimeB <=> $createdTimeA;
            case 'responses':
                return ((int)$b['response_count']) <=> ((int)$a['response_count']) ?: $createdTimeB <=> $createdTimeA;
            case 'start':
            default:
                return $createdTimeB <=> $createdTimeA ?: $timeA <=> $timeB;
        }
    });

    $rows = array_slice($rows, $offset, $limit);

    return array_map(static function (array $row): array {
        return [
            'survey_id' => (int)$row['survey_id'],
            'title' => (string)$row['title'],
            'deadline' => $row['deadline'] !== null ? $row['deadline'] : null,
            'creator' => (string)$row['creator'],
            'response_count' => (int)$row['response_count'],
            'duration' => $row['duration'],
            'question_key' => (string)($row['question_key'] ?? ''),
            'result_key' => (string)($row['result_key'] ?? ''),
        ];
    }, $rows);
}

/**
 * アンケートの回答期限を延長する
 * 
 * @param int $survey_id 対象のアンケートID
 * @param int $user_id 実行者（作成者）のユーザーID
 * @param string $new_end_at 新しい終了日時（Y-m-d H:i:s形式）
 * @return string|null 延長後の日時（Y.m.d H:i形式）、失敗時はnull
 */
function extend_survey_deadline(int $survey_id, int $user_id, string $new_end_at): ?string
{
    try {
        // 入力値の妥当性チェック
        $parsedTime = strtotime($new_end_at);
        if (!$parsedTime || $parsedTime <= time()) {
            return null; // 無効または過去の時刻
        }

        // タイムゾーン対応：明示的にUTCで統一
        $newEndAtFormatted = date('Y-m-d H:i:s', $parsedTime);

        $sql = "UPDATE surveys 
                SET end_at = :new_end_at,
                    updated_at = NOW()
                WHERE survey_id = :survey_id AND creator_id = :user_id
                RETURNING end_at";

        $stmt = executeQuery($sql, [
            ':survey_id' => $survey_id,
            ':user_id'   => $user_id,
            ':new_end_at' => $newEndAtFormatted,
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            return date('Y.m.d H:i', strtotime($result['end_at']));
        }
        
        return null;
    } catch (PDOException $e) {
        renderDbError($e);
        return null;
    }
}

/**
 * survey_spec から目安回答時間を自動計算する
 * ルール: 単一選択=15秒 / 複数選択=20秒 / テキスト=60秒
 * 合計秒数を分に換算し、1分未満は1分に切り上げる
 */
function parse_survey_duration(array $surveySpec): int
{
    $questions = $surveySpec['questions'] ?? [];
    if (!is_array($questions) || $questions === []) {
        return 0;
    }

    $totalSeconds = 0;
    foreach ($questions as $question) {
        $type = $question['type'] ?? 'single';

        switch ($type) {
            case 'multiple':
                $totalSeconds += 20;
                break;
            case 'text':
                $totalSeconds += 60;
                break;
            case 'single':
            default:
                $totalSeconds += 15;
                break;
        }
    }

    $minutes = (int)ceil($totalSeconds / 60);
    return max(1, $minutes);
}

/**
 * 公開キーまたは結果キーからアンケート情報を取得する
 */
function get_survey_by_key(string $key, string $type): ?array
{
    $column = $type === 'result_key' ? 'result_key' : 'question_key';
    $sql = "SELECT * FROM surveys WHERE {$column} = :key LIMIT 1";
    $stmt = executeQuery($sql, [':key' => $key]);
    $survey = $stmt->fetch();

    if ($survey === false) {
        return null;
    }

    $survey['survey_spec'] = decodeJson($survey['survey_spec'] ?? '');
    return $survey;
}

/**
 * アンケート情報を更新する
 */
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

    $sql = 'UPDATE surveys SET ' . implode(', ', $setClauses) . ', updated_at = NOW() WHERE survey_id = :survey_id';
    executeQuery($sql, $params);
    return true;
}

/**
 * 通知済みフラグをONにする
 */
function update_notification_flag(int $survey_id): bool
{
    $sql = 'UPDATE surveys SET is_notified = true WHERE survey_id = :survey_id';
    executeQuery($sql, [':survey_id' => $survey_id]);
    return true;
}

/**
 * 期限切れで未通知のアンケート一覧を取得する
 */
function get_expired_surveys_to_notify(int $user_id): array
{
    $sql = 'SELECT survey_id, title, end_at FROM surveys 
            WHERE creator_id = :user_id 
              AND is_notified = FALSE 
              AND end_at < NOW()';

    $params = [':user_id' => $user_id];
    $stmt = executeQuery($sql, $params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ========================================
// 6. 回答・レスポンス関連処理
// ========================================

/**
 * 指定アンケートの回答一覧を取得する
 */
function get_responses_by_survey_id(int $survey_id): array
{
    $sql = 'SELECT response_id, survey_id, user_id, answer_data, answered_at FROM responses WHERE survey_id = :survey_id ORDER BY answered_at ASC';
    $stmt = executeQuery($sql, [':survey_id' => $survey_id]);
    $responses = $stmt->fetchAll();

    foreach ($responses as &$response) {
        $response['answer_data'] = decodeJson($response['answer_data'] ?? '');
    }

    return $responses;
}

/**
 * 指定アンケートに対するログインユーザーの最新回答を取得する
 */
function get_response_by_survey_and_user(int $survey_id, ?int $user_id): ?array
{
    if ($user_id === null) {
        return null;
    }

    $sql = 'SELECT response_id, survey_id, user_id, answer_data, answered_at
            FROM responses
            WHERE survey_id = :survey_id AND user_id = :user_id
            ORDER BY answered_at DESC, response_id DESC
            LIMIT 1';
    $stmt = executeQuery($sql, [':survey_id' => $survey_id, ':user_id' => $user_id]);
    $response = $stmt->fetch();

    if ($response === false) {
        return null;
    }

    $response['answer_data'] = decodeJson($response['answer_data'] ?? '');
    return $response;
}

/**
 * 回答を登録する（既存回答があれば更新）
 */
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

// ========================================
// 7. コメント・いいね関連処理
// ========================================

/**
 * コメントを登録する
 */
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

/**
 * アンケートIDに紐づくコメント一覧を取得する
 */
function get_comments_by_survey_id(int $survey_id): array
{
    $sql = 'SELECT c.comment_id,
                   c.content AS comment,
                   u.account_name,
                   c.created_at,
                   COUNT(l.like_id) AS like_count
            FROM comments c
            JOIN users u ON c.user_id = u.user_id
            LEFT JOIN likes l ON c.comment_id = l.comment_id
            WHERE c.survey_id = :survey_id
            GROUP BY c.comment_id, c.content, u.account_name, c.created_at
            ORDER BY c.created_at DESC';

    $stmt = executeQuery($sql, [':survey_id' => $survey_id]);
    return $stmt->fetchAll();
}

/**
 * いいねの追加・解除を切り替え、現在の件数を返す
 */
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

// ========================================
// 8. forbidden_words関連処理
// ========================================

/**
 * データベースから禁止文字列（NGワード）の一覧を取得する
 */
function get_forbidden_words(): array
{
    $sql = 'SELECT word FROM forbidden_words';
    
    $stmt = executeQuery($sql);
    
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
