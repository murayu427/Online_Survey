<?php
require_once '../php/db.php';

header('Content-Type: text/html; charset=UTF-8');
echo "<!doctype html><html><head><meta charset=\"UTF-8\"><title>DB操作関数テスト</title></head><body>";
echo "<h1>データベース操作関数 単独テスト</h1>";

function printSection(string $title): void  //セクションタイトルを表示するための関数
{
    echo "<h2>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</h2>";
}

function dumpValue(mixed $value): void  //配列の中身を見やすく表示するための関数
{
    echo '<pre>' . htmlspecialchars(var_export($value, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
}

// 1. forbidden_words テーブルから NG ワードの一覧を取得できるか確認する。
//    成功条件: 空配列ではなく、DB に登録済みの禁止語が取得できること。
printSection('1. get_forbidden_words() のテスト');
$forbidden = get_forbidden_words();
dumpValue($forbidden);

// 2. ユーザー検索機能を確認する。ユーザーが存在しない場合は insert_user() で追加する。
//    成功条件: get_user_by_name() でユーザー情報が返ること、
//    または insert_user() 後に get_user_by_name() で同じユーザーを取得できること。
printSection('2. get_user_by_name() / insert_user() のテスト');
$testUserName = 'test_user_php';
$user = get_user_by_name($testUserName);
if (!$user) {
    $passwordHash = password_hash('password123', PASSWORD_DEFAULT);
    $inserted = insert_user($testUserName, $passwordHash);
    echo $inserted ? 'insert_user() 成功<br>' : 'insert_user() 失敗<br>';
    $user = get_user_by_name($testUserName);
}
if ($user) {
    echo '成功: ユーザーID ' . htmlspecialchars((string)$user['user_id'], ENT_QUOTES, 'UTF-8') . '<br>';
    dumpValue($user);
} else {
    echo '失敗: ユーザーが見つかりません。';
}

if ($user) {
    // 3. アンケート作成・検索・更新の流れを検証。
    //    成功条件: insert_survey() でキーが返り、get_survey_by_key() で同じキーのアンケート情報が取得できること。
    //    さらに update_survey() が true を返してタイトル更新できること。
    printSection('3. insert_survey() / get_survey_by_key() / update_survey() のテスト');
    $surveySpec = ['questions' => ['title' => 'あなたの好きな色は？']];
    $start = date('c', strtotime('-1 day'));
    $end = date('c', strtotime('+1 day'));
    $questionKey = insert_survey((int)$user['user_id'], 'テストアンケート', $surveySpec, $start, $end);
    echo 'insert_survey() で作成された question_key: ' . htmlspecialchars($questionKey, ENT_QUOTES, 'UTF-8') . '<br>';

    $survey = get_survey_by_key($questionKey, 'question');
    if ($survey) {
        echo 'タイトル: ' . htmlspecialchars($survey['title'], ENT_QUOTES, 'UTF-8') . '<br>';
        echo 'survey_spec の型: ' . htmlspecialchars(gettype($survey['survey_spec']), ENT_QUOTES, 'UTF-8') . '<br>';
        dumpValue($survey['survey_spec']);

        $updated = update_survey((int)$survey['survey_id'], ['title' => 'テストアンケート（更新）']);
        echo $updated ? 'update_survey() 成功<br>' : 'update_survey() 失敗<br>';
    } else {
        echo 'get_survey_by_key() でアンケートが取得できませんでした。';
    }

    // 4. アンケート一覧取得で複数件取得とページネーションが動くか確認する。
    //    成功条件: get_surveys_list() が配列を返し、作成済みのアンケートを含むこと。
    printSection('4. get_surveys_list() のテスト');
    $surveyList = get_surveys_list(10, 0);
    echo '取得件数: ' . count($surveyList) . '<br>';
    dumpValue($surveyList);

    // 5. 期限切れアンケート通知対象の抽出ロジックを確認する。
    //    成功条件: 終了日時が過去で未通知のアンケートが取得できること。
    printSection('5. get_expired_surveys_to_notify() のテスト');
    $notifications = get_expired_surveys_to_notify((int)$user['user_id']);
    echo '通知対象件数: ' . count($notifications) . ' 件<br>';
    dumpValue($notifications);

    // 5b. update_notification_flag() のテスト
    //    成功条件: 通知フラグを更新した後、expired リストに含まれなくなること。
    printSection('5b. update_notification_flag() のテスト');
    $expiredSurvey = get_survey_by_key('expired-q-key', 'question');
    if ($expiredSurvey) {
        $expiredSurveyId = (int)$expiredSurvey['survey_id'];
        $updated = update_notification_flag($expiredSurveyId);
        echo $updated ? 'update_notification_flag() 成功<br>' : 'update_notification_flag() 失敗<br>';
        $notificationsAfter = get_expired_surveys_to_notify((int)$expiredSurvey['creator_id']);
        echo '更新後の通知対象件数: ' . count($notificationsAfter) . ' 件<br>';
        dumpValue($notificationsAfter);
    } else {
        echo 'expired-q-key のアンケートが見つかりません。';
    }

    // 6. 回答登録と回答問い合わせを確認する。
    //    成功条件: upsert_response() で回答登録し、get_responses_by_survey_id() で回答一覧が取得できること。
    printSection('6. upsert_response() / get_responses_by_survey_id() のテスト');
    if (!empty($survey)) {
        $surveyId = (int)$survey['survey_id'];

        // 6a. user_id ありの回答登録・更新テスト
        upsert_response($surveyId, (int)$user['user_id'], ['answer' => 'テスト回答']);
        upsert_response($surveyId, (int)$user['user_id'], ['answer' => 'テスト回答（更新）']);

        // 6b. 匿名回答パターンの確認
        upsert_response($surveyId, null, ['answer' => '匿名回答']);

        $responses = get_responses_by_survey_id($surveyId);
        dumpValue($responses);
    }

    // 6c. get_survey_by_key(..., 'result') の確認
    printSection('6c. get_survey_by_key(..., \'result\') のテスト');
    if (!empty($survey['result_key'])) {
        $resultSurvey = get_survey_by_key($survey['result_key'], 'result');
        if ($resultSurvey) {
            echo 'result キーで取得に成功: ' . htmlspecialchars($resultSurvey['title'], ENT_QUOTES, 'UTF-8') . '<br>';
            dumpValue($resultSurvey);
        } else {
            echo 'get_survey_by_key(..., \'result\') で取得できませんでした。';
        }
    }

    // 7. get_homepage_survey_list() / parse_survey_duration() のテスト
    //    成功条件: 一覧取得と所要時間の文字列化が動作すること。
    printSection('7. get_homepage_survey_list() / parse_survey_duration() のテスト');
    echo 'parse_survey_duration(estimated_minutes=3): ' . htmlspecialchars(parse_survey_duration(['estimated_minutes' => 3]), ENT_QUOTES, 'UTF-8') . '<br>';
    echo 'parse_survey_duration(duration=\'約5分\'): ' . htmlspecialchars(parse_survey_duration(['duration' => '約5分']), ENT_QUOTES, 'UTF-8') . '<br>';
    echo 'parse_survey_duration(questions x2): ' . htmlspecialchars(parse_survey_duration(['questions' => [['q' => '1'], ['q' => '2']]]), ENT_QUOTES, 'UTF-8') . '<br>';
    echo 'parse_survey_duration(empty): ' . htmlspecialchars(parse_survey_duration([]), ENT_QUOTES, 'UTF-8') . '<br>';

    if (!empty($survey) && !empty($user)) {
        $createdList = get_homepage_survey_list('作成したアンケート', '新着', (int)$user['user_id']);
        echo '作成したアンケート 件数: ' . count($createdList) . '<br>';
        dumpValue($createdList);

        $answeredList = get_homepage_survey_list('回答したアンケート', '回答数', (int)$user['user_id']);
        echo '回答したアンケート 件数: ' . count($answeredList) . '<br>';
        dumpValue($answeredList);

        $allList = get_homepage_survey_list('アンケート', '開始期限');
        echo 'アンケート(全) 件数: ' . count($allList) . '<br>';
        dumpValue(array_slice($allList, 0, 5));

        $expiredStart = date('c', strtotime('-3 days'));
        $expiredEnd = date('c', strtotime('-1 day'));
        insert_survey((int)$user['user_id'], '期限切れテストアンケート', ['questions' => [['q' => 'expired']]], $expiredStart, $expiredEnd);
        $resultList = get_homepage_survey_list('調査結果', '新着', (int)$user['user_id']);
        echo '調査結果 件数: ' . count($resultList) . '<br>';
        dumpValue($resultList);
    } else {
        echo 'get_homepage_survey_list() のテストは、survey または user が存在しないためスキップされました。<br>';
    }

    // --- get_all_survey_titles() のテスト ---
    echo "<h2>テスト: 全アンケートタイトルの取得</h2>";
    
    try {
        $titles = get_all_survey_titles();
    
        if (is_array($titles)) {
            echo "<p>取得成功。件数: " . count($titles) . "件</p>";
            
            if (count($titles) > 0) {
                echo "<ul>";
                foreach ($titles as $title) {
                    echo "<li>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</li>";
                }
                echo "</ul>";
            } else {
                echo "<p>データが0件です。db_test.sql を実行してデータを追加してください。</p>";
            }
        } else {
            echo "<p style='color:red;'>エラー: 戻り値が配列ではありません。</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red;'>致命的なエラーが発生しました: " . $e->getMessage() . "</p>";
    }

    // 8. コメント登録といいね切り替えの挙動を確認する。
    //    成功条件: コメントを追加し、toggle_like() でいいねの状態と件数が返ること。
    printSection('8. insert_comment() / toggle_like() のテスト');
    if (!empty($survey)) {
        $surveyId = (int)$survey['survey_id'];
        insert_comment($surveyId, (int)$user['user_id'], 'テストコメント');
        $commentStmt = executeQuery('SELECT comment_id FROM comments WHERE survey_id = :survey_id ORDER BY created_at DESC LIMIT 1', [':survey_id' => $surveyId]);
        $comment = $commentStmt->fetch();
        if ($comment !== false) {
            $likeResult = toggle_like((int)$user['user_id'], (int)$comment['comment_id'], 1);
            dumpValue($likeResult);

            printSection('7b. get_comments_by_survey_id() のテスト');
            $comments = get_comments_by_survey_id($surveyId);
            echo 'コメント件数: ' . count($comments) . '<br>';
            dumpValue($comments);
        } else {
            echo 'コメント取得に失敗しました。';
        }
    }

    // extend_survey_deadline() のテスト ---
    // 成功条件: 指定した分数（またはデフォルト10分）だけ、終了日時(end_at)が未来に更新されること。
    printSection('5c. extend_survey_deadline() のテスト');

    // テスト対象のアンケートを取得（以前のテストで作成した 'expired-q-key' を利用）
    $targetSurvey = get_survey_by_key('expired-q-key', 'question');

    if ($targetSurvey) {
        $surveyId = (int)$targetSurvey['survey_id'];
        $creatorId = (int)$targetSurvey['creator_id'];
        $oldEndAt = $targetSurvey['end_at'];

        echo "延長前の終了日時: " . date('Y.m.d H:i', strtotime($oldEndAt)) . "<br>";

        // 30分延長をリクエスト
        $extensionMinutes = 30;
        $newEndAt = date('Y-m-d H:i:s', strtotime("+$extensionMinutes minutes"));
        $newTimeFormatted = extend_survey_deadline($surveyId, $creatorId, $newEndAt);

        if ($newTimeFormatted) {
            echo "extend_survey_deadline() 成功！<br>";
            echo "返却された新しい日時: " . $newTimeFormatted . "<br>";

            // DBから再度取得して、本当に書き換わっているか検証（プログラムのシートベルト的確認）
            $updatedSurvey = get_survey_by_key('expired-q-key', 'question');
            echo "DB上の最新の終了日時: " . date('Y.m.d H:i', strtotime($updatedSurvey['end_at'])) . "<br>";

            if ($newTimeFormatted === date('Y.m.d H:i', strtotime($updatedSurvey['end_at']))) {
                echo "<span style='color:green;'>結果の一致を確認しました。</span><br>";
            }
        } else {
            echo "<span style='color:red;'>extend_survey_deadline() 失敗</span>（本人確認エラーまたはDBエラー）<br>";
        }
    } else {
        echo "テスト対象のアンケート（expired-q-key）が見つかりません。先に 3. の INSERT を実行してください。";
    }

    // 9. delete_user() のカスケード削除テスト
    //    成功条件: 対象ユーザーを削除すると user, surveys, responses, comments, likes が削除されること。
    printSection('9. delete_user() のカスケード削除テスト');
    $deleteUserName = 'delete_test_user_php';
    $deleteUser = get_user_by_name($deleteUserName);
    if (!$deleteUser) {
        $passwordHash = password_hash('delete123', PASSWORD_DEFAULT);
        insert_user($deleteUserName, $passwordHash);
        $deleteUser = get_user_by_name($deleteUserName);
    }
    if ($deleteUser) {
        $deleteUserId = (int)$deleteUser['user_id'];
        $rand = bin2hex(random_bytes(4));
        $insertedSurveyStmt = executeQuery(
            'INSERT INTO surveys (creator_id, question_key, result_key, title, is_notified, survey_spec, start_at, end_at, created_at, updated_at) VALUES (:creator_id, :question_key, :result_key, :title, false, :survey_spec, NOW(), NOW(), NOW(), NOW()) RETURNING survey_id',
            [
                ':creator_id' => $deleteUserId,
                ':question_key' => 'del-q-key-' . $rand,
                ':result_key' => 'del-r-key-' . $rand,
                ':title' => '削除テストアンケート',
                ':survey_spec' => '[{"type":"text","title":"削除確認"}]',
            ]
        );
        $deleteSurvey = $insertedSurveyStmt->fetch();
        if ($deleteSurvey !== false) {
            $deleteSurveyId = (int)$deleteSurvey['survey_id'];
            executeQuery('INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at) VALUES (:survey_id, :user_id, :answer_data, 50, 1, NOW())', [':survey_id' => $deleteSurveyId, ':user_id' => $deleteUserId, ':answer_data' => '{"q1":"削除テスト"}']);
            $insertedCommentStmt = executeQuery('INSERT INTO comments (survey_id, user_id, content, created_at) VALUES (:survey_id, :user_id, :content, NOW()) RETURNING comment_id', [':survey_id' => $deleteSurveyId, ':user_id' => $deleteUserId, ':content' => '削除テストコメント']);
            $deleteComment = $insertedCommentStmt->fetch();
            if ($deleteComment !== false) {
                executeQuery('INSERT INTO likes (user_id, comment_id, like_type, created_at) VALUES (:user_id, :comment_id, 1, NOW())', [':user_id' => $deleteUserId, ':comment_id' => (int)$deleteComment['comment_id']]);
            }

            $deleted = delete_user($deleteUserId);
            echo $deleted ? 'delete_user() 成功<br>' : 'delete_user() 失敗<br>';

            $userExists = executeQuery('SELECT COUNT(*) AS cnt FROM users WHERE user_id = :id', [':id' => $deleteUserId])->fetch();
            $surveyExists = executeQuery('SELECT COUNT(*) AS cnt FROM surveys WHERE creator_id = :id', [':id' => $deleteUserId])->fetch();
            $responseExists = executeQuery('SELECT COUNT(*) AS cnt FROM responses WHERE user_id = :id OR survey_id = :survey_id', [':id' => $deleteUserId, ':survey_id' => $deleteSurveyId])->fetch();
            $commentExists = executeQuery('SELECT COUNT(*) AS cnt FROM comments WHERE user_id = :id OR survey_id = :survey_id', [':id' => $deleteUserId, ':survey_id' => $deleteSurveyId])->fetch();
            $likeExists = executeQuery('SELECT COUNT(*) AS cnt FROM likes WHERE user_id = :id', [':id' => $deleteUserId])->fetch();

            echo 'ユーザー残存: ' . ($userExists['cnt'] ?? 0) . '<br>';
            echo 'アンケート残存: ' . ($surveyExists['cnt'] ?? 0) . '<br>';
            echo '回答残存: ' . ($responseExists['cnt'] ?? 0) . '<br>';
            echo 'コメント残存: ' . ($commentExists['cnt'] ?? 0) . '<br>';
            echo 'いいね残存: ' . ($likeExists['cnt'] ?? 0) . '<br>';
        } else {
            echo '削除テスト用アンケートの作成に失敗しました。';
        }
    } else {
        echo 'delete_test_user_php の作成に失敗しました。';
    }
}

echo '<p>注意: このテストは DB にデータを追加する。不要な場合は手動で戻してください。</p>';
echo '</body></html>';

