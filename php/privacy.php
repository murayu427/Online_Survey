<?php
require_once __DIR__ . '/auth.php';
if (function_exists('start_sess')) {
    start_sess();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>プライバシーポリシー - オンラインアンケートサイト</title>
    <link rel="stylesheet" href="../css/reset.css">
    <link rel="stylesheet" href="../css/question.css">
    <link rel="stylesheet" href="../css/footer.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/readability.css">
</head>
<body>
<?php require_once 'header.php'; ?>
<main class="max-w-4xl mx-auto p-6 page-privacy">
    <p class="text-sm text-gray-500">最終更新: 2026-06-22</p>
    <h1><strong>プライバシーポリシー</strong></h1>

    <h2><strong>1. 取得する情報</strong></h2>
    <p>本サービスでは、以下の情報を取得し、データベースに保存します。</p>
    <ul>
        <li><strong>登録時の情報：</strong> アカウント名、パスワード</li>
        <li><strong>サービス利用情報：</strong> アンケートの作成データ（タイトル、設問内容、期間）、各アンケートへの回答データ、コメント内容、および「いいね」の履歴</li>
    </ul>

    <h2><strong>2. 利用目的</strong></h2>
    <p>取得した情報は、以下の目的でのみ利用します。</p>
    <ul>
        <li>・ アンケートの集計および結果の可視化（年代別や性別などのクロス集計を含む）のため</li>
        <li>・ ログイン認証およびセッション管理（二重投票防止や本人確認）のため</li>
        <li>・ 本サービスのシステムテストおよび品質向上のための動作検証として</li>
    </ul>


    <h2><strong>3. 安全管理措置</strong></h2>
    <p>運営者は、取得した情報の漏洩や改ざんを防ぐため、以下の技術的対策を講じています。</p>
    <ul>
        <li>・ パスワードは平文で保存せず、暗号学的ハッシュ関数（SCRAM-SHA-256）を用いて不可逆変換した上で安全に保管します。</li>
        <li>・ ユーザーの入力データに対しては、悪意のあるスクリプト実行を防ぐため、サニタイズ（無害化）処理を実施します。</li>
    </ul>

    <h2><strong>4. データの第三者提供</strong></h2>
    <p>本サービスで取得した情報は、法令に基づく場合を除き、ユーザー本人の同意なく第三者に提供することはありません。</p>


    <h2><strong>5. データの破棄</strong></h2>
    <p>ユーザーが退会した場合、そのユーザーに関連するすべての情報はデータベースから物理削除され、システム内に保持されることはありません。</p>

</main>
<?php require_once 'footer.php'; ?>
</body>
</html>
