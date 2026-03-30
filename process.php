<?php
/**
 * نظام Ledger Pro - المحرك البرمجي المحدث (AJAX Version)
 * معالجة البيانات والحسابات الدقيقة مع ردود JSON للإشعارات المنبثقة
 */

session_start();
require_once 'db.php';

// 1. ضبط توقيت السيرفر لليمن (GMT+3)
date_default_timezone_set('Asia/Aden');

// تجهيز نوع الرد ليكون JSON دائماً
header('Content-Type: application/json');

// 2. حماية الملف: التأكد من تسجيل دخول المستخدم
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'انتهت الجلسة، يرجى تسجيل الدخول']);
    exit();
}

$user_id = $_SESSION['user_id'];

// 3. التحقق من إرسال البيانات
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['amount'])) {
    
    // أ- استلام البيانات وتحويلها لـ Float
    $type = $_POST['type']; 
    $crypto_amount = floatval($_POST['amount'] ?? 0);
    $price_per_unit = floatval($_POST['price'] ?? 0);
    $manual_fee = floatval($_POST['manual_fee'] ?? 0);
    
    // ب- معالجة الرسوم (يدوية من الحقل أو تلقائية 0.1%)
    if (isset($_POST['binance_fee']) && $_POST['binance_fee'] !== '') {
        $binance_fee = floatval($_POST['binance_fee']);
    } else {
        $binance_fee = $crypto_amount * 0.001;
    }

    // ج- معالجة التاريخ (يدوي أو تلقائي)
    $transaction_date = !empty($_POST['transaction_date']) ? $_POST['transaction_date'] : date('Y-m-d H:i:s');

    // د- المنطق المحاسبي (مطابق تماماً لصور بينانس):
    $total_fiat_paid = 0; 
    $total_crypto_impact = 0;

    if ($type == 'sell') {
        // حالة البيع: التأثير = الكمية + الرسوم
        $total_crypto_impact = $crypto_amount + $binance_fee;
        $total_fiat_paid = $crypto_amount * $price_per_unit;
        $manual_fee_final = 0;
    } else {
        // حالة الشراء: التأثير = الكمية - الرسوم
        $total_crypto_impact = $crypto_amount - $binance_fee;
        $total_fiat_paid = ($crypto_amount * $price_per_unit) + $manual_fee;
        $manual_fee_final = $manual_fee;
    }

    // 4. تنفيذ عملية الحفظ
    try {
        $sql = "INSERT INTO transactions (
                    user_id, 
                    type, 
                    crypto_amount, 
                    price_per_unit, 
                    currency, 
                    binance_fee, 
                    manual_fee, 
                    total_fiat_paid, 
                    total_crypto_deducted, 
                    created_at
                ) VALUES (?, ?, ?, ?, 'YER', ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id,
            $type,
            $crypto_amount,
            $price_per_unit,
            $binance_fee,
            $manual_fee_final,
            $total_fiat_paid,
            $total_crypto_impact,
            $transaction_date
        ]);

        // 5. الرد بنجاح (بدون تحديث الصفحة)
        echo json_encode([
            'status' => 'success',
            'message' => 'تم حفظ العملية بنجاح!',
            'last_date' => $transaction_date
        ]);
        exit();

    } catch (Exception $e) {
        // الرد في حال حدوث خطأ
        echo json_encode([
            'status' => 'error', 
            'message' => 'حدث خطأ في قاعدة البيانات: ' . $e->getMessage()
        ]);
        exit();
    }

} else {
    echo json_encode(['status' => 'error', 'message' => 'طلب غير صالح']);
    exit();
}