<?php
require_once __DIR__ . '/../php/db.php';
$json = file_get_contents('badword.json');
$data = json_decode($json, true);

$stmt = $pdo->prepare(
    "INSERT INTO forbidden_words(word)
        VALUES(:word)
        ON CONFLICT (word) DO NOTHING"
);

foreach ($data as $category => $words) {
    foreach ($words as $word) {
        $stmt->execute([
            ':word' => trim($word)
        ]);
    }
}
?>

