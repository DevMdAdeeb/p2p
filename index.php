<?php 
session_start();
require_once 'db.php'; 

// 1. ضبط التوقيت لليمن (GMT+3) لضمان دقة العمليات الحالية واليومية
date_default_timezone_set('Asia/Aden');
$pdo->exec("SET time_zone = '+03:00'");
$today = date('Y-m-d');

// حماية الصفحة: التأكد من تسجيل الدخول وتحديد هوية المستخدم
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id']; 
$username = $_SESSION['username'] ?? 'مستخدم';

// --- جلب البيانات الخاصة بالمستخدم الحالي فقط ---

// جلب إعدادات الأسعار الافتراضية
$settings_stmt = $pdo->prepare("SELECT default_buy_price, default_sell_price FROM settings WHERE user_id = ?");
$settings_stmt->execute([$user_id]);
$settings = $settings_stmt->fetch();
$def_buy = $settings['default_buy_price'] ?? 535;
$def_sell = $settings['default_sell_price'] ?? 540;

// حساب متوسط الشراء (WAC) بدقة Float
$buy_stats_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid) as total_spent, SUM(crypto_amount) as total_bought FROM transactions WHERE user_id = ? AND type='buy'");
$buy_stats_stmt->execute([$user_id]);
$buy_stats = $buy_stats_stmt->fetch();
$avg_buy_price = ($buy_stats['total_bought'] > 0) ? ($buy_stats['total_spent'] / $buy_stats['total_bought']) : 0;

// أ. حساب الأرباح التراكمية (ريال ودولار)
$profit_stmt = $pdo->prepare("SELECT SUM((price_per_unit - ?) * crypto_amount) as net_profit FROM transactions WHERE user_id = ? AND type='sell'");
$profit_stmt->execute([$avg_buy_price, $user_id]);
$total_profit_yer = $profit_stmt->fetchColumn() ?: 0;
$total_profit_usd = ($def_buy > 0) ? ($total_profit_yer / $def_buy) : 0;

// ب. حساب حجم التداول اليومي والمخزون
$daily_buy_vol_stmt = $pdo->prepare("SELECT SUM(crypto_amount) FROM transactions WHERE user_id = ? AND type='buy' AND DATE(created_at) = ?");
$daily_buy_vol_stmt->execute([$user_id, $today]);
$daily_buy_vol = $daily_buy_vol_stmt->fetchColumn() ?: 0;

$daily_sell_vol_stmt = $pdo->prepare("SELECT SUM(crypto_amount) FROM transactions WHERE user_id = ? AND type='sell' AND DATE(created_at) = ?");
$daily_sell_vol_stmt->execute([$user_id, $today]);
$daily_sell_vol = $daily_sell_vol_stmt->fetchColumn() ?: 0;

$total_in = $pdo->prepare("SELECT SUM(total_crypto_deducted) FROM transactions WHERE user_id = ? AND type='buy'");
$total_in->execute([$user_id]);
$sum_in = $total_in->fetchColumn() ?: 0;

$total_out = $pdo->prepare("SELECT SUM(total_crypto_deducted) FROM transactions WHERE user_id = ? AND type='sell'");
$total_out->execute([$user_id]);
$sum_out = $total_out->fetchColumn() ?: 0;
$remaining_stock = $sum_in - $sum_out;

// ج. حسابات اليوم الجديدة (أرباح ورسوم اليوم فقط)
$daily_profit_stmt = $pdo->prepare("SELECT SUM((price_per_unit - ?) * crypto_amount) FROM transactions WHERE user_id = ? AND type='sell' AND DATE(created_at) = ?");
$daily_profit_stmt->execute([$avg_buy_price, $user_id, $today]);
$daily_profit_yer_val = $daily_profit_stmt->fetchColumn() ?: 0;
$daily_profit_usd_val = ($def_buy > 0) ? ($daily_profit_yer_val / $def_buy) : 0;

