-- db_test.php 用テストデータ
-- 1. テストユーザー
INSERT INTO users (account_name, password_hash, created_at, updated_at)
VALUES ('test_user_php', 'hashed_password_123', NOW(), NOW());

-- 2. NGワードの登録（get_forbidden_words() のテスト用）
INSERT INTO forbidden_words (word) VALUES ('不適切語A');
INSERT INTO forbidden_words (word) VALUES ('不適切語B');

-- 3. 期限切れ・未通知アンケート（get_expired_surveys_to_notify() 用）
INSERT INTO surveys (creator_id, question_key, result_key, title, is_notified, survey_spec, start_at, end_at, created_at, updated_at)
VALUES (1, 'expired-q-key', 'expired-r-key', '期限切れテストアンケート', FALSE,
'[{"type":"text","title":"自由記述"}]',
'2024-01-01', '2024-01-02', NOW(), NOW());

-- 4. 通常運用アンケート（JSONB 形式を含む）
INSERT INTO surveys (creator_id, question_key, result_key, title, is_notified, survey_spec, start_at, end_at, created_at, updated_at)
VALUES (1, 'active-q-key', 'active-r-key', 'PCのメモリ調査', FALSE,
'{"questions":[{"type":"single","title":"容量は？","options":["8GB","16GB","32GB"]},{"type":"multi","title":"用途は？","options":["仕事","ゲーム","動画編集"]}]}',
'2026-01-01', '2026-12-31', NOW(), NOW());

-- 5. 未来アンケート（get_surveys_list() で複数レコードを確認する用）
INSERT INTO surveys (creator_id, question_key, result_key, title, is_notified, survey_spec, start_at, end_at, created_at, updated_at)
VALUES (1, 'future-q-key', 'future-r-key', '2027年リリース希望調査', FALSE,
'{"questions":[{"type":"text","title":"期待する新機能は？"}]}',
'2027-01-01', '2027-12-31', NOW(), NOW());

-- 6. 過去通知済アンケート（expired には含めない確認用）
INSERT INTO surveys (creator_id, question_key, result_key, title, is_notified, survey_spec, start_at, end_at, created_at, updated_at)
VALUES (1, 'notified-q-key', 'notified-r-key', '過去のアンケート', TRUE,
'{"questions":[{"type":"single","title":"好きなOSは？","options":["Windows","Mac","Linux"]}]}',
'2020-01-01', '2020-01-31', NOW(), NOW());

-- 7. 回答データの登録（user_id あり）
INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
VALUES (2, 1, '{"q1":"16GB","q2":["仕事","ゲーム"]}', 20, 1, NOW());

-- 8. 回答データの登録（匿名回答 user_id=NULL）
INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
VALUES (2, NULL, '{"q1":"8GB","q2":["動画編集"]}', 35, 2, NOW());

-- 9. コメントの登録
INSERT INTO comments (survey_id, user_id, content, created_at)
VALUES (2, 1, '面白いアンケートですね！', NOW());

-- 10. いいねの登録
INSERT INTO likes (user_id, comment_id, like_type, created_at)
VALUES (1, 1, 1, NOW());

-- 11. 追加ユーザー（本番テストでは不要だが複数ユーザー時の応答確認用）
INSERT INTO users (account_name, password_hash, created_at, updated_at)
VALUES ('test_user2_php', 'hashed_password_456', NOW(), NOW());

-- 12. 追加回答（別ユーザーによる回答、result 取得時のデータ確認用）
INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
VALUES (2, 2, '{"q1":"32GB","q2":["ゲーム","仕事"]}', 30, 2, NOW());

-- いいねが「0件」のコメント（LEFT JOINのテスト用）
INSERT INTO comments (survey_id, user_id, content, created_at)
VALUES (2, 2, 'このアンケート、選択肢が足りない気がします。', NOW());

-- 複数のユーザーから「いいね」されているコメント（COUNT集計のテスト用）
INSERT INTO comments (survey_id, user_id, content, created_at)
VALUES (2, 1, '結果が楽しみですね。', NOW());

-- 上記のコメント（ID:3と仮定）に対して、ユーザー1と2の両方がいいね
INSERT INTO likes (user_id, comment_id, like_type, created_at) VALUES (1, 3, 1, NOW());
INSERT INTO likes (user_id, comment_id, like_type, created_at) VALUES (2, 3, 1, NOW());

-- 自由記述のみのアンケート
INSERT INTO surveys (creator_id, question_key, result_key, title, is_notified, survey_spec, start_at, end_at, created_at, updated_at)
VALUES (1, 'text-q-key', 'text-r-key', '自由な意見箱', FALSE,
'{"questions":[{"type":"text","title":"改善してほしい点は？"}]}',
'2026-01-01', '2026-12-31', NOW(), NOW());

-- 自由記述への回答
INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
VALUES (3, 1, '{"q1":"スマホで見にくいです"}', 20, 1, NOW());

-- Survey ID 2 (PCメモリ調査) への追加回答（統計的に意味のある数を追加）
INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
VALUES (2, NULL, '{"q1":"32GB","q2":["仕事"]}', 10, 1, NOW()); -- 10代男性
INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
VALUES (2, NULL, '{"q1":"8GB","q2":["仕事"]}', 50, 2, NOW());  -- 50代女性
INSERT INTO responses (survey_id, user_id, answer_data, respondent_age, respondent_gender, answered_at)
VALUES (2, NULL, '{"q1":"16GB","q2":["ゲーム"]}', 20, 2, NOW()); -- 20代女性

-- 実際には保存処理で弾かれるべきだが、DBに直接入れて表示テストを行う
INSERT INTO comments (survey_id, user_id, content, created_at)
VALUES (2, 2, 'このアンケートは不適切語Aが含まれています', NOW());

-- 1. 【期限内からの延長テスト用】
-- 現在時刻から1時間後に終わる設定。延長すると「1時間後 + 指定分」になるはず。
INSERT INTO surveys (
    creator_id, question_key, result_key, title, is_notified, 
    survey_spec, start_at, end_at, created_at, updated_at
) VALUES (
    1,
    'active-extend-q', 'active-extend-r', '期限内延長テスト', FALSE, 
    '[{"type":"text","title":"期限内の延長はどうですか？"}]', 
    NOW() - INTERVAL '1 hour',
    NOW() + INTERVAL '1 hour',
    NOW(), NOW()
);

-- 2. 【期限切れからの延長テスト用】
-- 1時間前に既に終わっている設定。延長すると「現在時刻(NOW) + 指定分」になるはず。
INSERT INTO surveys (
    creator_id, question_key, result_key, title, is_notified, 
    survey_spec, start_at, end_at, created_at, updated_at
) VALUES (
    1, 
    'expired-extend-q', 'expired-extend-r', '期限切れからの再開テスト', FALSE, 
    '[]', 
    NOW() - INTERVAL '2 hours',
    NOW() - INTERVAL '1 hour',
    NOW(), NOW()
);