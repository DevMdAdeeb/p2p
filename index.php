<?php 
/**
 * نظام LEDGER PRO - النسخة المتكاملة
 * إدارة تداولات P2P - حسابات دقيقة - أرشفة - نظام مستخدمين
 */

session_start();
require_once 'db.php'; 

// 1. ضبط التوقيت لليمن (GMT+3) لضمان دقة العمليات الحالية واليومية
date_default_timezone_set('Asia/Aden');
$pdo->exec("SET time_zone = '+03:00'");
$today = date('Y-m-d');

// 2. حماية الصفحة: التأكد من تسجيل الدخول وتحديد هوية المستخدم
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id']; 
$username = $_SESSION['username'] ?? 'مستخدم';

// --- البدء بجلب البيانات الخاصة بالمستخدم الحالي فقط ---

// جلب إعدادات الأسعار الافتراضية
$settings_stmt = $pdo->prepare("SELECT default_buy_price, default_sell_price FROM settings WHERE user_id = ?");
$settings_stmt->execute([$user_id]);
$settings = $settings_stmt->fetch();
$def_buy = $settings['default_buy_price'] ?? 535;
$def_sell = $settings['default_sell_price'] ?? 540;

// حساب متوسط الشراء (WAC) بدقة Float - أساس حساب الربح الحقيقي
$buy_stats_stmt = $pdo->prepare("SELECT SUM(total_fiat_paid) as total_spent, SUM(crypto_amount) as total_bought FROM transactions WHERE user_id = ? AND type='buy'");
$buy_stats_stmt->execute([$user_id]);
$buy_stats = $buy_stats_stmt->fetch();
$avg_buy_price = ($buy_stats['total_bought'] > 0) ? ($buy_stats['total_spent'] / $buy_stats['total_bought']) : 0;

// أ. حساب الأرباح التراكمية (YER / USD)
$profit_stmt = $pdo->prepare("SELECT SUM((price_per_unit - ?) * crypto_amount) as net_profit FROM transactions WHERE user_id = ? AND type='sell'");
$profit_stmt->execute([$avg_buy_price, $user_id]);
$total_profit_yer_all = $profit_stmt->fetchColumn() ?: 0;
$total_profit_usd_all = ($def_buy > 0) ? ($total_profit_yer_all / $def_buy) : 0;

// ب. حساب حجم التداول اليومي (Volume)
$daily_buy_vol_stmt = $pdo->prepare("SELECT SUM(crypto_amount) FROM transactions WHERE user_id = ? AND type='buy' AND DATE(created_at) = ?");
$daily_buy_vol_stmt->execute([$user_id, $today]);
$daily_buy_vol = $daily_buy_vol_stmt->fetchColumn() ?: 0;

$daily_sell_vol_stmt = $pdo->prepare("SELECT SUM(crypto_amount) FROM transactions WHERE user_id = ? AND type='sell' AND DATE(created_at) = ?");
$daily_sell_vol_stmt->execute([$user_id, $today]);
$daily_sell_vol = $daily_sell_vol_stmt->fetchColumn() ?: 0;

// ج. حساب المخزون المتوفر (Stock)
$total_in = $pdo->prepare("SELECT SUM(total_crypto_deducted) FROM transactions WHERE user_id = ? AND type='buy'");
$total_in->execute([$user_id]);
$sum_in = $total_in->fetchColumn() ?: 0;

$total_out = $pdo->prepare("SELECT SUM(total_crypto_deducted) FROM transactions WHERE user_id = ? AND type='sell'");
$total_out->execute([$user_id]);
$sum_out = $total_out->fetchColumn() ?: 0;
$remaining_stock = $sum_in - $sum_out;

// د. حسابات اليوم (أرباح ورسوم اليوم فقط) - الميزة الجديدة
$daily_profit_stmt = $pdo->prepare("SELECT SUM((price_per_unit - ?) * crypto_amount) FROM transactions WHERE user_id = ? AND type='sell' AND DATE(created_at) = ?");
$daily_profit_stmt->execute([$avg_buy_price, $user_id, $today]);
$daily_profit_yer_val = $daily_profit_stmt->fetchColumn() ?: 0;
$daily_profit_usd_val = ($def_buy > 0) ? ($daily_profit_yer_val / $def_buy) : 0;

