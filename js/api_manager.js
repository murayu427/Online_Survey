/**
 * ------------------------------------------------------------------------
 * 連携APIクライアント (JavaScript -> api.php)
 * ------------------------------------------------------------------------
 * このファイルは、画面からサーバー（api.php）へデータを送受信するための共通処理です。
 * HTML側からこれらの関数を呼び出すだけで、裏側で勝手に通信と画面更新が行われます。
 *
 * 【重要】このファイルを読み込むHTMLには、以下のIDを持つ要素を用意してください。
 * - コメント入力欄: id="comment-text-area"
 * - コメント一覧の枠: id="comment-list"
 * - アンケートIDを持つ隠し項目: id="current-survey-id" value="UUID..."
 * - 保存状態の表示欄: id="save-status"
 * - アンケートフォーム本体: id="main-form"
 * ------------------------------------------------------------------------
 */

const pendingLikeRequests = new Map();

function getApiEndpoint() {
    const currentUrl = new URL(window.location.href);
    const basePath = currentUrl.pathname.replace(/[^/]*$/, '');

    const candidates = [];
    const thisDir = currentUrl.pathname.replace(/[^/]*$/, '');
    if (thisDir.endsWith('/php/')) {
        candidates.push(new URL('./api.php', currentUrl.href).toString());
    }
    candidates.push(new URL('../php/api.php', currentUrl.href).toString());
    candidates.push(new URL('./php/api.php', currentUrl.href).toString());
    candidates.push(new URL('/php/api.php', currentUrl.href).toString());
    candidates.push(new URL('/api.php', currentUrl.href).toString());

    return candidates.filter((value, index, array) => array.indexOf(value) === index)[0];
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

async function readErrorResponse(response) {
    const contentType = (response.headers.get('content-type') || '').toLowerCase();
    try {
        if (contentType.includes('application/json')) {
            const parsed = await response.json().catch(() => null);
            if (parsed) {
                return parsed.message || parsed.error || parsed.detail || JSON.stringify(parsed);
            }
        }

        const text = await response.text().catch(() => '');
        if (text) {
            return text.replace(/\s+/g, ' ').trim().slice(0, 800);
        }
    } catch (error) {
        return `レスポンスの読み取りに失敗しました: ${error.message}`;
    }

    return '';
}

function showApiError(message, detail = '', endpoint = '') {
    const detailsText = [detail, endpoint ? `送信先: ${endpoint}` : '']
        .filter(Boolean)
        .join('\n\n');
    const fullMessage = detailsText ? `${message}\n\n${detailsText}` : message;

    console.error('[API Error]', fullMessage);

    const existing = document.getElementById('api-error-banner');
    if (existing) existing.remove();

    const banner = document.createElement('div');
    banner.id = 'api-error-banner';
    banner.style.cssText = 'margin:12px 0;padding:12px 14px;border:1px solid #dc2626;background:#fee2e2;color:#991b1b;border-radius:8px;white-space:pre-wrap;font-size:14px;line-height:1.5;';
    banner.innerHTML = `<strong>通信エラー</strong><br>${escapeHtml(message)}${detailsText ? `<br><br><span style="font-family:monospace">${escapeHtml(detailsText)}</span>` : ''}`;

    const target = document.getElementById('comment-list') || document.getElementById('save-status') || document.querySelector('main');
    if (target) {
        target.insertAdjacentElement('beforebegin', banner);
    }

    alert(fullMessage);
}

function setLikeButtonPending(commentId, pending) {
    const countElement = document.getElementById(`like-count-${commentId}`);
    const button = countElement ? countElement.closest('button') : null;
    if (!button) return;

    button.disabled = pending;
    button.classList.toggle('opacity-60', pending);
    button.classList.toggle('cursor-not-allowed', pending);
    button.setAttribute('aria-busy', pending ? 'true' : 'false');
}

/**
 * ① コメント投稿処理
 * 概要：テキストエリアの文字を取得し、api.phpに送信。成功すればHTML要素として追記します。
 * 使い方：投稿ボタンの onclick="postComment()" で呼び出してください。
 */
async function postComment() {
    // 1. HTMLから必要なデータを取得
    const textInput = document.getElementById('comment-text-area');
    const text = textInput.value.trim();
    const surveyIdInput = document.getElementById('current-survey-id');

    // エラーハンドリング：空送信の防止
    if (!text) {
        alert("コメントを入力してください。");
        return;
    }
    if (!surveyIdInput) {
        console.error("システムエラー: アンケートID(current-survey-id)が見つかりません。");
        return;
    }

    // 2. api.phpへ送るデータの梱包
    const API_ENDPOINT = getApiEndpoint();
    const formData = new FormData();
    formData.append('action', 'comment');
    formData.append('survey_id', surveyIdInput.value);
    formData.append('text', text);

    // CSRF トークンを付与（meta / hidden input / form 内の順で探索）
    const token = getCsrfToken();
    if (!token) {
        alert('セッション情報が見つかりません。ページを再読み込みしてください。');
        return;
    }
    // CSRF はヘッダで送信（サーバ側はヘッダを優先して確認します）

    // 3. 通信実行
    try {
        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': token,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        if (!response.ok) {
            const errorDetail = await readErrorResponse(response);
            showApiError(`コメント投稿に失敗しました。(${response.status} ${response.statusText || ''})`, errorDetail, API_ENDPOINT);
            return;
        }

        const data = await response.json();

        // 4. 通信成功時の画面更新処理
        if (data.status === 'success') {
            // サーバーから送られてきたHTML(コメント部品)を、リストの末尾に挿入
            const commentList = document.getElementById('comment-list');
            if (commentList && data.comment_html) {
                commentList.insertAdjacentHTML('beforeend', data.comment_html);
            }
            // 連続投稿できるように入力欄を空に戻す
            textInput.value = '';
        } else {
            // NGワード等、サーバー側で弾かれた場合のメッセージを表示
            alert(data.message || '投稿に失敗しました。');
        }
    } catch (error) {
        console.error('通信エラー:', error);
        alert('サーバーとの通信に失敗しました。');
    }
}

