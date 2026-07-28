<?php
ini_set('display_errors', 0); 
error_reporting(0);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Telegram-Init-Data");

$conn = new mysqli("localhost", "6a0f286f669d9_sardorstore", "sardorstore", "6a0f286f669d9_sardorstore");
if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "Ma'lumotlar bazasida xatolik!"]);
    exit;
}

$TOKEN = "8471799836:AAHmSZYDxF84XY_Klx3Y4gUU4Kkzs2oZdxE";
$ADMINS = [7977733681, 6453627966];

function validateTelegramInitData($initData, $botToken) {
    if (empty($initData)) return false;
    parse_str($initData, $data);
    if (!isset($data['hash'])) return false;
    $hash = $data['hash'];
    unset($data['hash']);
    ksort($data);
    $dataCheckString = "";
    foreach ($data as $key => $val) $dataCheckString .= "$key=$val\n";
    $dataCheckString = rtrim($dataCheckString, "\n");
    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $checkHash = hash_hmac('sha256', $dataCheckString, $secretKey);
    return hash_equals($checkHash, $hash);
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$initData = $headers['Telegram-Init-Data'] ?? $headers['telegram-init-data'] ?? '';
$data = json_decode(file_get_contents("php://input"), true);
$action = $data["action"] ?? "";
$isUsernameCheck = isset($data["web_app_check"]) && !empty($data["web_app_check"]);

if (!$isUsernameCheck && !empty($initData) && !validateTelegramInitData($initData, $TOKEN)) {
    echo json_encode(["success" => false, "message" => "Xavfsizlik xatosi!"]);
    $conn->close();
    exit;
}

// ========== 1. FOYDALANUVCHI MA'LUMOTLARI ==========
if ($action === "get_user_data") {
    $id = intval($data["user_id"] ?? 0);
    $name = $data["name"] ?? "Foydalanuvchi";
    $username = $data["username"] ?? "";
    if ($id <= 0) { echo json_encode(["success" => false]); $conn->close(); exit; }
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        $stmt = $conn->prepare("INSERT INTO users (id, name, username, balance, spent) VALUES (?, ?, ?, 0, 0)");
        $stmt->bind_param("iss", $id, $name, $username);
        $stmt->execute();
        $stmt->close();
        $user = ["id" => $id, "balance" => 0, "spent" => 0, "name" => $name, "username" => $username];
    }
    echo json_encode(["success" => true, "user" => $user]);
    $conn->close();
    exit;
}

