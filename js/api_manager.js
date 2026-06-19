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
    const API_ENDPOINT = '/php/api.php';
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
    formData.append('csrf_token', token);

    // 3. 通信実行
    try {
        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': token }
        });

        if (!response.ok) {
            const contentType = response.headers.get('content-type') || '';
            let msg = `通信エラー: ${response.status}`;
            if (contentType.includes('application/json')) {
                const err = await response.json().catch(() => null);
                if (err && err.message) msg = err.message;
            }
            alert(msg);
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
    const API_ENDPOINT = '/php/api.php';
    const formData = new FormData();
    formData.append('action', 'like');
    formData.append('comment_id', commentId);

    const token = getCsrfToken();
    if (!token) {
        console.warn('CSRF token missing; like aborted');
        return;
    }
    formData.append('csrf_token', token);

    try {
        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': token }
        });

        if (!response.ok) {
            console.warn('いいね通信エラー', response.status);
            return;
        }

        const data = await response.json();

        if (data.status === 'success') {
            // いいねの数字を表示している<span>の中身を、サーバーから返ってきた最新の数字で上書き
            const countElement = document.getElementById(`like-count-${commentId}`);
            if (countElement) {
                countElement.textContent = data.total_likes;
            }

            // キリ番などの条件を満たした場合、音声演出を実行
            if (data.play_voice) {
                const audio = new Audio('assets/iidesune_doukome.mp3');
                audio.play().catch(e => console.warn('音声再生がブラウザにブロックされました', e));
            }
        }
    } catch (error) {
        console.error('いいね処理エラー:', error);
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

    const API_ENDPOINT = '/php/api.php';
    const formData = new FormData();
    formData.append('action', 'save');
    formData.append('type', type);
    formData.append('payload', JSON.stringify(formDataObj));

    const token = getCsrfToken();
    if (!token) {
        console.debug('AutoSave: CSRF token not found; skipping silent save');
        return;
    }
    formData.append('csrf_token', token);

    try {
        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': token }
        });

        if (!response.ok) {
            console.debug('Silent Save failed, server error', response.status);
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