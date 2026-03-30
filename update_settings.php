<?php
require_once 'db.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $buy_p = floatval($_POST['default_buy_price']);
    $sell_p = floatval($_POST['default_sell_price']);

    // تحديث إعدادات المستخدم الحالي فقط
    $pdo->prepare("UPDATE settings SET default_buy_price = ?, default_sell_price = ? WHERE user_id = ?")
        ->execute([$buy_p, $sell_p, $user_id]);

    header("Location: index.php?updated=1");
}
