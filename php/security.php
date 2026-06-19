<?php
// 禁止文字列の検出，文字数制限違反の発見
// @param string $user_input ユーザ入力等の入力値
// @param int $max_length 文字列制限(デフォルトでは50)
// @return bool True：問題なし
//              False：問題あり
// @notice checkWord関数でエラーがあった場合にはfalseが返値される
//
//require "db.php";
require_once "logger.php";
function checkWord(string $user_input, int $max_length = 50):bool{
    try{
        $normalize = [" ","　",".","．","。",",","，","、"];
        $target = $user_input;//$_POST["taxt"];
        $target = Normalizer::normalize(
            $target,
            Normalizer::FORM_KC
        );//入力の正規化

        $target = preg_replace(
            '/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u',
            '',
            $target
        );//特殊文字の除去
        if (($n = mb_strlen($target)) > (int)$max_length){
            writeLog(__FILE__."::".__FUNCTION__, "WARNING", "文字列長超過:$n(制限:$max_length)");
            return false;
        }
        $target = str_replace($normalize,"",$target);
        $black_list = ["コーヒー","牛乳"];//get_forbidden_words();
        foreach($black_list as $word){
            if(mb_strpos($target,$word) !== false){
                writeLog(__FILE__."::".__FUNCTION__, "WARNING", "不正な入力です:$user_input");
                return false;
            }
        }
        writeLog(__FILE__."::".__FUNCTION__, "INFO", "正常な入力:$user_input");
        return true;//禁止文字なし
    } catch (Throwable $e){
        writeLog(__FILE__."::".__FUNCTION__, "ERROR", "予期しないエラー:".$e->getMessage());
        return false;
    }
}
?>