// ========== 2. BUYURTMALAR TARIXI ==========
if ($action === "get_user_orders") {
    $id = intval($data["user_id"] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = []; $finance = [];
    while($row = $result->fetch_assoc()) {
        if (isset($row['type']) && $row['type'] === 'finance') $finance[] = $row;
        else $orders[] = $row;
    }
    echo json_encode(["success" => true, "orders" => $orders, "finance" => $finance]);
    $conn->close();
    exit;
}

// ========== 3. BUYURTMA YARATISH ==========
if ($action === "create_order") {
    $id = intval($data["user_id"] ?? 0);
    $product = $data["product_id"] ?? "unknown";
    $amount = intval($data["amount"] ?? 0);
    $target = $data["target"] ?? "";
    if ($id <= 0 || $amount <= 0) { echo json_encode(["success" => false]); $conn->close(); exit; }
    $stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $balance = $stmt->get_result()->fetch_assoc()['balance'] ?? 0;
    $stmt->close();
    if ($balance < $amount) { echo json_encode(["success" => false, "message" => "Balans yetarli emas"]); $conn->close(); exit; }
    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("UPDATE users SET balance = balance - ?, spent = spent + ? WHERE id = ?");
        $upd->bind_param("iii", $amount, $amount, $id);
        $upd->execute();
        $product_final = $product . ($target ? " (Target: @$target)" : "");
        $ins = $conn->prepare("INSERT INTO orders (user_id, product, amount, status, type) VALUES (?, ?, ?, 'pending', 'order')");
        $ins->bind_param("isi", $id, $product_final, $amount);
        $ins->execute();
        $order_id = $conn->insert_id;
        $conn->commit();

        // ***** SEENUZPRO.UZ API ORQALI AVTOMATIK YETKAZISH *****
        $SEENUZ_KEY = "090ffb855c57358d9d3bffb96c21f774";
        $SEENUZ_URL = "https://seenuzpro.uz/api/v3";
        $clean_target = ltrim($target, '@');

        // Mahsulot turini aniqlash
        $api_service = null;
        $api_quantity = null;

        if (stripos($product, 'Stars') !== false) {
            // Stars: miqdorni product dan ajratib olamiz (masalan "100 Stars")
            preg_match('/(\d+)/', $product, $m);
            $stars_count = isset($m[1]) ? intval($m[1]) : 50;
            $api_service = 4; // seenuzpro.uz da Stars service ID
            $api_quantity = $stars_count;
        } elseif (stripos($product, '3') !== false && stripos($product, 'Premium') !== false) {
            $api_service = 1; // 3 oylik Premium
        } elseif (stripos($product, '6') !== false && stripos($product, 'Premium') !== false) {
            $api_service = 2; // 6 oylik Premium
        } elseif (stripos($product, '12') !== false && stripos($product, 'Premium') !== false) {
            $api_service = 3; // 12 oylik Premium
        }

        if ($api_service !== null) {
            $params = [
                "key"     => $SEENUZ_KEY,
                "action"  => "add",
                "service" => $api_service,
                "link"    => "@" . $clean_target,
            ];
            if ($api_quantity !== null) $params["quantity"] = $api_quantity;

            $ch = curl_init($SEENUZ_URL . "?" . http_build_query($params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $res = curl_exec($ch);
            curl_close($ch);
            $api_result = json_decode($res, true);

            if (isset($api_result['status']) && $api_result['status'] === 'Completed') {
                // Muvaffaqiyatli — status ni completed ga o'zgartiramiz
                $upd2 = $conn->prepare("UPDATE orders SET status = 'completed' WHERE id = ?");
                $upd2->bind_param("i", $order_id);
                $upd2->execute();
                $upd2->close();
            } elseif (isset($api_result['error'])) {
                // API xatosi — balansni qaytaramiz
                $conn->begin_transaction();
                $rb = $conn->prepare("UPDATE users SET balance = balance + ?, spent = spent - ? WHERE id = ?");
                $rb->bind_param("iii", $amount, $amount, $id);
                $rb->execute();
                $rb->close();
                $del = $conn->prepare("UPDATE orders SET status = 'canceled' WHERE id = ?");
                $del->bind_param("i", $order_id);
                $del->execute();
                $del->close();
                $conn->commit();
                echo json_encode(["success" => false, "message" => "Yetkazishda xatolik: " . $api_result['error']]);
                $conn->close();
                exit;
            }
        }

        echo json_encode(["success" => true, "message" => "Buyurtma muvaffaqiyatli bajarildi! ✅"]);
    } catch (Exception $e) { $conn->rollback(); echo json_encode(["success" => false]); }
    $conn->close();
    exit;
}

// ========== 4. CHECKCARD - TO'LOV YARATISH ==========
if ($action === "create_payment") {
    $user_id = intval($data["user_id"] ?? 0);
    $amount = intval($data["amount"] ?? 0);
    if ($user_id <= 0 || $amount < 1000) {
        echo json_encode(["success" => false, "message" => "Summa noto'g'ri (minimal 1000 so'm)"]);
        $conn->close(); exit;
    }

    $SHOP_ID  = "684531";   // <-- o'zingiznikini yozing
    $SHOP_KEY = "HRI39VBVLA";  // <-- o'zingiznikini yozing

    $WEBHOOK_URL = "https://6a0f286f66ae8.myxvest2.ru/webhook.php";
    $url = "https://checkcard.uz/api?method=create&shop_id={$SHOP_ID}&shop_key={$SHOP_KEY}&amount={$amount}&payurl=true&webhook=".urlencode($WEBHOOK_URL);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($res, true);

    if (!$result || $result['status'] !== 'success') {
        $msg = $result['message'] ?? "Checkcard xatoligi";
        echo json_encode(["success" => false, "message" => $msg]);
        $conn->close(); exit;
    }

    $order_code = $result['order'];
    $pay_url    = $result['payurl'];

    // Bazaga saqlash
    $stmt = $conn->prepare("INSERT INTO orders (user_id, product, amount, status, type, payment_status, transaction_id) VALUES (?, 'deposit', ?, 'pending', 'finance', 'pending', ?)");
    $stmt->bind_param("iis", $user_id, $amount, $order_code);
    $stmt->execute();
    $stmt->close();

    echo json_encode(["success" => true, "order" => $order_code, "payurl" => $pay_url, "amount" => $amount]);
    $conn->close(); exit;
}

// ========== 5. CHECKCARD - TO'LOV STATUSINI TEKSHIRISH ==========
if ($action === "check_payment") {
    $user_id    = intval($data["user_id"] ?? 0);
    $order_code = $data["order"] ?? "";
    if ($user_id <= 0 || empty($order_code)) {
        echo json_encode(["success" => false, "message" => "Noto'g'ri so'rov"]);
        $conn->close(); exit;
    }

    // Bazadan buyurtmani olish
    $stmt = $conn->prepare("SELECT * FROM orders WHERE transaction_id = ? AND user_id = ? AND type = 'finance' LIMIT 1");
    $stmt->bind_param("si", $order_code, $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        echo json_encode(["success" => false, "message" => "Buyurtma topilmadi"]);
        $conn->close(); exit;
    }

    // Agar allaqachon to'langan bo'lsa
    if ($order['payment_status'] === 'paid') {
        echo json_encode(["success" => true, "status" => "paid"]);
        $conn->close(); exit;
    }

    // Checkcard dan status tekshirish
    $url = "https://checkcard.uz/api?method=check&order={$order_code}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($res, true);

    if ($result && $result['status'] === 'success' && $result['data']['status'] === 'paid') {
        // Balansga qo'shish
        $amount = intval($order['amount']);
        $upd = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $upd->bind_param("ii", $amount, $user_id);
        $upd->execute();
        $upd->close();

        // Status yangilash
        $upd2 = $conn->prepare("UPDATE orders SET status = 'completed', payment_status = 'paid' WHERE transaction_id = ?");
        $upd2->bind_param("s", $order_code);
        $upd2->execute();
        $upd2->close();

        echo json_encode(["success" => true, "status" => "paid", "amount" => $amount]);
    } elseif ($result && $result['data']['status'] === 'cancel') {
        $upd = $conn->prepare("UPDATE orders SET status = 'canceled', payment_status = 'cancel' WHERE transaction_id = ?");
        $upd->bind_param("s", $order_code);
        $upd->execute();
        $upd->close();
        echo json_encode(["success" => true, "status" => "cancel"]);
    } else {
        echo json_encode(["success" => true, "status" => "pending"]);
    }
    $conn->close(); exit;
}

// ========== 6. USERNAME TEKSHIRISH ==========
if ($isUsernameCheck) {
    $username = $data["username"] ?? "";
    if (empty($username)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    if (strpos($username, '@') === 0) $username = substr($username, 1);
    $url = "https://t.me/" . urlencode($username);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $html = curl_exec($ch);
    curl_close($ch);
    if ($html && preg_match('/<meta property="og:title" content="([^"]+)">/', $html, $matches)) {
        $real_name = htmlspecialchars_decode($matches[1], ENT_QUOTES);
        if ($real_name !== "Telegram: Contact @" . $username && !empty($real_name)) {
            echo json_encode(["success" => true, "name" => $real_name, "username" => $username]);
        } else { echo json_encode(["success" => false]); }
    } else { echo json_encode(["success" => false]); }
    $conn->close();
    exit;
}

// ========== 5. ADMIN - TO'LIQ STATISTIKA (DASHBOARD) ==========
if ($action === "get_admin_full_stats") {
    $admin_id = intval($data["admin_id"] ?? 0);
    if (!in_array($admin_id, $ADMINS)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id BIGINT PRIMARY KEY,
        name VARCHAR(255),
        username VARCHAR(255),
        balance BIGINT DEFAULT 0,
        spent BIGINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $total_users = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'] ?? 0;
    $today_users = $conn->query("SELECT COUNT(*) as c FROM users WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'] ?? 0;
    $total_orders = $conn->query("SELECT COUNT(*) as c FROM orders WHERE type='order'")->fetch_assoc()['c'] ?? 0;
    $total_payments = $conn->query("SELECT COALESCE(SUM(amount), 0) as s FROM orders WHERE type='finance' AND status='completed'")->fetch_assoc()['s'] ?? 0;
    
    $chart_labels = []; $chart_users = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $chart_labels[] = date('d M', strtotime($date));
        $chart_users[] = $conn->query("SELECT COUNT(*) as c FROM users WHERE DATE(created_at) = '$date'")->fetch_assoc()['c'] ?? 0;
    }
    
    $recent_orders = $conn->query("SELECT id, product, amount, status, created_at FROM orders WHERE type='order' ORDER BY id DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        "success" => true, 
        "total_users" => intval($total_users), 
        "today_users" => intval($today_users), 
        "total_orders" => intval($total_orders), 
        "total_payments" => intval($total_payments), 
        "chart_labels" => $chart_labels, 
        "chart_users" => array_map('intval', $chart_users), 
        "recent_orders" => $recent_orders
    ]);
    $conn->close();
    exit;
}

// ========== 6. ADMIN - FOYDALANUVCHILAR RO'YXATI ==========
if ($action === "get_users") {
    $admin_id = intval($data["admin_id"] ?? 0);
    if (!in_array($admin_id, $ADMINS)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    
    $search = $conn->real_escape_string($data["search"] ?? "");
    $sort = $data["sort"] ?? "id";
    $allowed_sorts = ['id', 'balance', 'spent', 'name', 'username'];
    if (!in_array($sort, $allowed_sorts)) $sort = "id";
    
    $query = "SELECT id, name, username, balance, spent FROM users";
    if (!empty($search)) {
        $query .= " WHERE id LIKE '%$search%' OR name LIKE '%$search%' OR username LIKE '%$search%'";
    }
    $query .= " ORDER BY $sort DESC";
    
    $users = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
    echo json_encode(["success" => true, "users" => $users]);
    $conn->close();
    exit;
}

// ========== 7. ADMIN - BALANS O'ZGARTIRISH ==========
if ($action === "update_balance") {
    $admin_id = intval($data["admin_id"] ?? 0);
    if (!in_array($admin_id, $ADMINS)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    $target_id = intval($data["target_id"] ?? 0);
    $amount = intval($data["amount"] ?? 0);
    $type = $data["type"] ?? "add";
    if ($target_id <= 0 || $amount <= 0) { echo json_encode(["success" => false]); $conn->close(); exit; }
    
    if ($type === "add") {
        $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
        $stmt->bind_param("ii", $amount, $target_id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET balance = GREATEST(0, balance - ?) WHERE id = ?");
        $stmt->bind_param("ii", $amount, $target_id);
    }
    $stmt->execute();
    $stmt->close();
    
    // Add to finance history
    $finance_type = $type === "add" ? "deposit" : "withdraw";
    $stmt = $conn->prepare("INSERT INTO orders (user_id, product, amount, status, type, payment_status) VALUES (?, ?, ?, 'completed', 'finance', 'admin')");
    $product_name = 'admin_' . $finance_type;
    $stmt->bind_param("isi", $target_id, $product_name, $amount);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(["success" => true]);
    $conn->close();
    exit;
}

// ========== 8. ADMIN - BARCHA BUYURTMALAR ==========
if ($action === "get_all_orders") {
    $admin_id = intval($data["admin_id"] ?? 0);
    if (!in_array($admin_id, $ADMINS)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    
    $search = $conn->real_escape_string($data["search"] ?? "");
    $status = $data["status"] ?? "all";
    $allowed_statuses = ['all', 'pending', 'completed', 'canceled'];
    if (!in_array($status, $allowed_statuses)) $status = "all";
    
    $query = "SELECT * FROM orders WHERE type='order'";
    if ($status !== "all") {
        $query .= " AND status = '$status'";
    }
    if (!empty($search)) {
        $query .= " AND (user_id LIKE '%$search%' OR product LIKE '%$search%')";
    }
    $query .= " ORDER BY id DESC LIMIT 50";
    
    $orders = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
    echo json_encode(["success" => true, "orders" => $orders]);
    $conn->close();
    exit;
}

// ========== 9. ADMIN - STATUS O'ZGARTIRISH ==========
if ($action === "admin_change_order_status") {
    $admin_id = intval($data["admin_id"] ?? 0);
    if (!in_array($admin_id, $ADMINS)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    $order_id = intval($data["order_id"] ?? 0);
    $status = $data["status"] ?? "";
    if ($order_id <= 0 || !in_array($status, ['completed', 'canceled'])) { echo json_encode(["success" => false]); $conn->close(); exit; }
    
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $order_id);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(["success" => true]);
    $conn->close();
    exit;
}

// ========== 10. ADMIN - TO'LOVLAR RO'YXATI ==========
if ($action === "get_all_payments") {
    $admin_id = intval($data["admin_id"] ?? 0);
    if (!in_array($admin_id, $ADMINS)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    $payments = $conn->query("SELECT o.id, o.user_id, o.amount, o.type, o.payment_status, o.transaction_id, o.created_at, u.name as user_name 
                              FROM orders o 
                              LEFT JOIN users u ON o.user_id = u.id 
                              WHERE o.type='finance' 
                              ORDER BY o.id DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
    echo json_encode(["success" => true, "payments" => $payments]);
    $conn->close();
    exit;
}

// ========== 11. SOZLAMALAR (HAMMA UCHUN OCHIQ) ==========
if ($action === "get_settings") {
    $conn->query("CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(50) PRIMARY KEY, `value` VARCHAR(255))");
    
    $count = $conn->query("SELECT COUNT(*) as c FROM settings")->fetch_assoc()['c'];
    if ($count == 0) {
        $conn->query("INSERT INTO settings (`key`, `value`) VALUES 
            ('stars_price', '2000'),
            ('premium_3m_price', '150000'),
            ('premium_6m_price', '280000'),
            ('premium_12m_price', '500000')
        ");
    }
    
    $result = [
        "success" => true,
        "stars_price" => "2000",
        "premium_3m_price" => "150000",
        "premium_6m_price" => "280000",
        "premium_12m_price" => "500000"
    ];
    
    $settings = $conn->query("SELECT * FROM settings");
    while ($row = $settings->fetch_assoc()) {
        $result[$row['key']] = $row['value'];
    }
    
    echo json_encode($result);
    $conn->close();
    exit;
}

// ========== 12. ADMIN - SOZLAMALARNI SAQLASH ==========
if ($action === "save_settings") {
    $admin_id = intval($data["admin_id"] ?? 0);
    if (!in_array($admin_id, $ADMINS)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    
    $conn->query("CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(50) PRIMARY KEY, `value` VARCHAR(255))");
    
    foreach ($data as $key => $value) {
        if ($key !== "action" && $key !== "admin_id") {
            $stmt = $conn->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
            $stmt->bind_param("sss", $key, $value, $value);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo json_encode(["success" => true, "message" => "Sozlamalar muvaffaqiyatli saqlandi!"]);
    $conn->close();
    exit;
}

// ========== 13. ADMIN - STATISTIKA ==========
if ($action === "get_statistics") {
    $admin_id = intval($data["admin_id"] ?? 0);
    if (!in_array($admin_id, $ADMINS)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    $monthly_orders = [];
    for ($i = 1; $i <= 12; $i++) $monthly_orders[] = $conn->query("SELECT COUNT(*) as c FROM orders WHERE MONTH(created_at) = $i AND YEAR(created_at) = YEAR(CURDATE())")->fetch_assoc()['c'] ?? 0;
    $top_buyers = $conn->query("SELECT name, spent, (SELECT COUNT(*) FROM orders WHERE user_id = users.id) as total_orders FROM users ORDER BY spent DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
    echo json_encode(["success" => true, "monthly_orders" => $monthly_orders, "top_buyers" => $top_buyers]);
    $conn->close();
    exit;
}

// ========== 14. ADMIN - LOGLAR ==========
if ($action === "get_admin_logs") {
    $admin_id = intval($data["admin_id"] ?? 0);
    if (!in_array($admin_id, $ADMINS)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    $logs = $conn->query("SELECT * FROM admin_logs ORDER BY id DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
    echo json_encode(["success" => true, "logs" => $logs]);
    $conn->close();
    exit;
}

// ========== 15. GLOBAL REKLAMA ==========
if ($action === "send_global_reklama") {
    $admin_id = intval($data["admin_id"] ?? 0);
    if (!in_array($admin_id, $ADMINS)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    $text = $data["text"] ?? "";
    if (empty($text)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    $users = $conn->query("SELECT id FROM users");
    $sent = 0;
    while ($row = $users->fetch_assoc()) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot$TOKEN/sendMessage");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(["chat_id" => $row['id'], "text" => $text, "parse_mode" => "HTML"]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_exec($ch);
        curl_close($ch);
        $sent++;
        usleep(50000);
    }
    echo json_encode(["success" => true, "message" => "Reklama $sent ta foydalanuvchiga yuborildi!"]);
    $conn->close();
    exit;
}

// ========== 16. MA'LUMOTLAR BAZASINI SOZLASH ==========
if ($action === "init_db") {
    $admin_id = intval($data["admin_id"] ?? 0);
    if (!in_array($admin_id, $ADMINS)) { echo json_encode(["success" => false]); $conn->close(); exit; }
    
    $conn->query("CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(50) PRIMARY KEY,
        `value` VARCHAR(255)
    )");
    
    $conn->query("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT,
        product VARCHAR(255),
        amount BIGINT,
        status VARCHAR(50),
        type VARCHAR(50),
        payment_status VARCHAR(50),
        transaction_id VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $conn->query("INSERT IGNORE INTO settings (`key`, `value`) VALUES 
        ('stars_price', '2000'),
        ('premium_3m_price', '150000'),
        ('premium_6m_price', '280000'),
        ('premium_12m_price', '500000')
    ");
    
    echo json_encode(["success" => true, "message" => "Database initialized"]);
    $conn->close();
    exit;
}

echo json_encode(["success" => false, "message" => "Noto'g'ri so'rov"]);
$conn->close();
exit;
?>