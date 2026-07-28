<?php
ini_set('display_errors', 0);
error_reporting(0);

$SHOP_KEY = "HRI39VBVLA"; // <-- o'zingiznikini yozing

$conn = new mysqli("localhost", "6a0f286f669d9_sardorstore", "sardorstore", "6a0f286f669d9_sardorstore");
if ($conn->connect_error) { http_response_code(500); exit; }

$input = json_decode(file_get_contents("php://input"), true);

// Xavfsizlik tekshiruvi
if (!isset($input['shopkey']) || $input['shopkey'] !== $SHOP_KEY) {
    http_response_code(403);
    exit;
}

$order_code = $input['order'] ?? '';
$amount     = intval($input['amount'] ?? 0);

if (empty($order_code) || $amount <= 0) {
    http_response_code(400);
    exit;
}

// Bazadan buyurtmani topish
$stmt = $conn->prepare("SELECT * FROM orders WHERE transaction_id = ? AND type = 'finance' AND payment_status != 'paid' LIMIT 1");
$stmt->bind_param("s", $order_code);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(200); // Allaqachon to'langan yoki topilmadi
    echo "ok";
    exit;
}

$user_id = intval($order['user_id']);

// Balansga qo'shish
$upd = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
$upd->bind_param("ii", $amount, $user_id);
$upd->execute();
$upd->close();

// Status yangilash
$upd2 = $conn->prepare("UPDATE orders SET status = 'completed', payment_status = 'paid' WHERE transaction_id = ?");
$upd2->bind_param("s", $order_code);
$upd2->execute();
$upd2->close();

$conn->close();
http_response_code(200);
echo "ok";
?>