$daily_fees_stmt = $pdo->prepare("SELECT SUM(binance_fee) FROM transactions WHERE user_id = ? AND DATE(created_at) = ?");
$daily_fees_stmt->execute([$user_id, $today]);
$daily_fees_usdt_val = $daily_fees_stmt->fetchColumn() ?: 0;
$daily_fees_yer_val = $daily_fees_usdt_val * $def_buy;

// هـ. جلب السجل التاريخي (آخر 500 عملية)
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
    @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap');
    :root { --bg-main: #0a0f1c; --bg-card: #151b2d; --accent-gold: #eab308; --border-color: #242f48; --radius: 4px; }
    body { font-family: 'Tajawal', sans-serif; background-color: var(--bg-main); color: #e2e8f0; line-height: 1.6; scroll-behavior: smooth; font-style: normal !important; }
    * { font-style: normal !important; }
    .glass-card { background: var(--bg-card); border: 1px solid var(--border-color); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3); border-radius: var(--radius) !important; }
    .input-dark { background-color: #0d1220; border: 1px solid var(--border-color); color: white; padding: 12px; border-radius: var(--radius) !important; width: 100%; font-size: 15px; transition: all 0.3s ease; }
    .input-dark:focus { border-color: var(--accent-gold); outline: none; box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.1); }
    .btn-yellow { background: linear-gradient(135deg, #facc15 0%, #eab308 100%); color: #0f172a; font-weight: 900; border-radius: var(--radius) !important; transition: all 0.3s; }
    .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
    #toast { transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55); transform: translate(-50%, 100px); visibility: hidden; opacity: 0; z-index: 9999; }
    #toast.show { visibility: visible; opacity: 1; transform: translate(-50%, 0); }
    nav { border-radius: 0 0 var(--radius) var(--radius) !important; border-bottom: 2px solid var(--accent-gold) !important; }
    .tabular-nums { font-family: 'Courier New', monospace; font-weight: 700; }
</style>
</head>
<body class="p-3 md:p-6 pb-20">

    <!-- الإشعار المنبثق -->
    <div id="toast" class="fixed bottom-10 left-1/2 -translate-x-1/2 bg-emerald-600 text-white px-8 py-4 rounded-xl shadow-2xl font-black flex items-center gap-3 border border-emerald-400/30">
        <i data-lucide="check-circle"></i> <span id="toast-msg">تم الحفظ بنجاح!</span>
    </div>

    <!-- شريط التنقل العلوي -->
    <nav class="max-w-6xl mx-auto bg-[#1e293b]/50 border-b border-slate-800 py-3 px-4 md:px-8 flex justify-between items-center mb-8 rounded-b-2xl glass-card">
        <div class="flex items-center gap-3 group">
            <div class="w-10 h-10 bg-yellow-500/10 rounded-full flex items-center justify-center border border-yellow-500/20 group-hover:scale-110 transition">
                <i data-lucide="user-lock" class="w-6 h-6 text-yellow-500"></i>
            </div>
            <div class="flex flex-col text-right">
                <span class="text-[10px] text-slate-500 font-bold uppercase tracking-wider italic">الحساب النشط</span>
                <span class="text-sm font-black text-white"><?php echo htmlspecialchars($username); ?></span>
            </div>
        </div>
        <a href="logout.php" onclick="return confirm('هل تريد الخروج؟')" class="flex items-center gap-2 bg-rose-500/10 hover:bg-rose-500 text-rose-500 hover:text-white px-5 py-2 rounded-xl text-xs font-black border border-rose-500/20 transition-all">
            <span>خروج آمن</span> <i data-lucide="log-out" class="w-4 h-4"></i>
        </a>
    </nav>

    <div class="max-w-6xl mx-auto">
        <header class="flex flex-col md:flex-row justify-between items-center gap-6 mb-10">
            <div class="text-right">
                <h1 class="text-3xl font-black text-yellow-500 flex items-center gap-3 italic"><i data-lucide="shield-check"></i> LEDGER PRO</h1>
                <div class="flex gap-4 mt-3">
                    <div class="text-xs text-blue-400 font-bold border-l border-slate-700 pl-4 uppercase">شراء: <span class="text-white"><?php echo $def_buy; ?></span></div>
                    <div class="text-xs text-green-400 font-bold border-l border-slate-700 pl-4 uppercase">بيع: <span class="text-white"><?php echo $def_sell; ?></span></div>
                    <button onclick="document.getElementById('settingsModal').classList.remove('hidden')" class="text-yellow-500 hover:scale-125 transition"><i data-lucide="sliders"></i></button>
                </div>
            </div>
            <a href="reports.php" class="bg-blue-600 hover:bg-blue-500 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 shadow-lg transition"><i data-lucide="calendar-days"></i> التقارير والتحليل</a>
        </header>

        <!-- البطاقات: الصف الأول (إحصائيات الربح والمخزون) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="glass-card p-5 rounded-xl border-r-4 border-emerald-500">
                <span class="text-slate-400 text-[10px] font-bold block mb-1 uppercase">إجمالي الربح (YER)</span>
                <h3 class="text-lg md:text-2xl font-black text-emerald-400"><?php echo number_format($total_profit_yer_all, 2); ?></h3>
            </div>
            <div class="glass-card p-5 rounded-xl border-r-4 border-blue-500">
                <span class="text-slate-400 text-[10px] font-bold block mb-1 uppercase">إجمالي الربح ($)</span>
                <h3 class="text-lg md:text-2xl font-black text-blue-400">$<?php echo number_format($total_profit_usd_all, 2); ?></h3>
            </div>
            <div class="glass-card p-5 rounded-xl border-r-4 border-yellow-500 shadow-xl">
                <span class="text-slate-400 text-[10px] font-bold block mb-1 uppercase italic tracking-tighter">المخزون الحالي (Stock)</span>
                <h3 class="text-lg md:text-2xl font-black text-yellow-500"><?php echo number_format($remaining_stock, 4); ?></h3>
            </div>
            <div class="glass-card p-5 rounded-xl border-r-4 border-purple-500">
                <span class="text-slate-400 text-[10px] font-bold block mb-1 uppercase italic">متوسط الشراء (WAC)</span>
                <h3 class="text-lg md:text-2xl font-black text-purple-400"><?php echo number_format($avg_buy_price, 2); ?></h3>
            </div>
        </div>

        <!-- البطاقات: الصف الثاني (فوليوم اليوم) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="glass-card p-4 bg-blue-500/5 border-l-4 border-blue-500">
                <span class="text-[10px] text-blue-400 font-bold block">شراء اليوم (Vol)</span>
                <h3 class="text-lg font-black"><?php echo number_format($daily_buy_vol, 2); ?> USDT</h3>
            </div>
            <div class="glass-card p-4 bg-green-500/5 border-l-4 border-green-500">
                <span class="text-[10px] text-green-400 font-bold block">بيع اليوم (Vol)</span>
                <h3 class="text-lg font-black"><?php echo number_format($daily_sell_vol, 2); ?> USDT</h3>
            </div>
            <div class="glass-card p-4 bg-slate-500/5 border-l-4 border-slate-500">
                <span class="text-[10px] text-slate-400 font-bold block">وارد اليوم (YER)</span>
                <h3 class="text-lg font-black"><?php echo number_format($daily_buy_vol * $def_buy); ?></h3>
            </div>
            <div class="glass-card p-4 bg-slate-500/5 border-l-4 border-slate-500">
                <span class="text-[10px] text-slate-400 font-bold block">صادر اليوم (YER)</span>
                <h3 class="text-lg font-black"><?php echo number_format($daily_sell_vol * $def_sell); ?></h3>
            </div>
        </div>

        <!-- البطاقات: الصف الثالث (أرباح ورسوم اليوم) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10">
            <div class="glass-card p-4 bg-emerald-500/10 border border-emerald-500/20">
                <span class="text-[9px] text-emerald-500 font-black uppercase mb-1 block">ربح اليوم (ريال)</span>
                <h3 class="text-lg font-black text-emerald-400"><?php echo number_format($daily_profit_yer_val); ?></h3>
            </div>
            <div class="glass-card p-4 bg-blue-500/10 border border-blue-500/20">
                <span class="text-[9px] text-blue-500 font-black uppercase mb-1 block">ربح اليوم (دولار)</span>
                <h3 class="text-lg font-black text-blue-400">$<?php echo number_format($daily_profit_usd_val, 2); ?></h3>
            </div>
            <div class="glass-card p-4 bg-rose-500/10 border border-rose-500/20">
                <span class="text-[9px] text-rose-500 font-black uppercase mb-1 block">رسوم اليوم (USDT)</span>
                <h3 class="text-lg font-black text-rose-400"><?php echo number_format($daily_fees_usdt_val, 4); ?></h3>
            </div>
            <div class="glass-card p-4 bg-purple-500/10 border border-purple-500/20">
                <span class="text-[9px] text-purple-500 font-black uppercase mb-1 block">رسوم اليوم (ريال)</span>
                <h3 class="text-lg font-black text-purple-400"><?php echo number_format($daily_fees_yer_val); ?></h3>
            </div>
        </div>

        <div id="form-section" class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- نموذج الإدخال السريع -->
            <div class="lg:col-span-5 order-2 lg:order-1">
                <div class="glass-card p-6 md:p-8 border-b-4 border-b-yellow-500 shadow-2xl">
                    <h2 class="text-lg font-bold mb-8 text-yellow-500 italic flex items-center gap-3">
                        <i data-lucide="zap"></i> تسجيل تداول جديد
                    </h2>
                    <form id="ajax-form" class="space-y-5">
                        <div class="flex items-center gap-2 mb-4 p-3 bg-blue-500/5 border border-blue-500/20">
                            <input type="checkbox" id="enable_backdate" class="w-4 h-4 accent-yellow-500 cursor-pointer" onchange="toggleDateInput()">
                            <label for="enable_backdate" class="text-xs text-blue-400 font-bold cursor-pointer italic">إضافة عملية ليوم منسي؟</label>
                        </div>
                        <div id="date_container" style="display: none;" class="mb-4 animate-pulse">
                            <input type="datetime-local" name="transaction_date" id="manual_date" class="input-dark text-yellow-500 border-yellow-500/30 font-bold">
                        </div>
                        <div id="live_clock_display" class="bg-slate-900/40 p-3 border border-slate-700 flex justify-between items-center mb-4">
                            <span class="text-[10px] text-slate-500 font-bold uppercase tracking-widest italic">ساعة اليمن</span>
                            <span id="clock" class="text-sm font-black text-yellow-500 tabular-nums">--:--:--</span>
                        </div>
                        <select name="type" id="typeSelect" onchange="handleTypeChange()" class="input-dark cursor-pointer font-bold text-yellow-500 text-center">
                            <option value="buy">شراء من زبون (أنت تستلم)</option>
                            <option value="sell">بيع لزبون (أنت ترسل)</option>
                        </select>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-slate-400 mb-2 font-bold italic tracking-tighter uppercase">الكمية المستلمة (Net)</label>
                                <input type="number" step="any" name="amount" id="crypto_amount_input" required class="input-dark text-xl font-bold tabular-nums" placeholder="0.0000">
                            </div>
                            <div>
                                <label class="block text-xs text-slate-400 mb-2 font-bold italic tracking-tighter uppercase">السعر المتفق عليه</label>
                                <input type="number" step="any" name="price" id="priceInput" value="<?php echo $def_buy; ?>" required class="input-dark text-xl font-black tabular-nums text-blue-400">
                            </div>
                        </div>

                        <!-- حاسبة المعاينة الذكية للهندسة العكسية -->
                        <div id="calc-preview" class="p-4 bg-slate-900/80 border border-slate-700 text-[11px] space-y-2 hidden">
                            <div class="flex justify-between font-bold"><span class="text-slate-500">الكمية الإجمالية (Gross):</span> <span id="prev-gross" class="text-white tabular-nums">0.00</span></div>
                            <div class="flex justify-between border-t border-slate-800 pt-2"><span class="text-yellow-500 font-black uppercase">إجمالي الدفع النهائي (YER):</span> <span id="prev-total-yer" class="text-yellow-500 font-black tabular-nums text-sm">0.00</span></div>
                        </div>

                        <div class="p-3 bg-yellow-500/5 border border-yellow-500/20">
                            <label class="block text-[10px] text-yellow-500 font-bold uppercase italic mb-2 flex justify-between"><span>رسوم بينانس (USDT)</span> <span class="text-[8px] text-slate-500 italic">تلقائي 0.1%</span></label>
                            <input type="number" step="any" name="binance_fee" id="binance_fee_input" class="input-dark text-sm font-bold text-yellow-500 tabular-nums">
                        </div>
                        <div id="manualFeeContainer" class="bg-slate-900/50 p-4 border border-dashed border-slate-700">
                            <label class="block text-[10px] text-blue-400 mb-2 font-bold uppercase tracking-widest italic">رسوم إيداع إضافية (ريال يمني)</label>
                            <input type="number" step="any" name="manual_fee" id="manual_fiat_fee" class="input-dark tabular-nums" placeholder="0.00">
                        </div>
                        <button type="submit" id="submit-btn" class="w-full btn-yellow py-4 flex justify-center items-center gap-2 shadow-xl active:scale-95 transition-all">
                            <i data-lucide="save"></i> حفظ وتحديث الأرباح
                        </button>
                    </form>
                </div>
            </div>

            <!-- جدول العمليات المتسلسل -->
            <div class="lg:col-span-7 order-1 lg:order-2">
                <div class="glass-card overflow-hidden shadow-2xl flex flex-col max-h-[850px]">
                    <div class="p-6 border-b border-slate-700 bg-slate-800/40 sticky top-0 z-20 flex justify-between items-center">
                        <h2 class="text-lg font-black text-slate-300 flex items-center gap-2 uppercase tracking-widest italic"><i data-lucide="activity"></i> السجل المتسلسل</h2>
                        <button onclick="location.reload()" class="text-xs text-blue-400 font-bold hover:underline italic">تحديث يدوي</button>
                    </div>
                    <div class="overflow-y-auto flex-grow custom-scrollbar">
                        <table class="w-full text-right min-w-[500px]">
                            <thead class="bg-slate-800/90 text-slate-500 text-[10px] uppercase font-black tracking-widest sticky top-0 z-10">
                                <tr><th class="px-6 py-4">بيانات التداول</th><th class="px-6 py-4 text-center text-white">الإجمالي (YER)</th><th class="px-6 py-4 text-center">إدارة</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                <?php foreach($transactions as $row): ?>
                                <tr class="hover:bg-slate-800/50 transition group">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-4">
                                            <div class="p-2 rounded <?php echo $row['type'] == 'buy' ? 'bg-blue-500/10 text-blue-400' : 'bg-green-500/10 text-green-400'; ?>"><i data-lucide="<?php echo $row['type'] == 'buy' ? 'plus' : 'minus'; ?>" class="w-4 h-4"></i></div>
                                            <div>
                                                <p class="text-sm font-black text-slate-200 tabular-nums"><?php echo number_format($row['crypto_amount'], 4); ?> USDT</p>
                                                <p class="text-[10px] text-slate-500 font-bold italic tabular-nums"><?php echo date('Y-m-d | H:i:s', strtotime($row['created_at'])); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-center font-bold">
                                        <p class="text-sm font-black text-white tabular-nums"><?php echo number_format($row['total_fiat_paid'], 2); ?></p>
                                        <p class="text-[10px] text-slate-500 tracking-tighter italic">سعر: <?php echo $row['price_per_unit']; ?></p>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex justify-center gap-4 opacity-0 group-hover:opacity-100 transition duration-300">
                                            <button onclick='openEditModal(<?php echo json_encode($row); ?>)' class="text-blue-400 hover:scale-125 transition-transform"><i data-lucide="edit-3" class="w-4 h-4"></i></button>
                                            <a href="delete.php?id=<?php echo $row['id']; ?>" onclick="return confirm('حذف؟')" class="text-rose-500 hover:scale-125 transition-transform"><i data-lucide="trash-2" class="w-4 h-4"></i></a>
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

    <!-- نافذة الإعدادات -->
    <div id="settingsModal" class="hidden fixed inset-0 bg-black/95 flex items-center justify-center p-4 z-50">
        <div class="glass-card w-full max-w-sm p-8 border-2 border-yellow-500/30 shadow-2xl text-center">
            <h2 class="text-xl font-black mb-8 text-yellow-500 flex items-center justify-center gap-3 italic uppercase underline decoration-yellow-500/20 tracking-widest"><i data-lucide="cog"></i> الإعدادات</h2>
            <form action="update_settings.php" method="POST" class="space-y-6 text-right">
                <div><label class="block text-xs text-blue-400 mb-2 font-black uppercase italic tracking-widest">سعر الشراء الافتراضي</label><input type="number" step="any" name="default_buy_price" value="<?php echo $def_buy; ?>" class="input-dark text-2xl font-black text-center tabular-nums"></div>
                <div><label class="block text-xs text-green-400 mb-2 font-black uppercase italic tracking-widest">سعر البيع الافتراضي</label><input type="number" step="any" name="default_sell_price" value="<?php echo $def_sell; ?>" class="input-dark text-2xl font-black text-center tabular-nums"></div>
                <div class="flex gap-4 pt-4"><button type="submit" class="flex-1 btn-yellow py-4 shadow-lg shadow-yellow-900/20 uppercase font-black italic">حفظ</button><button type="button" onclick="document.getElementById('settingsModal').classList.add('hidden')" class="flex-1 bg-slate-800 py-4 text-xs font-black text-white uppercase italic">إغلاق</button></div>
            </form>
        </div>
    </div>

    <!-- نافذة التعديل -->
    <div id="editModal" class="hidden fixed inset-0 bg-black/95 flex items-center justify-center p-4 z-50">
        <div class="glass-card w-full max-w-md p-8 border-2 border-blue-500/30 shadow-2xl">
            <h2 class="text-xl font-black mb-8 text-blue-400 flex items-center gap-3 italic uppercase"><i data-lucide="edit"></i> تعديل بيانات قديمة</h2>
            <form action="update.php" method="POST" class="space-y-6 text-right">
                <input type="hidden" name="id" id="edit_id">
                <div><label class="block text-xs text-slate-400 mb-2 font-black italic tracking-widest uppercase">تعديل التاريخ والوقت</label><input type="datetime-local" name="transaction_date" id="edit_date" required class="input-dark font-black text-yellow-500 border-yellow-500/20 tabular-nums"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-xs text-slate-400 mb-2 font-black italic uppercase">الكمية Net</label><input type="number" step="any" name="amount" id="edit_amount" required class="input-dark font-black tabular-nums"></div>
                    <div><label class="block text-xs text-slate-400 mb-2 font-black italic uppercase">السعر YER</label><input type="number" step="any" name="price" id="edit_price" required class="input-dark font-black tabular-nums"></div>
                </div>
                <div><label class="block text-xs text-yellow-500 mb-2 font-black italic uppercase underline">تعديل الرسوم (USDT)</label><input type="number" step="any" name="binance_fee" id="edit_binance_fee" class="input-dark text-yellow-500 font-black tabular-nums"></div>
                <div class="flex gap-4 pt-4"><button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-500 py-4 font-black shadow-lg text-white uppercase italic">تحديث السجل</button><button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="flex-1 bg-slate-800 py-4 text-xs font-black text-white uppercase italic">تراجع</button></div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();
        const BUY_PRICE_DEF = <?php echo $def_buy; ?>;
        const SELL_PRICE_DEF = <?php echo $def_sell; ?>;

        function updateClock() { const clock = document.getElementById('clock'); if (clock) { const now = new Date(); clock.textContent = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' }); } }
        setInterval(updateClock, 1000); updateClock();

        function showToast(msg) { const toast = document.getElementById('toast'); document.getElementById('toast-msg').textContent = msg; toast.classList.add('show'); setTimeout(() => toast.classList.remove('show'), 4000); }

        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('status') === 'success') { showToast("تم حفظ العملية وتحديث ميزان الأرباح!"); window.history.replaceState({}, document.title, "index.php#form-section"); }
            if (window.location.hash === "#form-section") { document.getElementById('form-section').scrollIntoView({ behavior: 'smooth' }); }
        }

        const ajaxForm = document.getElementById('ajax-form');
        const submitBtn = document.getElementById('submit-btn');

        ajaxForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i data-lucide="loader" class="animate-spin w-5 h-5"></i> جاري المعالجة...';
            lucide.createIcons();
            const formData = new FormData(this);
            fetch('process.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => { if (data.status === 'success') { window.location.href = "index.php?status=success#form-section"; window.location.reload(); } else { alert("خطأ: " + data.message); submitBtn.disabled = false; submitBtn.innerHTML = '<i data-lucide="save"></i> حفظ وتحديث البيانات'; lucide.createIcons(); } })
            .catch(err => { console.error(err); submitBtn.disabled = false; });
        });

        const amountInput = document.getElementById('crypto_amount_input');
        const priceInput = document.getElementById('priceInput');
        const feeInput = document.getElementById('binance_fee_input');
        const manualFiatInput = document.getElementById('manual_fiat_fee');
        const typeSelect = document.getElementById('typeSelect');
        const calcPreview = document.getElementById('calc-preview');
        const prevGross = document.getElementById('prev-gross');
        const prevTotalYer = document.getElementById('prev-total-yer');

        function updateCalculations() {
            const amount = parseFloat(amountInput.value) || 0;
            const price = parseFloat(priceInput.value) || 0;
            const type = typeSelect.value;
            const manualFiat = parseFloat(manualFiatInput.value) || 0;

            if (amount > 0) {
                calcPreview.classList.remove('hidden');
                if (type === 'buy') {
                    const gross = amount / 0.999;
                    const fee = gross - amount;
                    const totalYer = (gross * price) + manualFiat;
                    feeInput.value = fee.toFixed(4);
                    prevGross.textContent = gross.toFixed(4) + " USDT";
                    prevTotalYer.textContent = totalYer.toLocaleString('en-US', { minimumFractionDigits: 2 }) + " YER";
                } else {
                    const fee = amount * 0.001;
                    const totalImpact = amount + fee;
                    const totalYer = amount * price;
                    feeInput.value = fee.toFixed(4);
                    prevGross.textContent = totalImpact.toFixed(4) + " USDT (Total Deduct)";
                    prevTotalYer.textContent = totalYer.toLocaleString('en-US', { minimumFractionDigits: 2 }) + " YER";
                }
            } else { calcPreview.classList.add('hidden'); feeInput.value = "0.0000"; }
        }

        amountInput.addEventListener('input', updateCalculations);
        priceInput.addEventListener('input', updateCalculations);
        manualFiatInput.addEventListener('input', updateCalculations);
        typeSelect.addEventListener('change', () => { handleTypeChange(); updateCalculations(); });

        function handleTypeChange() {
            const type = typeSelect.value;
            const feeContainer = document.getElementById('manualFeeContainer');
            if (type === 'sell') { priceInput.value = SELL_PRICE_DEF; feeContainer.style.display = 'none'; priceInput.classList.replace('text-blue-400', 'text-green-400'); }
            else { priceInput.value = BUY_PRICE_DEF; feeContainer.style.display = 'block'; priceInput.classList.replace('text-green-400', 'text-blue-400'); }
        }

        function toggleDateInput() {
            const isChecked = document.getElementById('enable_backdate').checked;
            const container = document.getElementById('date_container');
            const clockDisplay = document.getElementById('live_clock_display');
            const input = document.getElementById('manual_date');
            if (isChecked) { container.style.display = 'block'; clockDisplay.style.display = 'none'; input.required = true; let now = new Date(); input.value = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16); }
            else { container.style.display = 'none'; clockDisplay.style.display = 'flex'; input.required = false; input.value = ""; }
        }

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

        handleTypeChange();
    </script>
</body>
</html>