$daily_fees_stmt = $pdo->prepare("SELECT SUM(binance_fee) FROM transactions WHERE user_id = ? AND DATE(created_at) = ?");
$daily_fees_stmt->execute([$user_id, $today]);
$daily_fees_usdt_val = $daily_fees_stmt->fetchColumn() ?: 0;
$daily_fees_yer_val = $daily_fees_usdt_val * $def_buy;

// د. جلب العمليات التاريخية
$total_fees_stmt = $pdo->prepare("SELECT SUM(binance_fee) FROM transactions WHERE user_id = ?");
$total_fees_stmt->execute([$user_id]);
$total_fees_usdt = $total_fees_stmt->fetchColumn() ?: 0;

$stmt = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 500");
$stmt->execute([$user_id]);
$transactions = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ledger Pro | المحاسب الذكي</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
<style>
    /* استيراد خط تجوال - الخط المفضل للواجهات الفخمة */
    @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap');

    :root {
        --bg-main: #0a0f1c;      /* أسود ملكي عميق */
        --bg-card: #151b2d;      /* رمادي كربوني للبطاقات */
        --accent-gold: #eab308;  /* لون بينانس الذهبي */
        --border-color: #242f48; /* لون الحدود الهادئ */
        --radius: 6px;           /* حواف تقارب 4px كما طلبت لإعطاء شكل هندسي */
    }

    body { 
        font-family: 'Tajawal', sans-serif; 
        background-color: var(--bg-main); 
        color: #e2e8f0; 
        line-height: 1.6; 
        scroll-behavior: smooth;
        /* منع الخط المائل في كل الموقع */
        font-style: normal !important; 
    }

    /* إلغاء أي تنسيق مائل قديم من Tailwind أو غيره */
    * { font-style: normal !important; }

    /* تحسين البطاقات (Glassmorphism المطوّر) */
    .glass-card { 
        background: var(--bg-card); 
        border: 1px solid var(--border-color);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
        border-radius: var(--radius) !important;
        transition: transform 0.2s ease, border-color 0.2s ease;
    }

    .glass-card:hover {
        border-color: #334155;
    }

    /* تنسيق الحقول بشكل فخم وهندسي */
    .input-dark { 
        background-color: #0d1220; 
        border: 1px solid var(--border-color); 
        color: white; 
        padding: 12px; 
        border-radius: var(--radius) !important; 
        width: 100%; 
        font-size: 15px; 
        transition: all 0.3s ease;
    }

    .input-dark:focus { 
        border-color: var(--accent-gold); 
        outline: none; 
        box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.1);
        background-color: #111827;
    }

    /* أزرار فخمة */
    .btn-yellow { 
        background: linear-gradient(135deg, #facc15 0%, #eab308 100%);
        color: #0f172a; 
        font-weight: 700; 
        border-radius: var(--radius) !important; 
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .btn-yellow:hover { 
        filter: brightness(1.1);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(234, 179, 8, 0.3);
    }

    .btn-yellow:active { transform: translateY(0); }

    /* تحسين الجدول - طابع بنكي */
    .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: var(--accent-gold); }

    table thead th {
        background-color: #1e293b;
        color: #94a3b8;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 1px;
        border-bottom: 2px solid var(--border-color);
    }

    table tbody tr {
        border-bottom: 1px solid var(--border-color);
        transition: background 0.2s;
    }

    table tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.02);
    }

    /* الإشعار المنبثق (Toast) المطور */
/* تنسيق الإشعار المطور */
#toast { 
    transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55); 
    transform: translate(-50%, 100px); /* يبدأ من الأسفل خارج الشاشة */
}

