<?php
ini_set('display_errors', 0);
error_reporting(0);

$TOKEN = "8471799836:AAHmSZYDxF84XY_Klx3Y4gUU4Kkzs2oZdxE";
$WEBAPP_URL = "https://6a0f286f66ae8.myxvest2.ru/index.html?v=" . time();
$ADMIN_ID = 7977733681;

$update = json_decode(file_get_contents("php://input"), true);

$conn = new mysqli("localhost", "6a0f286f669d9_sardorstore", "sardorstore", "6a0f286f669d9_sardorstore");
if ($conn->connect_error) {
    exit;
}

// 1. INLINE TUGMALAR BOSILGANDA (CALLBACK QUERY)
if (isset($update["callback_query"])) {
    $callback = $update["callback_query"];
    $callback_id = $callback["id"];
    $chat_id = $callback["message"]["chat"]["id"];
    $message_id = $callback["message"]["message_id"];
    $data = $callback["data"];

    // BUYURTMANI TASDIQLASH
    if (strpos($data, "order_approve_") === 0) {
        $order_id = intval(str_replace("order_approve_", "", $data));
        
        $o_stmt = $conn->prepare("SELECT user_id, product FROM orders WHERE id = ? AND status = 'pending'");
        $o_stmt->bind_param("i", $order_id);
        $o_stmt->execute();
        $order = $o_stmt->get_result()->fetch_assoc();
        $o_stmt->close();

        if ($order) {
            $u_id = $order['user_id'];
            $p_name = $order['product'];

            $stmt = $conn->prepare("UPDATE orders SET status = 'completed' WHERE id = ?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $stmt->close();

            editMessageText($chat_id, $message_id, "✅ <b>BUYURTMA TASDIQLANDI</b>\n\n🆔 ID: #$order_id\n📦 Mahsulot: $p_name\nStatus: Muvaffaqiyatli bajarildi!");
            sendMessage($u_id, "✅ Xushxabar! Sizning #$order_id sonli buyurtmangiz (<b>$p_name</b>) muvaffaqiyatli bajarildi!");
        } else {
            editMessageText($chat_id, $message_id, "⚠️ Bu buyurtma allaqachon bajarilgan yoki bekor qilingan!");
        }
    }

    // BUYURTMANI RAD ETISH
    if (strpos($data, "order_cancel_") === 0) {
        $order_id = intval(str_replace("order_cancel_", "", $data));
        
        $conn->begin_transaction();
        try {
            $o_stmt = $conn->prepare("SELECT user_id, amount, product, status FROM orders WHERE id = ?");
            $o_stmt->bind_param("i", $order_id);
            $o_stmt->execute();
            $order = $o_stmt->get_result()->fetch_assoc();
            $o_stmt->close();

            if ($order && $order['status'] === 'pending') {
                $u_id = $order['user_id'];
                $refund_amount = intval($order['amount']);
                $p_name = $order['product'];

                $up_stmt = $conn->prepare("UPDATE users SET balance = balance + ?, spent = GREATEST(0, spent - ?) WHERE id = ?");
                $up_stmt->bind_param("iii", $refund_amount, $refund_amount, $u_id);
                $up_stmt->execute();
                $up_stmt->close();

                $st_stmt = $conn->prepare("UPDATE orders SET status = 'canceled' WHERE id = ?");
                $st_stmt->bind_param("i", $order_id);
                $st_stmt->execute();
                $st_stmt->close();

                $conn->commit();

                editMessageText($chat_id, $message_id, "❌ <b>BUYURTMA RAD ETILDI</b>\n\n🆔 ID: #$order_id\n📦 Mahsulot: $p_name\n💰 Pul miqdori: $refund_amount so'm foydalanuvchiga qaytarildi.");
                sendMessage($u_id, "❌ Sizning #$order_id sonli buyurtmangiz (<b>$p_name</b>) rad etildi va $refund_amount so'm hisobingizga qaytarildi.");
            } else {
                throw new Exception("Buyurtma topilmadi yoki allaqachon qayta ishlangan.");
            }
        } catch (Exception $e) {
            $conn->rollback();
            editMessageText($chat_id, $message_id, "⚠️ Xatolik: " . $e->getMessage());
        }
    }

    $conn->close();
    exit;
}

// 2. MATNLI XABARLAR YOKI /start KELGANDA
if (isset($update["message"])) {
    $message = $update["message"];
    $chat_id = $message["chat"]["id"];
    $text = $message["text"] ?? "";
    $name = $message["from"]["first_name"] ?? "Foydalanuvchi";
    $username = $message["from"]["username"] ?? "";

    if ($text === "/start") {
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->bind_param("i", $chat_id);
        $stmt->execute();
        $user_exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user_exists) {
            $stmt_ins = $conn->prepare("INSERT INTO users (id, name, username, balance, spent) VALUES (?, ?, ?, 0, 0)");
            $stmt_ins->bind_param("iss", $chat_id, $name, $username);
            $stmt_ins->execute();
            $stmt_ins->close();
        }

        $keyboard = [
            "inline_keyboard" => [
                [
                    ["text" => "🚀 Do'konni ochish", "web_app" => ["url" => $WEBAPP_URL]]
                ]
            ]
        ];

        sendMessage($chat_id, "👋 Salom, <b>$name</b>! Do'konimizga xush kelibsiz.\n\nTelegram Premium va Stars xizmatlarini eng arzon narxlarda sotib olishingiz mumkin! Botni ochish tugmasini bosing 👇", $keyboard);
    }
}

$conn->close();
exit;

// FUNKSIYALAR
function sendMessage($chat_id, $text, $keyboard = null) {
    global $TOKEN;
    $url = "https://api.telegram.org/bot$TOKEN/sendMessage";
    $data = ["chat_id" => $chat_id, "text" => $text, "parse_mode" => "HTML"];
    if ($keyboard) $data["reply_markup"] = json_encode($keyboard);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function editMessageText($chat_id, $message_id, $text) {
    global $TOKEN;
    $url = "https://api.telegram.org/bot$TOKEN/editMessageText";
    $data = ["chat_id" => $chat_id, "message_id" => $message_id, "text" => $text, "parse_mode" => "HTML"];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}