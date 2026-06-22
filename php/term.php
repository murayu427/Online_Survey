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
    <title>利用規約 - オンラインアンケートサイト</title>
    <link rel="stylesheet" href="../css/reset.css">
    <link rel="stylesheet" href="../css/question.css">
    <link rel="stylesheet" href="../css/footer.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/readability.css">
</head>
<body>
<?php require_once 'header.php'; ?>
<main class="max-w-4xl mx-auto p-6 page-terms">
    <p class="text-sm text-gray-500">最終更新: 2026-06-22</p>
    <h1><strong>利用規約</strong></h1>

    <h2><strong>第1条（目的）</strong></h2>
    <p>本規約は、オンラインアンケートサイト（以下「本サービス」といいます）の利用条件を定めるものです。ユーザーは本サービスを利用することにより、本規約に同意したものとみなされます。</p>


    <h2><strong>第2条（ユーザー登録と管理）</strong></h2>
    <ol>
        <li>本サービスでアンケートの作成、自身の回答の修正、マイページの利用を行うには、ユーザー登録が必要です。</li>
        <li>ユーザーは、登録したアカウント名およびパスワードを自己の責任において厳重に管理するものとします。</li>
        <li>アカウント名およびパスワードの管理不十分、第三者の使用等によって生じた損害について、運営者（開発チーム）は一切の責任を負いません。</li>
    </ol>


    <h2><strong>第3条（禁止事項）</strong></h2>
    <p>ユーザーは、本サービスの利用にあたり、以下の行為をしてはなりません。</p>
    <ul>
        <li>・ 法令または公序良俗に違反する行為</li>
        <li>・ 他のユーザー、第三者、または運営者に不利益や損害を与える行為</li>
        <li>・ 運営者が不適切と判断する用語（NGワード）を含むアンケートやコメントを投稿する行為</li>
        <li>・ システムの不具合を意図的に利用する行為、またはサーバーに過度な負担をかける行為</li>
    </ul>

    <h2><strong>第4条（退会とデータの削除）</strong></h2>
    <ol>
        <li>ユーザーは、マイページの「退会」ボタンからいつでも本サービスを退会することができます。</li>
        <li>退会処理が完了すると、該当ユーザーのアカウント情報、作成したアンケート、回答データ、コメント、および「いいね」の履歴は、データベース上から物理的に一括削除（連鎖削除）され、復元することはできません。</li>
    </ol>

    <h2><strong>第5条（免責事項）</strong></h2>
    <ol>
        <li>本サービスは教育目的の課題作品として稼働しているものであり、運営者は本サービスの完全性、正確性、およびセキュリティ上の完全な安全性を保証するものではありません。</li>
        <li>本サービスは予告なく仕様が変更される、または停止・終了する場合があります。これによりユーザーに生じた損害について、運営者は一切の責任を負いません。</li>
    </ol>

</main>
<?php require_once 'footer.php'; ?>
</body>
</html>
