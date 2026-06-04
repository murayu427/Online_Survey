<?php
// セッション開始
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php'; 

// --- 既読処理の統合（JavaScriptからのリクエストをここで受け取る） ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_read') {
    // リクエストボディからIDリストを取得
    $input = json_decode(file_get_contents('php://input'), true);
    $ids = $input['ids'] ?? [];
    
    // データベースのフラグを更新
    foreach ($ids as $id) {
        update_notification_flag((int)$id);
    }
    exit; // 処理後はHTMLを出力させず終了する
}
// ------------------------------------------------------------

$user_id = $_SESSION['user_id'] ?? null;
$notifications = [];
$surveys = get_all_survey_titles();

// ログインしている場合、期限切れかつ未通知のアンケートを取得
if ($user_id) {
    $notifications = get_expired_surveys_to_notify($user_id);  //データベースから得られる通知すべきアンケート
}
?>

<header class="w-full bg-[#1e3a8a] text-white fixed top-0 left-0 h-16 z-[9999] shadow-lg">
  <div class="max-w-6xl mx-auto h-full flex items-center justify-between px-6">
    <div class="flex items-center gap-4">
      <a href="index.php" class="text-2xl hover:text-blue-300 transition-colors"><i class="fa-solid fa-house"></i></a>
      <span class="font-bold text-lg tracking-wider">村上製作所</span>
    </div>

    <div class="flex items-center gap-6">
      <?php if ($user_id): ?>
      <div class="relative">
        <button id="notificationBtn" class="text-2xl hover:text-blue-300 transition-colors relative">
          <i class="fa-solid fa-bell"></i>
          <?php if (count($notifications) > 0): ?>
            <span id="notiCount" class="absolute -top-1 -right-1 bg-red-500 text-[10px] px-1.5 rounded-full">
              <?php echo count($notifications); ?>
            </span>
          <?php endif; ?>
        </button>
        
        <div id="notificationPopup" class="popup-box top-12 right-0 w-80 p-4 text-gray-800 bg-white border rounded shadow-lg hidden">
          <div class="flex justify-between items-center border-b pb-2 mb-2">
            <h3 class="font-bold text-blue-900">通知一覧</h3>
            <button id="closeNotiBtn" class="text-gray-500 hover:text-gray-800 text-xl">&times;</button>
          </div>
          <ul id="notificationList" class="text-sm space-y-2">
            <?php if (count($notifications) > 0): ?>
                <?php foreach ($notifications as $n): ?>
                    <li class="border-b pb-2">・「<?php echo htmlspecialchars($n['title']); ?>」の募集が終了しました。</li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="text-gray-400 italic">通知はありません。</li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
      <?php endif; ?>

      <div class="relative w-64">
        <input type="text" id="survey-search" placeholder="アンケート検索" class="w-full py-2 pl-10 pr-4 rounded text-gray-800 outline-none">
        <div id="searchPopup" class="popup-box top-12 right-0 w-full max-h-80 overflow-y-auto bg-white border rounded shadow-lg hidden">
          <div id="search-results-container" class="p-2 text-gray-800 text-sm"></div>
        </div>
      </div>
    </div>
  </div>
</header>

<script>
const surveyData = <?php echo json_encode($surveys); ?>;
const notiIds = <?php echo json_encode(array_column($notifications, 'survey_id')); ?>;

document.addEventListener('DOMContentLoaded', () => {
  const notiBtn = document.getElementById('notificationBtn');
  const notiPopup = document.getElementById('notificationPopup');
  const closeNotiBtn = document.getElementById('closeNotiBtn');
  const notiCount = document.getElementById('notiCount');
  const searchInp = document.getElementById('survey-search');
  const searchPopup = document.getElementById('searchPopup');
  const resultsContainer = document.getElementById('search-results-container');

  if(notiBtn) {
    notiBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      // 通知ボタン押下時に自分自身（header.php）へ既読命令を送る
      if(notiPopup.classList.contains('hidden') && notiIds.length > 0) {
          fetch('header.php', {
              method: 'POST',
              headers: {'Content-Type': 'application/x-www-form-urlencoded'},
              body: 'action=mark_read&ids=' + encodeURIComponent(JSON.stringify({ ids: notiIds }))
          }).then(() => {
              if(notiCount) notiCount.classList.add('hidden');
          });
      }
      notiPopup.classList.toggle('hidden');
      searchPopup.classList.add('hidden');
    });
  }

  if(closeNotiBtn) {
    closeNotiBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      notiPopup.classList.add('hidden');
    });
  }

  searchInp.addEventListener('input', (e) => {
    const val = e.target.value.trim();
    if(val) {
      searchPopup.classList.remove('hidden');
      if(notiPopup) notiPopup.classList.add('hidden');
      const filtered = surveyData.filter(s => s.includes(val));
      resultsContainer.innerHTML = filtered.length > 0 
        ? filtered.map(s => `<div class="p-2 hover:bg-gray-100 cursor-pointer">${s}</div>`).join('')
        : '<div class="p-2 text-gray-400">該当なし</div>';
    } else {
      searchPopup.classList.add('hidden');
    }
  });

  document.addEventListener('click', () => {
    if(notiPopup) notiPopup.classList.add('hidden');
    searchPopup.classList.add('hidden');
  });
});
</script>