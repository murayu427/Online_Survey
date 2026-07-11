<?php
session_start();

// POST のアンケートデータを受け取る
$data = $_POST;
$edit_mode = isset($data['edit_mode']) && $data['edit_mode'] === '1';
$survey_id = isset($data['survey_id']) && $data['survey_id'] !== '' ? (int)$data['survey_id'] : 0;
$survey_key = $data['survey_key'] ?? '';

// セッションに一時保存（完了画面で使う）
$_SESSION['survey_input'] = $data;
$_SESSION['survey_edit_mode'] = $edit_mode;
$_SESSION['survey_edit_id'] = $survey_id;
$_SESSION['survey_edit_key'] = $survey_key;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>アンケート作成 - 確認</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-[#1E2D5A] flex items-center justify-center min-h-screen">
    <div class="bg-[#24376F] p-8 rounded-2xl shadow-2xl border border-white/10 w-full max-w-2xl">
        
        <h1 class="text-3xl font-bold mb-8 text-center text-white">アンケート内容の確認</h1>

        <div class="space-y-6 mb-6 text-white">
            <?php foreach ($data['q_label'] as $i => $label): ?>
                <div class="border-b border-white/10 pb-3">
                    <span class="text-sm text-gray-300 block">質問<?= $i ?></span>
                    <span class="text-lg font-medium"><?= htmlspecialchars($label) ?></span>

                    <?php if (!empty($data['q_option'][$i])): ?>
                        <div class="mt-2 ml-4 text-gray-300">
                            <?php foreach ($data['q_option'][$i] as $opt): ?>
                                <p>・<?= htmlspecialchars($opt) ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <form action="survey_complete.php" method="POST">
    <input type="hidden" name="csrf_token"
           value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="edit_mode" value="<?= htmlspecialchars($edit_mode ? '1' : '0', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="survey_id" value="<?= htmlspecialchars((string)$survey_id, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="survey_key" value="<?= htmlspecialchars((string)$survey_key, ENT_QUOTES, 'UTF-8') ?>">

    <button type="submit" class="btn-submit">
        この内容で作成する
    </button>
</form>

    </div>
</body>
</html>