/**
 * ② コメントへの「いいね」処理
 * 概要：対象コメントのいいね数を切り替え（トグル）、最新の件数で画面を書き換えます。
 * 使い方：各コメントのいいねボタン onclick="toggleLike('コメントのUUID')" で呼び出してください。
 */
async function toggleLike(commentId) {
    if (!commentId) return;

    const requestKey = String(commentId);
    if (pendingLikeRequests.has(requestKey)) {
        return;
    }

    pendingLikeRequests.set(requestKey, true);
    setLikeButtonPending(requestKey, true);

    const API_ENDPOINT = getApiEndpoint();
    const formData = new FormData();
    formData.append('action', 'like');
    formData.append('comment_id', commentId);

    const token = getCsrfToken();
    if (!token) {
        console.warn('CSRF token missing; like aborted');
        pendingLikeRequests.delete(requestKey);
        setLikeButtonPending(requestKey, false);
        return;
    }
    // CSRF はヘッダで送信

    try {
        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': token,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        if (!response.ok) {
            const errorDetail = await readErrorResponse(response);
            showApiError(`いいね処理に失敗しました。(${response.status} ${response.statusText || ''})`, errorDetail, API_ENDPOINT);
            return;
        }

        const data = await response.json();

        if (data.status === 'success') {
            // いいねの数字を表示している<span>の中身を、サーバーから返ってきた最新の数字で上書き
            const countElement = document.getElementById(`like-count-${commentId}`);
            if (countElement) {
                const parsedCount = Number.parseInt(data.total_likes, 10);
                if (Number.isFinite(parsedCount)) {
                    countElement.textContent = String(parsedCount);
                }
            }

            // キリ番などの条件を満たした場合、音声演出を実行
            if (data.play_voice) {
                const audio = new Audio(new URL('../assets/iidesune_doukome.mp3', window.location.href).toString());
                audio.play().catch(e => console.warn('音声再生がブラウザにブロックされました', e));
            }
        }
    } catch (error) {
        console.error('いいね処理エラー:', error);
    } finally {
        pendingLikeRequests.delete(requestKey);
        setLikeButtonPending(requestKey, false);
    }
}
/**
 * ③ リアルタイム保存（サイレント・オートセーブ）
 * 概要：ユーザーに通知することなく、裏側でこっそりセッションへ同期します。
 * 意図：UXを邪魔せず、ブラウザが落ちた際などの「最悪の事態」を回避するための保険です。
 */
async function autoSave(type) {
    const formElement = document.getElementById('main-form');
    if (!formElement) return;
    // フォームの内容をまるごと取得（配列対応）
    const fd = new FormData(formElement);
    const questionIdInput = formElement.querySelector('input[name="question_id"]');
    const formDataObj = {};
    for (const [k, v] of fd.entries()) {
        // name が配列形式（例: q0[]）の場合は末尾の [] を取り除いて正規化
        const name = k.endsWith('[]') ? k.slice(0, -2) : k;
        if (Object.prototype.hasOwnProperty.call(formDataObj, name)) {
            if (!Array.isArray(formDataObj[name])) formDataObj[name] = [formDataObj[name]];
            formDataObj[name].push(v);
        } else {
            formDataObj[name] = v;
        }
    }

    const API_ENDPOINT = getApiEndpoint();
    const formData = new FormData();
    formData.append('action', 'save');
    formData.append('type', type);
    if (questionIdInput && questionIdInput.value) {
        formData.append('question_id', questionIdInput.value);
    }
    formData.append('payload', JSON.stringify(formDataObj));

    const token = getCsrfToken();
    if (!token) {
        console.debug('AutoSave: CSRF token not found; skipping silent save');
        return;
    }
    // CSRF はヘッダで送信

    try {
        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': token,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        if (!response.ok) {
            const errorDetail = await readErrorResponse(response);
            console.debug('Silent Save failed', response.status, errorDetail);
            return;
        }

        const data = await response.json().catch(() => null);
        if (data && data.status === 'success' && data.saved_at) {
            const saveStatus = document.getElementById('save-status');
            if (saveStatus) saveStatus.textContent = `Saved at ${data.saved_at}`;
        }

    } catch (error) {
        // 開発時のデバッグ用にコンソールにだけ残しておく
        console.error('Silent Save Error (Backend only):', error);
    }
}

/**
 * CSRF トークンを DOM から探索して返す。存在しない場合は null を返す。
 */
function getCsrfToken() {
    // meta tag を優先
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;

    // id ベースの隠し input
    const byId = document.getElementById('csrf_token');
    if (byId && byId.value) return byId.value;

    // フォーム内の input[name="csrf_token"]
    const form = document.getElementById('main-form');
    if (form) {
        const input = form.querySelector('input[name="csrf_token"]');
        if (input && input.value) return input.value;
    }

    // グローバル変数として埋められている場合
    if (typeof window.CSRF_TOKEN !== 'undefined') return window.CSRF_TOKEN;

    return null;
}

/**
 * ------------------------------------------------------------------------
 * 自動保存の監視設定
 * ------------------------------------------------------------------------
 */
document.addEventListener('DOMContentLoaded', () => {
    const formElement = document.getElementById('main-form');
    if (formElement) {
        let saveTimer;

        formElement.addEventListener('input', () => {

            // デバウンス処理　入力が止まってから1秒後に1回だけ送信
            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => {
                autoSave('answer');
            }, 1000); 
        });
    }
});