#toast.show { 
    visibility: visible;
    opacity: 1;
    transform: translate(-50%, 0); /* يرتفع للأعلى */
}

    /* تحسين التنقل (Top Bar) */
    nav {
        border-radius: 0 0 var(--radius) var(--radius) !important;
        border-bottom: 2px solid var(--accent-gold) !important;
    }

    /* البطاقات اليومية - لمسات ضوئية */
    .daily-card {
        position: relative;
        overflow: hidden;
    }
    .daily-card::after {
        content: "";
        position: absolute;
        top: 0; right: 0; width: 4px; height: 100%;
    }
    .card-buy::after { background: #3b82f6; }
    .card-sell::after { background: #10b981; }
    .card-fee::after { background: #f43f5e; }

    /* العناوين والأرقام */
    h1, h2, h3 { 
        letter-spacing: -0.5px; 
        font-weight: 900;
    }

    .tabular-nums {
        font-family: 'Courier New', monospace; /* للأرقام المالية لتبقى متساوية العرض */
        font-weight: 700;
    }

    @media (max-width: 640px) {
        .text-responsive { font-size: 0.7rem; }
        .input-dark { padding: 10px; font-size: 14px; }
    }
</style>
</head>
<body class="p-3 md:p-6 pb-20">

    <!-- الإشعار المنبثق (Toast) -->
    <div id="toast" class="fixed bottom-10 left-1/2 -translate-x-1/2 z-[100] bg-emerald-600 text-white px-8 py-4 rounded-2xl shadow-2xl font-bold flex items-center gap-3 border border-emerald-400/30">
        <i data-lucide="check-circle"></i> <span id="toast-msg">تم الحفظ بنجاح!</span>
    </div>

    <!-- شريط التنقل العلوي -->
    <nav class="max-w-6xl mx-auto bg-[#1e293b]/50 border-b border-slate-800 py-3 px-4 md:px-8 flex justify-between items-center mb-8 rounded-b-2xl glass-card">
        <div class="flex items-center gap-3 group">
            <div class="w-10 h-10 bg-yellow-500/10 rounded-full flex items-center justify-center border border-yellow-500/20 group-hover:scale-110 transition">
                <i data-lucide="user-lock" class="w-6 h-6 text-yellow-500"></i>
            </div>
            <div class="flex flex-col">
                <span class="text-[10px] text-slate-500 font-bold uppercase tracking-wider text-responsive italic">حساب التاجر</span>
                <span class="text-sm font-black text-white"><?php echo htmlspecialchars($username); ?></span>
            </div>
        </div>
        <a href="logout.php" onclick="return confirm('هل تريد تسجيل الخروج؟')" class="flex items-center gap-2 bg-rose-500/10 hover:bg-rose-500 text-rose-500 hover:text-white px-5 py-2.5 rounded-xl text-xs font-black border border-rose-500/20 transition-all duration-300 group">
            <span class="hidden md:inline italic">خروج آمن</span>
            <i data-lucide="log-out" class="w-4 h-4 group-hover:translate-x-1 transition"></i>
        </a>
    </nav>

    <div class="max-w-6xl mx-auto">
        
        <header class="flex flex-col md:flex-row justify-between items-center gap-6 mb-10">
            <div>
                <h1 class="text-3xl font-black text-yellow-500 flex items-center gap-3 italic">
                    <i data-lucide="shield-check" class="w-8 h-8 text-yellow-500"></i> LEDGER PRO
                </h1>
                <div class="flex gap-4 mt-3">
                    <div class="text-xs text-blue-400 font-bold border-l border-slate-700 pl-4 uppercase tracking-tighter">شراء: <span class="text-white"><?php echo $def_buy; ?></span></div>
                    <div class="text-xs text-green-400 font-bold border-l border-slate-700 pl-4 uppercase tracking-tighter">بيع: <span class="text-white"><?php echo $def_sell; ?></span></div>
                    <button onclick="document.getElementById('settingsModal').classList.remove('hidden')" class="text-yellow-500 hover:scale-125 transition"><i data-lucide="sliders"></i></button>
                </div>
            </div>
            <a href="reports.php" class="bg-blue-600 hover:bg-blue-500 text-white px-6 py-3 rounded-2xl font-bold flex items-center gap-2 shadow-lg shadow-blue-900/20 transition">
                <i data-lucide="calendar-days" class="w-5 h-5"></i> الأرشيف والتقارير اليومية
            </a>
        </header>

        <!-- صف البطاقات الأول (إحصائيات عامة) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="glass-card p-5 rounded-2xl border-r-4 border-emerald-500">
                <span class="text-slate-400 text-[10px] font-bold block mb-1 uppercase tracking-wider">صافي الربح (YER)</span>
                <h3 class="text-lg md:text-2xl font-bold text-emerald-400"><?php echo number_format($total_profit_yer, 2); ?></h3>
            </div>
            <div class="glass-card p-5 rounded-2xl border-r-4 border-blue-500">
                <span class="text-slate-400 text-[10px] font-bold block mb-1 uppercase tracking-wider">صافي الربح ($)</span>
                <h3 class="text-lg md:text-2xl font-bold text-blue-400">$<?php echo number_format($total_profit_usd, 2); ?></h3>
            </div>
            <div class="glass-card p-5 rounded-2xl border-r-4 border-yellow-500 shadow-xl">
                <span class="text-slate-400 text-[10px] font-bold block mb-1 uppercase tracking-tighter italic">المخزون المتوفر (Stock)</span>
                <h3 class="text-lg md:text-2xl font-bold text-yellow-500"><?php echo number_format($remaining_stock, 4); ?></h3>
            </div>
            <div class="glass-card p-5 rounded-2xl border-r-4 border-purple-500">
                <span class="text-slate-400 text-[10px] font-bold block mb-1 uppercase tracking-wider italic">متوسط الشراء (WAC)</span>
                <h3 class="text-lg md:text-2xl font-bold text-purple-400"><?php echo number_format($avg_buy_price, 2); ?></h3>
            </div>
        </div>

        <!-- صف البطاقات الثاني (تداولات اليوم) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="glass-card p-4 rounded-2xl border-l-4 border-blue-500 bg-blue-500/5">
                <span class="text-[10px] text-blue-400 font-bold block">شراء اليوم (Vol)</span>
                <h3 class="text-lg font-black"><?php echo number_format($daily_buy_vol, 2); ?> <span class="text-[8px] text-slate-500">USDT</span></h3>
            </div>
            <div class="glass-card p-4 rounded-2xl border-l-4 border-green-500 bg-green-500/5">
                <span class="text-[10px] text-green-400 font-bold block">بيع اليوم (Vol)</span>
                <h3 class="text-lg font-black"><?php echo number_format($daily_sell_vol, 2); ?> <span class="text-[8px] text-slate-500">USDT</span></h3>
            </div>
            <div class="glass-card p-4 rounded-2xl border-l-4 border-slate-500 bg-slate-500/5">
                <span class="text-[10px] text-slate-400 font-bold block italic uppercase">شراء اليوم (YER)</span>
                <h3 class="text-lg font-black"><?php echo number_format($daily_buy_vol * $def_buy); ?></h3>
            </div>
            <div class="glass-card p-4 rounded-2xl border-l-4 border-slate-500 bg-slate-500/5">
                <span class="text-[10px] text-slate-400 font-bold block italic uppercase">بيع اليوم (YER)</span>
                <h3 class="text-lg font-black"><?php echo number_format($daily_sell_vol * $def_sell); ?></h3>
            </div>
        </div>

        <!-- صف البطاقات الثالث (أرباح ورسوم اليوم - الميزة الجديدة) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10">
            <div class="glass-card p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/20">
                <div class="flex justify-between mb-1"><span class="text-[9px] text-emerald-500 font-bold uppercase">ربح اليوم (ريال)</span><i data-lucide="trending-up" class="w-3 h-3 text-emerald-500"></i></div>
                <h3 class="text-lg font-black text-emerald-400"><?php echo number_format($daily_profit_yer_val); ?></h3>
            </div>
            <div class="glass-card p-4 rounded-2xl bg-blue-500/10 border border-blue-500/20">
                <div class="flex justify-between mb-1"><span class="text-[9px] text-blue-500 font-bold uppercase">ربح اليوم (دولار)</span><i data-lucide="dollar-sign" class="w-3 h-3 text-blue-500"></i></div>
                <h3 class="text-lg font-black text-blue-400">$<?php echo number_format($daily_profit_usd_val, 2); ?></h3>
            </div>
            <div class="glass-card p-4 rounded-2xl bg-rose-500/10 border border-rose-500/20">
                <div class="flex justify-between mb-1"><span class="text-[9px] text-rose-500 font-bold uppercase">رسوم اليوم (USDT)</span><i data-lucide="percent" class="w-3 h-3 text-rose-500"></i></div>
                <h3 class="text-lg font-black text-rose-400"><?php echo number_format($daily_fees_usdt_val, 4); ?></h3>
            </div>
            <div class="glass-card p-4 rounded-2xl bg-purple-500/10 border border-purple-500/20">
                <div class="flex justify-between mb-1"><span class="text-[9px] text-purple-500 font-bold uppercase">رسوم اليوم (ريال)</span><i data-lucide="calculator" class="w-3 h-3 text-purple-500"></i></div>
                <h3 class="text-lg font-black text-purple-400"><?php echo number_format($daily_fees_yer_val); ?></h3>
            </div>
        </div>

        <div id="form-section" class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            
            <!-- نموذج الإدخال السريع (AJAX Enabled) -->
            <div class="lg:col-span-5 order-2 lg:order-1">
                <div class="glass-card p-6 md:p-8 rounded-3xl border-b-4 border-b-yellow-500 shadow-2xl">
                    <h2 class="text-lg font-bold mb-8 flex items-center gap-3 text-yellow-500 italic">
                        <i data-lucide="plus-circle"></i> تسجيل عملية جديدة
                    </h2>
                    <form id="ajax-form" class="space-y-5">
                        
                        <div class="flex items-center gap-2 mb-4 p-3 bg-blue-500/5 rounded-xl border border-blue-500/20">
                            <input type="checkbox" id="enable_backdate" class="w-4 h-4 accent-yellow-500 cursor-pointer" onchange="toggleDateInput()">
                            <label for="enable_backdate" class="text-xs text-blue-400 font-bold cursor-pointer select-none italic">هل تريد إضافة عملية لتاريخ قديم؟</label>
                        </div>

                        <div id="date_container" style="display: none;" class="animate-pulse">
                            <label class="block text-xs text-slate-400 mb-2 font-bold flex items-center gap-2">
                                <i data-lucide="calendar" class="w-3 h-3 text-yellow-500"></i> اختر التاريخ والوقت المنسي:
                            </label>
                            <input type="datetime-local" name="transaction_date" id="manual_date" class="input-dark text-yellow-500 border-yellow-500/30">
                        </div>

                        <div id="live_clock_display" class="bg-slate-900/40 p-3 rounded-xl border border-slate-700 flex justify-between items-center mb-4">
                            <span class="text-[10px] text-slate-500 font-bold uppercase tracking-widest italic">التوقيت المحلي لليمن</span>
                            <span id="clock" class="text-sm font-black text-yellow-500 tabular-nums tracking-widest">--:--:--</span>
                        </div>

                        <div>
                            <label class="block text-xs text-slate-400 mb-2 font-bold uppercase italic">نوع العملية</label>
                            <select name="type" id="typeSelect" onchange="handleTypeChange()" class="input-dark cursor-pointer font-bold text-yellow-500">
                                <option value="buy">شراء من زبون (وارد)</option>
                                <option value="sell">بيع لزبون (صادر)</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-slate-400 mb-2 font-bold tracking-tighter">الكمية (USDT)</label>
                                <input type="number" step="any" name="amount" id="crypto_amount_input" required class="input-dark text-xl font-bold" placeholder="0.0000">
                                
                                <div class="mt-4 p-3 bg-yellow-500/5 rounded-xl border border-yellow-500/20">
                                    <div class="flex justify-between items-center mb-2">
                                        <label class="block text-[10px] text-yellow-500 font-bold uppercase italic tracking-tighter">رسوم بينانس (USDT)</label>
                                        <span class="text-[8px] text-slate-500 italic">تلقائي 0.1%</span>
                                    </div>
                                    <input type="number" step="any" name="binance_fee" id="binance_fee_input" class="input-dark text-sm font-bold border-yellow-500/30 text-yellow-500" placeholder="0.0000">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-2 font-bold text-blue-400 tracking-tighter">السعر (YER)</label>
                                <input type="number" step="any" name="price" id="priceInput" value="<?php echo $def_buy; ?>" required class="input-dark text-xl font-black">
                            </div>
                        </div>

                        <div id="manualFeeContainer" class="bg-slate-900/50 p-4 rounded-2xl border border-dashed border-slate-700">
                            <label class="block text-[10px] text-blue-400 mb-2 font-bold uppercase tracking-widest italic text-responsive">رسوم إيداع إضافية (ريال يمني)</label>
                            <input type="number" step="any" name="manual_fee" class="input-dark" placeholder="0.00">
                        </div>

                        <button type="submit" id="submit-btn" class="w-full btn-yellow py-4 flex justify-center items-center gap-2 shadow-xl active:scale-95 transition-all">
                            <i data-lucide="save" class="w-5 h-5"></i> حفظ وتحديث البيانات
                        </button>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-7 order-1 lg:order-2">
                <div class="glass-card rounded-3xl overflow-hidden shadow-2xl flex flex-col max-h-[750px]">
                    <div class="p-6 border-b border-slate-700 bg-slate-800/40 sticky top-0 z-20 flex justify-between items-center">
                        <h2 class="text-lg font-bold text-slate-300 flex items-center gap-2 uppercase tracking-wider italic">
                            <i data-lucide="list-video" class="w-5 h-5 text-yellow-500"></i> سجل العمليات المتسلسل
                        </h2>
                        <button onclick="location.reload()" class="text-xs text-blue-400 hover:underline italic">تحديث السجل</button>
                    </div>
                    
                    <div class="overflow-y-auto flex-grow custom-scrollbar">
                        <table class="w-full text-right min-w-[500px]">
                            <thead class="bg-slate-800/90 text-slate-500 text-[10px] uppercase font-black tracking-widest sticky top-0 z-10">
                                <tr>
                                    <th class="px-6 py-4">التفاصيل (Float)</th>
                                    <th class="px-6 py-4 text-center text-white">الإجمالي (YER)</th>
                                    <th class="px-6 py-4 text-center">إدارة</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800" id="transactions-list">
                                <?php foreach($transactions as $row): ?>
                                <tr class="hover:bg-slate-800/50 transition group">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3 text-responsive">
                                            <div class="p-2 rounded-lg <?php echo $row['type'] == 'buy' ? 'bg-blue-500/10 text-blue-400' : 'bg-green-500/10 text-green-400'; ?>">
                                                <i data-lucide="<?php echo $row['type'] == 'buy' ? 'plus' : 'minus'; ?>" class="w-4 h-4"></i>
                                            </div>
                                            <div>
                                                <p class="text-sm font-bold text-slate-200"><?php echo number_format($row['crypto_amount'], 4); ?> USDT</p>
                                                <p class="text-[10px] text-slate-500 italic font-medium tracking-tight">
                                                    <?php echo date('Y-m-d | H:i:s', strtotime($row['created_at'])); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-center font-bold">
                                        <p class="text-sm font-black text-white"><?php echo number_format($row['total_fiat_paid'], 2); ?></p>
                                        <p class="text-[10px] text-slate-500 tracking-tighter italic">سعر: <?php echo $row['price_per_unit']; ?></p>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex justify-center gap-3 opacity-0 group-hover:opacity-100 transition duration-200">
                                            <button onclick='openEditModal(<?php echo json_encode($row); ?>)' class="text-blue-400 hover:scale-125 transition-transform"><i data-lucide="edit-3" class="w-4 h-4"></i></button>
                                            <a href="delete.php?id=<?php echo $row['id']; ?>" onclick="return confirm('حذف العملية نهائياً؟')" class="text-rose-500 hover:scale-125 transition-transform"><i data-lucide="trash-2" class="w-4 h-4"></i></a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة الإعدادات (Modal) -->
    <div id="settingsModal" class="hidden fixed inset-0 bg-black/95 flex items-center justify-center p-4 z-50 animate-in fade-in duration-300">
        <div class="glass-card w-full max-w-sm p-8 rounded-3xl border-2 border-yellow-500/40 shadow-2xl text-center">
            <h2 class="text-xl font-bold mb-8 text-yellow-500 flex items-center justify-center gap-3 italic">
                <i data-lucide="cog"></i> الأسعار الافتراضية
            </h2>
            <form action="update_settings.php" method="POST" class="space-y-6">
                <div>
                    <label class="block text-xs text-blue-400 mb-2 font-bold uppercase tracking-widest italic text-right">سعر الشراء (Buy)</label>
                    <input type="number" step="any" name="default_buy_price" value="<?php echo $def_buy; ?>" class="input-dark text-2xl font-black text-center tracking-widest">
                </div>
                <div>
                    <label class="block text-xs text-green-400 mb-2 font-bold uppercase tracking-widest italic text-right">سعر البيع (Sell)</label>
                    <input type="number" step="any" name="default_sell_price" value="<?php echo $def_sell; ?>" class="input-dark text-2xl font-black text-center tracking-widest">
                </div>
                <div class="flex gap-4 pt-4">
                    <button type="submit" class="flex-1 btn-yellow py-4 shadow-lg shadow-yellow-900/20">حفظ التغييرات</button>
                    <button type="button" onclick="document.getElementById('settingsModal').classList.add('hidden')" class="flex-1 bg-slate-800 py-4 rounded-2xl text-xs font-bold text-white">إغلاق</button>
                </div>
            </form>
        </div>
    </div>

    <!-- نافذة التعديل (Modal) -->
    <div id="editModal" class="hidden fixed inset-0 bg-black/95 flex items-center justify-center p-4 z-50 animate-in zoom-in duration-300">
        <div class="glass-card w-full max-w-md p-8 rounded-3xl border-2 border-blue-500/40 shadow-2xl">
            <h2 class="text-xl font-bold mb-8 text-blue-400 flex items-center gap-3 italic underline">
                <i data-lucide="edit"></i> تعديل بيانات العملية
            </h2>
            <form action="update.php" method="POST" class="space-y-6">
                <input type="hidden" name="id" id="edit_id">
                
                <div>
                    <label class="block text-xs text-slate-400 mb-2 font-bold italic tracking-wider">تعديل التاريخ والوقت</label>
                    <input type="datetime-local" name="transaction_date" id="edit_date" required class="input-dark font-bold text-yellow-500 border-yellow-500/20">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-400 mb-2 font-bold italic">الكمية USDT</label>
                        <input type="number" step="any" name="amount" id="edit_amount" required class="input-dark font-bold tracking-widest">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-2 font-bold italic">السعر YER</label>
                        <input type="number" step="any" name="price" id="edit_price" required class="input-dark font-bold tracking-widest">
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-2 font-bold italic text-yellow-500 tracking-tighter uppercase underline">تعديل الرسوم (USDT)</label>
                    <input type="number" step="any" name="binance_fee" id="edit_binance_fee" class="input-dark text-yellow-500 font-bold tracking-widest">
                </div>
                <div class="flex gap-4 pt-4">
                    <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-500 py-4 rounded-2xl font-bold shadow-lg shadow-blue-900/20 text-white transition-colors">تحديث السجل</button>
                    <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="flex-1 bg-slate-800 py-4 rounded-2xl text-xs font-bold text-white">تراجع</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // 1. تمرير الأسعار الافتراضية للـ JavaScript
        const BUY_PRICE = <?php echo $def_buy; ?>;
        const SELL_PRICE = <?php echo $def_sell; ?>;

        // 2. تحديث الساعة الحية
        function updateClock() {
            const clockElement = document.getElementById('clock');
            if (clockElement) {
                const now = new Date();
                const options = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
                clockElement.textContent = now.toLocaleTimeString('en-US', options);
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        // 3. منطق الحفظ بدون تحديث ( AJAX / Fetch API )
        const ajaxForm = document.getElementById('ajax-form');
        const submitBtn = document.getElementById('submit-btn');

        ajaxForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i data-lucide="loader" class="animate-spin w-5 h-5"></i> جاري الحفظ...';
            lucide.createIcons();

            const formData = new FormData(this);
            
            fetch('process.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast(data.message);
                    this.reset();
                    handleTypeChange();
                    
                    // التحديث الذكي: إعادة تحميل الصفحة مع الحفاظ على مكان التمرير
                    setTimeout(() => {
                        window.location.hash = "form-section";
                        location.reload(); 
                    }, 1500);
                } else {
                    alert("خطأ: " + data.message);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i data-lucide="save" class="w-5 h-5"></i> حفظ وتحديث البيانات';
                    lucide.createIcons();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i data-lucide="save" class="w-5 h-5"></i> حفظ وتحديث البيانات';
                lucide.createIcons();
            });
        });

        // 4. إظهار الإشعار المنبثق
function showToast(msg) {
    const toast = document.getElementById('toast');
    const msgSpan = document.getElementById('toast-msg');
    
    msgSpan.textContent = msg;
    
    // إظهار الإشعار
    toast.classList.add('show');

    // إخفاء الإشعار بعد 3 ثوانٍ
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}
        // 5. الحفاظ على مكان التمرير عند التحميل
        window.onload = function() {
            if (window.location.hash === "#form-section") {
                document.getElementById('form-section').scrollIntoView();
            }
        }

        // 6. التحكم بنوع العملية وتغيير الأسعار
        function handleTypeChange() {
            const type = document.getElementById('typeSelect').value;
            const priceInput = document.getElementById('priceInput');
            const feeContainer = document.getElementById('manualFeeContainer');

            if (type === 'sell') {
                priceInput.value = SELL_PRICE;
                priceInput.classList.replace('text-blue-400', 'text-green-400');
                feeContainer.style.display = 'none';
            } else {
                priceInput.value = BUY_PRICE;
                priceInput.classList.replace('text-green-400', 'text-blue-400');
                feeContainer.style.display = 'block';
            }
        }

        // 7. التحكم بحقل التاريخ المنسي
        function toggleDateInput() {
            const isChecked = document.getElementById('enable_backdate').checked;
            const container = document.getElementById('date_container');
            const clockDisplay = document.getElementById('live_clock_display');
            const input = document.getElementById('manual_date');

            if (isChecked) {
                container.style.display = 'block';
                clockDisplay.style.display = 'none';
                input.required = true;
                let now = new Date();
                input.value = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            } else {
                container.style.display = 'none';
                clockDisplay.style.display = 'flex';
                input.required = false;
                input.value = "";
            }
        }

        // 8. حساب الرسوم التلقائي أثناء الكتابة
        const amountInput = document.getElementById('crypto_amount_input');
        const feeInput = document.getElementById('binance_fee_input');

        amountInput.addEventListener('input', function() {
            const val = parseFloat(this.value);
            if (!isNaN(val) && val > 0) {
                const autoFee = (val * 0.001);
                feeInput.value = autoFee.toFixed(4);
            } else {
                feeInput.value = "0.0000";
            }
        });

        // 9. فتح نافذة التعديل وتعبئة بياناتها
        function openEditModal(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_amount').value = data.crypto_amount;
            document.getElementById('edit_price').value = data.price_per_unit;
            document.getElementById('edit_binance_fee').value = data.binance_fee;
            
            let date = new Date(data.created_at);
            let localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
            document.getElementById('edit_date').value = localDate;

            document.getElementById('editModal').classList.remove('hidden');
        }

        // تشغيل الحالة الافتراضية
        handleTypeChange();
    </script>
</body>
</html>