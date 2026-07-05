<?php
/**
 * header.php
 * 認証・データベース連携および共通ヘッダー
 */

// 1. 外部ファイルの読み込み
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php'; 

// 2. セッション開始（auth.phpの関数を使用）
start_sess();

// 3. CSRFトークンを生成
$csrf_token = generate_csrf();

// 4. 既読処理（header.php内で安全に完結）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json_input = file_get_contents('php://input');
    $input = json_decode($json_input, true);

    if (isset($input['action']) && $input['action'] === 'mark_read') {
      // ログイン済みか確認
      login_check();
      $ids = $input['ids'] ?? [];
      foreach ($ids as $id) {
        update_notification_flag((int)$id);
      }
      header('Content-Type: application/json');
      echo json_encode(['status' => 'success']);
      exit; // 処理終了
   }
}

// 5. ユーザー情報とデータの準備
$user_id = $_SESSION['user_id'] ?? null;
$notifications = $user_id ? get_expired_surveys_to_notify($user_id) : [];
$surveys = get_all_survey_titles();
?>

<header class="w-full bg-[#1e3a8a] text-white fixed top-0 left-0 h-16 z-[9999] shadow-lg">
  <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

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
        
        <div id="notificationPopup" class="popup-box absolute top-16 right-0 w-80 p-4 text-gray-800 bg-white border rounded shadow-lg hidden">
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
        <div id="searchPopup" class="popup-box absolute top-16 right-0 w-full max-h-80 overflow-y-auto bg-white border rounded shadow-lg hidden">
          <div id="search-results-container" class="p-2 text-gray-800 text-sm"></div>
        </div>
      </div>
    </div>
  </div>
</header>

<script>
const surveyData = <?php echo json_encode($surveys); ?>;
const notiIds = <?php echo json_encode(array_column($notifications, 'survey_id')); ?>;
const csrfToken = document.getElementById('csrf_token').value;
document.addEventListener('DOMContentLoaded', () => {
  const notiBtn = document.getElementById('notificationBtn');
  const notiPopup = document.getElementById('notificationPopup');
  const closeNotiBtn = document.getElementById('closeNotiBtn');
  const notiCount = document.getElementById('notiCount');
  const searchInp = document.getElementById('survey-search');
  const searchPopup = document.getElementById('searchPopup');
  const resultsContainer = document.getElementById('search-results-container');

  // 通知ボタンクリックで既読処理を実行
  if(notiBtn) {
    notiBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if(!notiPopup.classList.contains('hidden')) {
          notiPopup.classList.add('hidden');
          return;
      }

      if(notiIds.length > 0) {
          fetch('header.php', {
              method: 'POST',
              headers: { 
                  'Content-Type': 'application/json',
                  'X-CSRF-Token': csrfToken // トークンをヘッダーに含める
              },
              body: JSON.stringify({ action: 'mark_read', ids: notiIds })
          }).then(res => res.json()).then(data => {
              if(data.status === 'success' && notiCount) notiCount.classList.add('hidden');
          });
      }
      notiPopup.classList.remove('hidden');
      searchPopup.classList.add('hidden');
    });
  }

  // 検索・UIイベント
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
      const filtered = surveyData.filter(s => s.title.includes(val));
      resultsContainer.innerHTML = filtered.length > 0 
        ? filtered.map(s => `<a href="question.php?id=${s.question_key}" class="p-2 hover:bg-gray-100 cursor-pointer">${s.title}</a>`).join('')
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