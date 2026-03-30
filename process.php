<?php
/**
 * نظام Ledger Pro - المحرك البرمجي المحدث (AJAX Version)
 * معالجة البيانات والحسابات الدقيقة مع الهندسة العكسية لرسوم الشراء
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
    $crypto_amount = floatval($_POST['amount'] ?? 0); // الكمية الصافية المستلمة/المرسلة
    $price_per_unit = floatval($_POST['price'] ?? 0);
    $manual_fiat_fee = floatval($_POST['manual_fee'] ?? 0); // رسوم الكريمي/الصراف بالريال

    // ب- معالجة التاريخ
    $transaction_date = !empty($_POST['transaction_date']) ? $_POST['transaction_date'] : date('Y-m-d H:i:s');

    // ج- المنطق المحاسبي الجديد المطابق لبينانس (دقة 100%)
    $total_fiat_paid = 0; 
    $total_crypto_impact = 0;
    $binance_fee = 0;

    if ($type == 'sell') {
        /**
         * حالة البيع (Sell):
         * كما في صورتك (51.06 USDT)، بينانس تضيف الرسوم (0.05) فوقها.
         * الرسوم اليدوية (إذا أدخلتها) أو 0.1% تلقائياً
         */
        if (isset($_POST['binance_fee']) && $_POST['binance_fee'] !== '') {
            $binance_fee = floatval($_POST['binance_fee']);
        } else {
            $binance_fee = $crypto_amount * 0.001;
        }
        
        $total_crypto_impact = $crypto_amount + $binance_fee; // ما يخصم من محفظتك
        $total_fiat_paid = $crypto_amount * $price_per_unit; // ما تستلمه كاش
        $manual_fee_final = 0;

    } else {
        /**
         * حالة الشراء (Buy) - (حل المشكلة التي ذكرتها يابطل):
         * إذا أدخلت 119.83، يجب أن نعرف أن الإجمالي قبل الخصم كان 119.949...
         * المعادلة: الإجمالي = الصافي ÷ 0.999
         */
        
        if (isset($_POST['binance_fee']) && $_POST['binance_fee'] !== '' && floatval($_POST['binance_fee']) >= 0) {
            // إذا المستخدم أدخل الرسوم يدوياً (مثلاً 0 أو قيمة مخصصة)
            $binance_fee = floatval($_POST['binance_fee']);
            $gross_crypto = $crypto_amount + $binance_fee;
        } else {
            // تلقائياً: الهندسة العكسية لرسوم بينانس
            $gross_crypto = $crypto_amount / 0.999;
            $binance_fee = $gross_crypto - $crypto_amount;
        }

        $total_crypto_impact = $crypto_amount; // ما دخل محفظتك فعلياً (الصافي)
        // الإجمالي بالريال = (الكمية قبل الخصم × السعر) + رسوم الصراف اليدوية
        $total_fiat_paid = ($gross_crypto * $price_per_unit) + $manual_fiat_fee;
        $manual_fee_final = $manual_fiat_fee;
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
            $crypto_amount, // الكمية التي أدخلها المستخدم (الصافي)
            $price_per_unit,
            $binance_fee,
            $manual_fee_final,
            $total_fiat_paid,
            $total_crypto_impact,
            $transaction_date
        ]);

        // 5. الرد بنجاح بنظام AJAX
        echo json_encode([
            'status' => 'success',
            'message' => 'تم حفظ العملية بدقة بينانس!',
            'last_date' => $transaction_date
        ]);
        exit();

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'خطأ في الحفظ: ' . $e->getMessage()
        ]);
        exit();
    }

} else {
    echo json_encode(['status' => 'error', 'message' => 'طلب غير صالح']);
    exit();
}