<?php
require_once __DIR__ . '/error.php';

/*====================
セッション開始関数
====================*/
function start_sess(){
    //セッションが開始されていない場合は開始する
    if(session_status() === PHP_SESSION_NONE){
        ini_set('session.gc_maxlifetime', 3600); // サーバ側の有効期限を1時間に設定する
        ini_set('session.use_strict_mode', 1); // セッションIDの固定攻撃を防止

         // 現在の接続がHTTPSかどうかを判定(HTTPSの時trueが返る)
        $is_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        // クッキーの設定をまとめて指定
        session_set_cookie_params([
            'lifetime' => 3600,                     // クッキーの有効期限（1時間）
            'path' => '/',                          // サイト全体で有効
            'domain' => '',                         // 現在のドメイン
            'secure' => $is_https,                  // HTTPSの時だけSecure属性をオンにする
            'httponly' => true,                     // JavaScriptからのクッキーアクセスを禁止
            'samesite' => 'Strict'                   // クロスサイトリクエストを防止
        ]);

        session_start();
    }

    // セッションの有効期限を更新
    if(session_status() === PHP_SESSION_ACTIVE){
        $_SESSION['last_acc'] = time();
    }
}

/*====================
セッション破棄関数
====================*/
function del_sess(){
    //セッションが開始されていない場合は開始する
    if(session_status() == PHP_SESSION_NONE){
        start_sess();
    }

    //セッション変数をすべて空にする
    $_SESSION = array();

    //ブラウザのセッションクッキーを削除する
    if(ini_get("session.use_cookies")){
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    //セッションidを破棄する
    session_destroy();
}


/*====================
ログインチェック関数
====================*/
function login_check(){
    //セッションが開始されていない場合は開始する
    if(session_status() == PHP_SESSION_NONE){
        start_sess();
    }

    //セッションにユーザーIDが保存されているか確認する
    if(!isset($_SESSION['user_id'])){
        //元のURLを保存してログインページにリダイレクトする
        $_SESSION['return_to'] = $_SERVER['REQUEST_URI']; 
        header('Location: ../php/signin.php');
        exit();
    }

    //タイムアウトのチェック
    if(isset($_SESSION['last_acc'])){
        $timeout = 30 * 60; // 30分
        if(time() - $_SESSION['last_acc'] > $timeout){
            //セッションを破棄してログインページにリダイレクトする
            del_sess();
            $_SESSION['return_to'] = $_SERVER['REQUEST_URI'];
            header('Location: ../php/signin.php');
            exit();
        }
    }
}

/*====================
CSRFトークン生成関数
====================*/
function generate_csrf(){
    //セッションが開始されていない場合は開始する
    if(session_status() == PHP_SESSION_NONE){
        start_sess();
    }

    if(empty($_SESSION['csrf_token'])){
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/*====================
CSRFトークンチェック関数
====================*/
/* 呼び出すときは
check_csrf($_POST['csrf_token'])のように、フォームから送られてきたトークンを引数に渡す
*/
function check_csrf($token){
    //セッションが開始されていない場合は開始する
    if(session_status() == PHP_SESSION_NONE){
        start_sess();
    }

    //セッションに保存されたCSRFトークンと一致するか確認する
    if(isset($_SESSION['csrf_token']) && !hash_equals($_SESSION['csrf_token'], $token)){
        
        unset($_SESSION['csrf_token']);// トークンが不正な場合はセッションから削除して新しいトークンを生成する
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // 新しいトークンを生成して保存する
        renderError('不正なリクエストです。もう一度お試しください。', 400, 'app', 'WARNING');
    }

}