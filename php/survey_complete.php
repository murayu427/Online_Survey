<?php
require_once 'db.php';
require_once 'security.php';
require_once __DIR__ . '/error.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ------------------------------
   CSRF チェック
------------------------------ */
$posted_token  = $_POST['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';

if (empty($posted_token) || $posted_token !== $session_token) {
    renderError('403 Forbidden: 不正なリクエストです。', 403, 'app', 'WARNING');
}

/* ------------------------------
   セッションから入力データ取得
------------------------------ */
$input = $_SESSION['survey_input'] ?? null;
if (!$input) {
    renderError('アンケート情報が見つかりません。最初からやり直してください。', 400, 'app', 'WARNING');
}

try {

    /* ------------------------------
       作成者ID（ログインユーザー）
    ------------------------------ */
    $creator_id = (int)($_SESSION['user_id'] ?? 0);
    if ($creator_id <= 0) {
        throw new RuntimeException('ログインユーザーが不明です。');
    }

    /* ------------------------------
       タイトル
    ------------------------------ */
    $title = (string)($input['title'] ?? '');

    /* ------------------------------
       開始・終了日時（空なら NULL）
    ------------------------------ */
    $start = !empty($input['start_at']) ? $input['start_at'] : null;
    $end   = !empty($input['end_at'])   ? $input['end_at']   : null;

    /* ------------------------------
       survey_spec を組み立てる
    ------------------------------ */
    $spec = [
        'description' => (string)($input['description'] ?? ''),
        'Survey_tag'  => !empty($input['tags'])
            ? array_map('trim', explode(',', $input['tags']))
            : [],
        'questions'   => [],
    ];

    /* ------------------------------
       質問を詰める
    ------------------------------ */
    if (!empty($input['q_label']) && is_array($input['q_label'])) {
        foreach ($input['q_label'] as $idx => $label) {

            $spec['questions'][] = [
                'label'          => (string)$label,
                'type'           => (string)($input['q_type'][$idx] ?? 'single'),
                'options'        => array_values($input['q_option'][$idx] ?? []),
                'result_display' => (string)($input['q_result_display'][$idx] ?? 'bar'),
            ];
        }
    }

    /* ------------------------------
       DB 登録（insert_survey）
    ------------------------------ */
    $question_key = insert_survey(
        $creator_id,
        $title,
        $spec,
        $start,
        $end
    );

    /* ------------------------------
       一時データ削除
    ------------------------------ */
    unset($_SESSION['survey_input']);

} catch (Throwable $e) {

    renderError(
        '500 Internal Server Error: アンケート登録中にエラーが発生しました。',
        500,
        'db',
        'ERROR',
        $e,
        'Survey Complete Error'
    );
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>アンケート作成 - 完了</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-[#1E2D5A] flex items-center justify-center min-h-screen">
    <div class="bg-[#24376F] p-8 rounded-2xl shadow-2xl border border-white/10 w-full max-w-md text-center">
        <h1 class="text-3xl font-bold mb-6 text-white">作成が完了しました</h1>
        <p class="text-gray-300 mb-8">アンケートが正常に作成されました。</p>

        <a href="index.php" class="bg-blue-600 text-white py-2 px-6 rounded hover:bg-blue-700 transition font-medium">
            トップへ戻る
        </a>
    </div>
</body>
</html>
