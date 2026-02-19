<?php
/**
 * FINAL ELITE — Single index.php (Webhook)
 * ✅ Supabase PostgreSQL (PDO pgsql) — NO mysqli
 * ✅ Render compatible (listens via Apache; see Dockerfile+start.sh you already have)
 * ✅ User Panel: Add Diamonds (Amazon/UPI), Buy Coupon, My Orders, Balance
 * ✅ Admin Panel: Stock, Change Price, Get Free Code, Add Coupon, Remove Coupon
 * ✅ Admin Approve/Decline deposits (inline buttons) with screenshot proof
 *
 * REQUIRED TABLES (PostgreSQL / Supabase) — run once in Supabase SQL editor:
 *
 * CREATE TABLE IF NOT EXISTS users (
 *   id SERIAL PRIMARY KEY,
 *   telegram_id BIGINT UNIQUE,
 *   username TEXT,
 *   diamonds INTEGER DEFAULT 0,
 *   state TEXT,
 *   temp TEXT
 * );
 *
 * CREATE TABLE IF NOT EXISTS deposits (
 *   id SERIAL PRIMARY KEY,
 *   telegram_id BIGINT,
 *   method TEXT,
 *   diamonds INTEGER,
 *   amount INTEGER,
 *   screenshot TEXT,
 *   status TEXT DEFAULT 'pending',
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE IF NOT EXISTS coupons (
 *   id SERIAL PRIMARY KEY,
 *   type TEXT,
 *   code TEXT,
 *   is_used INTEGER DEFAULT 0
 * );
 *
 * CREATE TABLE IF NOT EXISTS orders (
 *   id SERIAL PRIMARY KEY,
 *   telegram_id BIGINT,
 *   type TEXT,
 *   quantity INTEGER,
 *   total_cost INTEGER,
 *   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE IF NOT EXISTS prices (
 *   type TEXT PRIMARY KEY,
 *   cost INTEGER
 * );
 *
 * INSERT INTO prices (type, cost) VALUES
 * ('500',50),('1k',100),('2k',200),('4k',400)
 * ON CONFLICT (type) DO NOTHING;
 */

// ===================== CONFIG =====================
$BOT_TOKEN = "YOUR_BOT_TOKEN_HERE";

// Multiple admins supported:
$ADMIN_IDS = [123456789]; // put your admin Telegram IDs here

// Supabase DATABASE_URL (preferred):
// Example: postgresql://user:pass@host:5432/postgres
$DATABASE_URL = "postgresql://postgres.xxxxx:YOURPASS@aws-1-xxxx.pooler.supabase.com:5432/postgres";

// Minimum diamonds for deposits
$MIN_DIAMONDS = 10;

// Rate message
$RATE_TEXT = "💹 Rate: 1 Rs = 1 Diamond 💎";

// ===================== BASIC WEB HEALTH =====================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  http_response_code(200);
  echo "OK";
  exit;
}

// ===================== TELEGRAM HELPERS =====================
function bot($method, $data = []) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function is_admin($tg_id) {
  global $ADMIN_IDS;
  return in_array((int)$tg_id, array_map('intval', $ADMIN_IDS), true);
}

function now_text() {
  // You can change format if you want
  return date("d M Y, h:i A");
}

// ===================== DB (PDO pgsql) =====================
function pdo() {
  static $pdo = null;
  global $DATABASE_URL;

  if ($pdo) return $pdo;

  $u = parse_url($DATABASE_URL);
  $host = $u['host'] ?? '';
  $port = $u['port'] ?? 5432;
  $db   = ltrim($u['path'] ?? '/postgres', '/');
  $user = $u['user'] ?? '';
  $pass = $u['pass'] ?? '';

  // Supabase pooler requires SSL
  $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
  return $pdo;
}

function db_one($sql, $params = []) {
  $st = pdo()->prepare($sql);
  $st->execute($params);
  $row = $st->fetch();
  return $row ?: null;
}

function db_all($sql, $params = []) {
  $st = pdo()->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

function db_exec($sql, $params = []) {
  $st = pdo()->prepare($sql);
  $st->execute($params);
  return $st->rowCount();
}

// ===================== USER STATE =====================
function ensure_user($tg_id, $username) {
  $row = db_one("SELECT telegram_id FROM users WHERE telegram_id = :tg", [":tg" => $tg_id]);
  if (!$row) {
    db_exec("INSERT INTO users (telegram_id, username, diamonds) VALUES (:tg, :u, 0)", [
      ":tg" => $tg_id,
      ":u"  => $username
    ]);
  } else {
    // keep username fresh
    db_exec("UPDATE users SET username = :u WHERE telegram_id = :tg", [
      ":tg" => $tg_id,
      ":u"  => $username
    ]);
  }
}

function set_state($tg_id, $state = null, $temp = null) {
  db_exec("UPDATE users SET state = :s, temp = :t WHERE telegram_id = :tg", [
    ":tg" => $tg_id,
    ":s"  => $state,
    ":t"  => $temp === null ? null : json_encode($temp, JSON_UNESCAPED_UNICODE)
  ]);
}

function get_state($tg_id) {
  $row = db_one("SELECT state, temp FROM users WHERE telegram_id = :tg", [":tg" => $tg_id]);
  if (!$row) return ["state" => null, "temp" => null];
  $temp = null;
  if (!empty($row["temp"])) {
    $decoded = json_decode($row["temp"], true);
    if (json_last_error() === JSON_ERROR_NONE) $temp = $decoded;
  }
  return ["state" => $row["state"], "temp" => $temp];
}

function user_balance($tg_id) {
  $row = db_one("SELECT diamonds FROM users WHERE telegram_id = :tg", [":tg" => $tg_id]);
  return (int)($row["diamonds"] ?? 0);
}

// ===================== UI =====================
function main_menu($chat_id) {
  bot("sendMessage", [
    "chat_id" => $chat_id,
    "text" => "Welcome 💎\n\nChoose an option:",
    "reply_markup" => json_encode([
      "keyboard" => [
        [["💰 Add Diamonds"], ["🛒 Buy Coupon"]],
        [["📦 My Orders"], ["💎 Balance"]],
      ],
      "resize_keyboard" => true
    ], JSON_UNESCAPED_UNICODE)
  ]);
}

function admin_menu($chat_id) {
  bot("sendMessage", [
    "chat_id" => $chat_id,
    "text" => "Admin Panel 👑",
    "reply_markup" => json_encode([
      "keyboard" => [
        [["📊 Stock"], ["💰 Change Price"]],
        [["🎁 Get Free Code"], ["➕ Add Coupon"]],
        [["➖ Remove Coupon"], ["📌 Pending Deposits"]],
        [["⬅ Back to User Menu"]],
      ],
      "resize_keyboard" => true
    ], JSON_UNESCAPED_UNICODE)
  ]);
}

function payment_method_prompt($chat_id) {
  bot("sendMessage", [
    "chat_id" => $chat_id,
    "text" => "💳 Select Payment Method:\n\n⚠️ Under Maintenance:\n\nPlease use other methods for deposit.",
    "reply_markup" => json_encode([
      "inline_keyboard" => [
        [["text" => "🛍 Amazon Gift Card", "callback_data" => "dep_method:amazon"]],
        [["text" => "💳 UPI", "callback_data" => "dep_method:upi"]],
      ]
    ], JSON_UNESCAPED_UNICODE)
  ]);
}

function coupon_type_buttons() {
  $rows = db_all("SELECT type, cost FROM prices ORDER BY cost ASC");
  $kb = [];
  foreach ($rows as $r) {
    $type = $r["type"];
    $cost = (int)$r["cost"];
    $stock = (int)(db_one("SELECT COUNT(*) AS c FROM coupons WHERE type = :t AND is_used = 0", [":t" => $type])["c"] ?? 0);
    $label = "{$type} (Cost: {$cost}💎 | Stock: {$stock})";
    $kb[] = [["text" => $label, "callback_data" => "buy_type:{$type}"]];
  }
  return $kb ?: [[["text" => "No types configured", "callback_data" => "noop"]]];
}

// ===================== UPDATE INPUT =====================
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { http_response_code(200); echo "OK"; exit; }

$message  = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

// ===================== CALLBACK HANDLER =====================
if ($callback) {
  $cb_id = $callback["id"];
  $data  = $callback["data"] ?? "";
  $from  = $callback["from"] ?? [];
  $tg_id = (int)($from["id"] ?? 0);
  $username = $from["username"] ?? "user";
  ensure_user($tg_id, $username);

  // Always answer callback quickly
  bot("answerCallbackQuery", ["callback_query_id" => $cb_id]);

  // Deposit method select
  if (strpos($data, "dep_method:") === 0) {
    $method = substr($data, strlen("dep_method:"));
    set_state($tg_id, "dep_enter_diamonds", ["method" => $method]);
    bot("sendMessage", [
      "chat_id" => $tg_id,
      "text" => "Enter the number of diamonds to add (Minimum {$GLOBALS['MIN_DIAMONDS']})\n(Method: " . strtoupper($method) . "):"
    ]);
    http_response_code(200); echo "OK"; exit;
  }

  // Deposit submit button
  if (strpos($data, "dep_submit:") === 0) {
    $method = substr($data, strlen("dep_submit:"));
    $st = get_state($tg_id);
    $temp = $st["temp"] ?? [];
    $diamonds = (int)($temp["diamonds"] ?? 0);
    if ($diamonds < (int)$GLOBALS["MIN_DIAMONDS"]) {
      bot("sendMessage", ["chat_id" => $tg_id, "text" => "❌ Invalid diamonds amount. Please try again."]);
      set_state($tg_id, null, null);
      http_response_code(200); echo "OK"; exit;
    }

    if ($method === "amazon") {
      set_state($tg_id, "dep_amazon_amount", ["method" => "amazon", "diamonds" => $diamonds]);
      bot("sendMessage", [
        "chat_id" => $tg_id,
        "text" => "Enter your Amazon Gift Card Amount for {$diamonds}:"
      ]);
    } elseif ($method === "upi") {
      set_state($tg_id, "dep_upi_screenshot", ["method" => "upi", "diamonds" => $diamonds, "amount" => $diamonds]);
      bot("sendMessage", [
        "chat_id" => $tg_id,
        "text" => "📸 Now upload a screenshot of the UPI payment:"
      ]);
    }
    http_response_code(200); echo "OK"; exit;
  }

  // Buy coupon type
  if (strpos($data, "buy_type:") === 0) {
    $type = substr($data, strlen("buy_type:"));
    set_state($tg_id, "buy_enter_qty", ["type" => $type]);
    bot("sendMessage", [
      "chat_id" => $tg_id,
      "text" => "How many {$type} coupons do you want to buy?\nPlease send the quantity:"
    ]);
    http_response_code(200); echo "OK"; exit;
  }

  // Admin actions
  if (is_admin($tg_id)) {
    if (strpos($data, "admin_price_type:") === 0) {
      $type = substr($data, strlen("admin_price_type:"));
      set_state($tg_id, "admin_enter_new_price", ["type" => $type]);
      bot("sendMessage", ["chat_id" => $tg_id, "text" => "Send new price (diamonds cost) for {$type}:"]);
      http_response_code(200); echo "OK"; exit;
    }

    if (strpos($data, "admin_free_type:") === 0) {
      $type = substr($data, strlen("admin_free_type:"));
      $row = db_one("SELECT id, code FROM coupons WHERE type = :t AND is_used = 0 ORDER BY id ASC LIMIT 1", [":t" => $type]);
      if (!$row) {
        bot("sendMessage", ["chat_id" => $tg_id, "text" => "❌ No stock for {$type}."]);
      } else {
        db_exec("UPDATE coupons SET is_used = 1 WHERE id = :id", [":id" => $row["id"]]);
        bot("sendMessage", ["chat_id" => $tg_id, "text" => "🎁 Free {$type} code:\n\n{$row['code']}"]);
      }
      http_response_code(200); echo "OK"; exit;
    }

    if (strpos($data, "admin_add_type:") === 0) {
      $type = substr($data, strlen("admin_add_type:"));
      set_state($tg_id, "admin_add_codes", ["type" => $type]);
      bot("sendMessage", ["chat_id" => $tg_id, "text" => "Send {$type} coupons (one per line):"]);
      http_response_code(200); echo "OK"; exit;
    }

    if (strpos($data, "admin_remove_type:") === 0) {
      $type = substr($data, strlen("admin_remove_type:"));
      set_state($tg_id, "admin_remove_qty", ["type" => $type]);
      bot("sendMessage", ["chat_id" => $tg_id, "text" => "How many unused {$type} coupons to remove? Send a number:"]);
      http_response_code(200); echo "OK"; exit;
    }

    // Approve / Decline deposit
    if (strpos($data, "dep_approve:") === 0 || strpos($data, "dep_decline:") === 0) {
      $isApprove = (strpos($data, "dep_approve:") === 0);
      $depId = (int)substr($data, $isApprove ? strlen("dep_approve:") : strlen("dep_decline:"));

      $pdo = pdo();
      $pdo->beginTransaction();
      try {
        // Lock row
        $st = $pdo->prepare("SELECT * FROM deposits WHERE id = :id FOR UPDATE");
        $st->execute([":id" => $depId]);
        $dep = $st->fetch();

        if (!$dep) {
          $pdo->rollBack();
          bot("sendMessage", ["chat_id" => $tg_id, "text" => "❌ Deposit not found."]);
          http_response_code(200); echo "OK"; exit;
        }

        if ($dep["status"] !== "pending") {
          $pdo->rollBack();
          bot("sendMessage", ["chat_id" => $tg_id, "text" => "⚠️ Already processed (status: {$dep['status']})."]);
          http_response_code(200); echo "OK"; exit;
        }

        $user_tg = (int)$dep["telegram_id"];
        $diamonds = (int)$dep["diamonds"];

        if ($isApprove) {
          $pdo->prepare("UPDATE deposits SET status='approved' WHERE id=:id")->execute([":id"=>$depId]);
          $pdo->prepare("UPDATE users SET diamonds = diamonds + :d WHERE telegram_id = :tg")->execute([
            ":d" => $diamonds,
            ":tg" => $user_tg
          ]);
          $pdo->commit();

          bot("sendMessage", ["chat_id" => $user_tg, "text" => "✅ Payment Approved!\n💎 {$diamonds} Diamonds Added."]);
          bot("sendMessage", ["chat_id" => $tg_id, "text" => "✅ Approved deposit #{$depId}."]);
        } else {
          $pdo->prepare("UPDATE deposits SET status='rejected' WHERE id=:id")->execute([":id"=>$depId]);
          $pdo->commit();

          bot("sendMessage", ["chat_id" => $user_tg, "text" => "❌ Payment Rejected by Admin.\nNo diamonds were added."]);
          bot("sendMessage", ["chat_id" => $tg_id, "text" => "❌ Rejected deposit #{$depId}."]);
        }
      } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        bot("sendMessage", ["chat_id" => $tg_id, "text" => "❌ Error processing deposit."]);
      }

      http_response_code(200); echo "OK"; exit;
    }
  }

  http_response_code(200); echo "OK"; exit;
}

// ===================== MESSAGE HANDLER =====================
if ($message) {
  $chat_id = (int)($message["chat"]["id"] ?? 0);
  $from = $message["from"] ?? [];
  $tg_id = (int)($from["id"] ?? $chat_id);
  $username = $from["username"] ?? "user";

  ensure_user($tg_id, $username);

  $text  = $message["text"] ?? null;
  $photo = $message["photo"] ?? null;

  $st = get_state($tg_id);
  $state = $st["state"];
  $temp  = $st["temp"] ?? [];

  // /start
  if ($text === "/start") {
    set_state($tg_id, null, null);
    main_menu($tg_id);
    http_response_code(200); echo "OK"; exit;
  }

  // /admin
  if ($text === "/admin" && is_admin($tg_id)) {
    set_state($tg_id, null, null);
    admin_menu($tg_id);
    http_response_code(200); echo "OK"; exit;
  }

  // Back to user menu (admin)
  if ($text === "⬅ Back to User Menu" && is_admin($tg_id)) {
    set_state($tg_id, null, null);
    main_menu($tg_id);
    http_response_code(200); echo "OK"; exit;
  }

  // ================= USER MENU ACTIONS =================
  if ($text === "💰 Add Diamonds") {
    set_state($tg_id, null, null);
    payment_method_prompt($tg_id);
    http_response_code(200); echo "OK"; exit;
  }

  if ($text === "💎 Balance") {
    $bal = user_balance($tg_id);
    bot("sendMessage", ["chat_id" => $tg_id, "text" => "💎 Your Current Balance: {$bal} Diamonds"]);
    http_response_code(200); echo "OK"; exit;
  }

  if ($text === "🛒 Buy Coupon") {
    set_state($tg_id, null, null);
    bot("sendMessage", [
      "chat_id" => $tg_id,
      "text" => "Select a coupon type:",
      "reply_markup" => json_encode(["inline_keyboard" => coupon_type_buttons()], JSON_UNESCAPED_UNICODE)
    ]);
    http_response_code(200); echo "OK"; exit;
  }

  if ($text === "📦 My Orders") {
    $orders = db_all("SELECT * FROM orders WHERE telegram_id = :tg ORDER BY id DESC LIMIT 15", [":tg"=>$tg_id]);
    $deps   = db_all("SELECT * FROM deposits WHERE telegram_id = :tg ORDER BY id DESC LIMIT 15", [":tg"=>$tg_id]);

    $out = "📦 *My Orders*\n\n";

    if ($deps) {
      $out .= "*Deposits*\n";
      foreach ($deps as $d) {
        $out .= "• #{$d['id']} — " . strtoupper($d["method"]) . " — {$d['diamonds']}💎 — *{$d['status']}* — {$d['created_at']}\n";
      }
      $out .= "\n";
    }

    if ($orders) {
      $out .= "*Coupon Purchases*\n";
      foreach ($orders as $o) {
        $out .= "• #{$o['id']} — {$o['type']} x{$o['quantity']} — {$o['total_cost']}💎 — {$o['created_at']}\n";
      }
    }

    if (!$deps && !$orders) $out .= "No orders yet.";

    bot("sendMessage", [
      "chat_id" => $tg_id,
      "text" => $out,
      "parse_mode" => "Markdown"
    ]);
    http_response_code(200); echo "OK"; exit;
  }

  // ================= ADMIN MENU ACTIONS =================
  if (is_admin($tg_id)) {
    if ($text === "📊 Stock") {
      $rows = db_all("SELECT type, cost FROM prices ORDER BY cost ASC");
      $out = "📊 *Stock & Prices*\n\n";
      foreach ($rows as $r) {
        $type = $r["type"];
        $cost = (int)$r["cost"];
        $stock = (int)(db_one("SELECT COUNT(*) AS c FROM coupons WHERE type=:t AND is_used=0", [":t"=>$type])["c"] ?? 0);
        $out .= "• {$type} — Cost: {$cost}💎 — Stock: {$stock}\n";
      }
      bot("sendMessage", ["chat_id"=>$tg_id, "text"=>$out, "parse_mode"=>"Markdown"]);
      http_response_code(200); echo "OK"; exit;
    }

    if ($text === "💰 Change Price") {
      $rows = db_all("SELECT type, cost FROM prices ORDER BY cost ASC");
      $kb = [];
      foreach ($rows as $r) {
        $kb[] = [["text" => "{$r['type']} (current: {$r['cost']}💎)", "callback_data" => "admin_price_type:{$r['type']}"]];
      }
      bot("sendMessage", [
        "chat_id"=>$tg_id,
        "text"=>"Choose coupon type to change price:",
        "reply_markup"=>json_encode(["inline_keyboard"=>$kb], JSON_UNESCAPED_UNICODE)
      ]);
      http_response_code(200); echo "OK"; exit;
    }

    if ($text === "🎁 Get Free Code") {
      $rows = db_all("SELECT type FROM prices ORDER BY cost ASC");
      $kb = [];
      foreach ($rows as $r) {
        $kb[] = [["text"=>$r["type"], "callback_data"=>"admin_free_type:{$r['type']}"]];
      }
      bot("sendMessage", [
        "chat_id"=>$tg_id,
        "text"=>"Select type to get 1 free code:",
        "reply_markup"=>json_encode(["inline_keyboard"=>$kb], JSON_UNESCAPED_UNICODE)
      ]);
      http_response_code(200); echo "OK"; exit;
    }

    if ($text === "➕ Add Coupon") {
      $rows = db_all("SELECT type FROM prices ORDER BY cost ASC");
      $kb = [];
      foreach ($rows as $r) $kb[] = [["text"=>$r["type"], "callback_data"=>"admin_add_type:{$r['type']}"]];
      bot("sendMessage", [
        "chat_id"=>$tg_id,
        "text"=>"Select type to add coupons:",
        "reply_markup"=>json_encode(["inline_keyboard"=>$kb], JSON_UNESCAPED_UNICODE)
      ]);
      http_response_code(200); echo "OK"; exit;
    }

    if ($text === "➖ Remove Coupon") {
      $rows = db_all("SELECT type FROM prices ORDER BY cost ASC");
      $kb = [];
      foreach ($rows as $r) $kb[] = [["text"=>$r["type"], "callback_data"=>"admin_remove_type:{$r['type']}"]];
      bot("sendMessage", [
        "chat_id"=>$tg_id,
        "text"=>"Select type to remove coupons:",
        "reply_markup"=>json_encode(["inline_keyboard"=>$kb], JSON_UNESCAPED_UNICODE)
      ]);
      http_response_code(200); echo "OK"; exit;
    }

    if ($text === "📌 Pending Deposits") {
      $deps = db_all("SELECT * FROM deposits WHERE status='pending' ORDER BY id DESC LIMIT 10");
      if (!$deps) {
        bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"✅ No pending deposits."]);
        http_response_code(200); echo "OK"; exit;
      }
      foreach ($deps as $d) {
        $cap = "🧾 Pending Deposit #{$d['id']}\n"
          . "👤 User: {$d['telegram_id']}\n"
          . "💳 Method: " . strtoupper($d['method']) . "\n"
          . "💵 Amount: {$d['amount']}\n"
          . "💎 Diamonds: {$d['diamonds']}\n"
          . "🕒 Time: {$d['created_at']}";
        if (!empty($d["screenshot"])) {
          bot("sendPhoto", [
            "chat_id"=>$tg_id,
            "photo"=>$d["screenshot"],
            "caption"=>$cap,
            "reply_markup"=>json_encode([
              "inline_keyboard"=>[
                [
                  ["text"=>"✅ Accept", "callback_data"=>"dep_approve:{$d['id']}"],
                  ["text"=>"❌ Decline", "callback_data"=>"dep_decline:{$d['id']}"],
                ]
              ]
            ], JSON_UNESCAPED_UNICODE)
          ]);
        } else {
          bot("sendMessage", [
            "chat_id"=>$tg_id,
            "text"=>$cap,
            "reply_markup"=>json_encode([
              "inline_keyboard"=>[
                [
                  ["text"=>"✅ Accept", "callback_data"=>"dep_approve:{$d['id']}"],
                  ["text"=>"❌ Decline", "callback_data"=>"dep_decline:{$d['id']}"],
                ]
              ]
            ], JSON_UNESCAPED_UNICODE)
          ]);
        }
      }
      http_response_code(200); echo "OK"; exit;
    }
  }

  // ================= STATE FLOWS =================

  // Enter diamonds for deposit
  if ($state === "dep_enter_diamonds" && $text !== null) {
    $method = $temp["method"] ?? "";
    $diamonds = (int)trim($text);

    if ($diamonds < (int)$GLOBALS["MIN_DIAMONDS"]) {
      bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Minimum is {$GLOBALS['MIN_DIAMONDS']} diamonds. Send again:"]);
      http_response_code(200); echo "OK"; exit;
    }

    // Save diamonds in temp
    set_state($tg_id, "dep_summary", ["method"=>$method, "diamonds"=>$diamonds]);

    $time = now_text();
    $summary =
      "📝 Order Summary:\n━━━━━━━━━━━━━━━\n"
      . "{$GLOBALS['RATE_TEXT']}\n"
      . "💵 Amount: {$diamonds}\n"
      . "💎 Diamonds to Receive: {$diamonds} 💎\n"
      . "💳 Method: " . ($method === "amazon" ? "Amazon Gift Card" : "UPI") . "\n"
      . "📅 Time: {$time}\n"
      . "━━━━━━━━━━━━━━━\n\n"
      . "Click below to proceed.";

    bot("sendMessage", [
      "chat_id"=>$tg_id,
      "text"=>$summary,
      "reply_markup"=>json_encode([
        "inline_keyboard"=>[
          [["text"=>($method==="amazon" ? "📤 Submit a Gift Card" : "📤 Submit UPI Payment"), "callback_data"=>"dep_submit:{$method}"]],
        ]
      ], JSON_UNESCAPED_UNICODE)
    ]);

    http_response_code(200); echo "OK"; exit;
  }

  // Amazon: enter gift card amount
  if ($state === "dep_amazon_amount" && $text !== null) {
    $diamonds = (int)($temp["diamonds"] ?? 0);
    $amount = (int)trim($text);
    if ($amount <= 0) {
      bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Please send a valid amount number."]);
      http_response_code(200); echo "OK"; exit;
    }
    set_state($tg_id, "dep_amazon_screenshot", ["method"=>"amazon", "diamonds"=>$diamonds, "amount"=>$amount]);
    bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"📸 Now upload a screenshot of the gift card:"]);
    http_response_code(200); echo "OK"; exit;
  }

  // Amazon: screenshot
  if ($state === "dep_amazon_screenshot" && $photo) {
    $file_id = end($photo)["file_id"];
    $diamonds = (int)($temp["diamonds"] ?? 0);
    $amount = (int)($temp["amount"] ?? $diamonds);

    db_exec("INSERT INTO deposits (telegram_id, method, diamonds, amount, screenshot, status)
             VALUES (:tg,'amazon',:d,:a,:s,'pending')", [
      ":tg"=>$tg_id, ":d"=>$diamonds, ":a"=>$amount, ":s"=>$file_id
    ]);
    $dep = db_one("SELECT id FROM deposits WHERE telegram_id=:tg ORDER BY id DESC LIMIT 1", [":tg"=>$tg_id]);
    $depId = (int)($dep["id"] ?? 0);

    bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"✅ Admin is checking your code.\nPlease wait for approval."]);

    $cap =
      "🆕 New Amazon Deposit Request\n\n"
      . "👤 User: @{$username}\n"
      . "🆔 ID: {$tg_id}\n"
      . "💎 Diamonds: {$diamonds}\n"
      . "💵 Amount: {$amount}\n"
      . "🕒 Time: " . now_text() . "\n"
      . "🔢 Deposit #: {$depId}";

    foreach ($GLOBALS["ADMIN_IDS"] as $adminId) {
      bot("sendPhoto", [
        "chat_id" => $adminId,
        "photo" => $file_id,
        "caption" => $cap,
        "reply_markup" => json_encode([
          "inline_keyboard" => [
            [
              ["text"=>"✅ Accept", "callback_data"=>"dep_approve:{$depId}"],
              ["text"=>"❌ Decline", "callback_data"=>"dep_decline:{$depId}"],
            ]
          ]
        ], JSON_UNESCAPED_UNICODE)
      ]);
    }

    set_state($tg_id, null, null);
    http_response_code(200); echo "OK"; exit;
  }

  // UPI: screenshot
  if ($state === "dep_upi_screenshot" && $photo) {
    $file_id = end($photo)["file_id"];
    $diamonds = (int)($temp["diamonds"] ?? 0);
    $amount = (int)($temp["amount"] ?? $diamonds);

    db_exec("INSERT INTO deposits (telegram_id, method, diamonds, amount, screenshot, status)
             VALUES (:tg,'upi',:d,:a,:s,'pending')", [
      ":tg"=>$tg_id, ":d"=>$diamonds, ":a"=>$amount, ":s"=>$file_id
    ]);
    $dep = db_one("SELECT id FROM deposits WHERE telegram_id=:tg ORDER BY id DESC LIMIT 1", [":tg"=>$tg_id]);
    $depId = (int)($dep["id"] ?? 0);

    bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"✅ Admin is checking your payment.\nPlease wait for approval."]);

    $cap =
      "🆕 New UPI Deposit Request\n\n"
      . "👤 User: @{$username}\n"
      . "🆔 ID: {$tg_id}\n"
      . "💎 Diamonds: {$diamonds}\n"
      . "💵 Amount: {$amount}\n"
      . "🕒 Time: " . now_text() . "\n"
      . "🔢 Deposit #: {$depId}";

    foreach ($GLOBALS["ADMIN_IDS"] as $adminId) {
      bot("sendPhoto", [
        "chat_id" => $adminId,
        "photo" => $file_id,
        "caption" => $cap,
        "reply_markup" => json_encode([
          "inline_keyboard" => [
            [
              ["text"=>"✅ Accept", "callback_data"=>"dep_approve:{$depId}"],
              ["text"=>"❌ Decline", "callback_data"=>"dep_decline:{$depId}"],
            ]
          ]
        ], JSON_UNESCAPED_UNICODE)
      ]);
    }

    set_state($tg_id, null, null);
    http_response_code(200); echo "OK"; exit;
  }

  // Buy: enter qty
  if ($state === "buy_enter_qty" && $text !== null) {
    $type = $temp["type"] ?? null;
    $qty = (int)trim($text);

    if (!$type || $qty <= 0) {
      bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Send a valid quantity number."]);
      http_response_code(200); echo "OK"; exit;
    }

    $priceRow = db_one("SELECT cost FROM prices WHERE type = :t", [":t"=>$type]);
    if (!$priceRow) {
      bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Invalid coupon type."]);
      set_state($tg_id, null, null);
      http_response_code(200); echo "OK"; exit;
    }

    $cost = (int)$priceRow["cost"];
    $need = $cost * $qty;

    $stock = (int)(db_one("SELECT COUNT(*) AS c FROM coupons WHERE type=:t AND is_used=0", [":t"=>$type])["c"] ?? 0);
    if ($qty > $stock) {
      bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Not enough stock! Available: {$stock}"]);
      set_state($tg_id, null, null);
      http_response_code(200); echo "OK"; exit;
    }

    $bal = user_balance($tg_id);
    if ($bal < $need) {
      bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Not enough diamonds!\nNeeded: {$need} | You have: {$bal}"]);
      set_state($tg_id, null, null);
      http_response_code(200); echo "OK"; exit;
    }

    // Transaction: deduct + mark coupons used + create order
    $pdo = pdo();
    $pdo->beginTransaction();
    try {
      // Deduct balance
      $st1 = $pdo->prepare("UPDATE users SET diamonds = diamonds - :n WHERE telegram_id = :tg AND diamonds >= :n");
      $st1->execute([":n"=>$need, ":tg"=>$tg_id]);
      if ($st1->rowCount() !== 1) {
        $pdo->rollBack();
        bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Not enough diamonds (race condition). Try again."]);
        set_state($tg_id, null, null);
        http_response_code(200); echo "OK"; exit;
      }

      // Get coupons to deliver
      $st2 = $pdo->prepare("SELECT id, code FROM coupons WHERE type=:t AND is_used=0 ORDER BY id ASC LIMIT {$qty} FOR UPDATE");
      $st2->execute([":t"=>$type]);
      $rows = $st2->fetchAll();

      if (count($rows) < $qty) {
        $pdo->rollBack();
        bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Not enough stock (race condition). Try again."]);
        set_state($tg_id, null, null);
        http_response_code(200); echo "OK"; exit;
      }

      $codes = [];
      $ids = [];
      foreach ($rows as $r) { $codes[] = $r["code"]; $ids[] = (int)$r["id"]; }

      // Mark used
      $st3 = $pdo->prepare("UPDATE coupons SET is_used=1 WHERE id = ANY(:ids)");
      // PDO pgsql needs array as string like {1,2,3}
      $arr = "{" . implode(",", $ids) . "}";
      $st3->execute([":ids"=>$arr]);

      // Save order
      $st4 = $pdo->prepare("INSERT INTO orders (telegram_id, type, quantity, total_cost) VALUES (:tg,:t,:q,:c)");
      $st4->execute([":tg"=>$tg_id, ":t"=>$type, ":q"=>$qty, ":c"=>$need]);

      $pdo->commit();

      bot("sendMessage", [
        "chat_id"=>$tg_id,
        "text"=>"🎉 Purchase Successful!\n\n" . implode("\n", $codes)
      ]);

    } catch (Exception $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Purchase failed. Try again."]);
    }

    set_state($tg_id, null, null);
    http_response_code(200); echo "OK"; exit;
  }

  // ================= ADMIN STATE FLOWS =================
  if (is_admin($tg_id)) {
    // Admin enter new price
    if ($state === "admin_enter_new_price" && $text !== null) {
      $type = $temp["type"] ?? null;
      $new = (int)trim($text);
      if (!$type || $new <= 0) {
        bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Send a valid price number."]);
        http_response_code(200); echo "OK"; exit;
      }
      db_exec("UPDATE prices SET cost = :c WHERE type = :t", [":c"=>$new, ":t"=>$type]);
      bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"✅ Updated price for {$type} to {$new}💎"]);
      set_state($tg_id, null, null);
      http_response_code(200); echo "OK"; exit;
    }

    // Admin add codes (one per line)
    if ($state === "admin_add_codes" && $text !== null) {
      $type = $temp["type"] ?? null;
      if (!$type) { set_state($tg_id, null, null); http_response_code(200); echo "OK"; exit; }

      $lines = preg_split("/\r\n|\n|\r/", trim($text));
      $codes = [];
      foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln !== "") $codes[] = $ln;
      }

      if (!$codes) {
        bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ No codes found. Send again (one per line)."]);
        http_response_code(200); echo "OK"; exit;
      }

      $pdo = pdo();
      $pdo->beginTransaction();
      try {
        $st = $pdo->prepare("INSERT INTO coupons (type, code, is_used) VALUES (:t, :c, 0)");
        $added = 0;
        foreach ($codes as $c) {
          $st->execute([":t"=>$type, ":c"=>$c]);
          $added++;
        }
        $pdo->commit();
        bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"✅ Added {$added} coupons to {$type}."]);
      } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Failed to add coupons."]);
      }

      set_state($tg_id, null, null);
      http_response_code(200); echo "OK"; exit;
    }

    // Admin remove qty
    if ($state === "admin_remove_qty" && $text !== null) {
      $type = $temp["type"] ?? null;
      $qty = (int)trim($text);
      if (!$type || $qty <= 0) {
        bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Send a valid number."]);
        http_response_code(200); echo "OK"; exit;
      }

      $pdo = pdo();
      $pdo->beginTransaction();
      try {
        $st = $pdo->prepare("SELECT id FROM coupons WHERE type=:t AND is_used=0 ORDER BY id DESC LIMIT {$qty} FOR UPDATE");
        $st->execute([":t"=>$type]);
        $rows = $st->fetchAll();
        if (!$rows) {
          $pdo->rollBack();
          bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ No unused coupons to remove for {$type}."]);
          set_state($tg_id, null, null);
          http_response_code(200); echo "OK"; exit;
        }
        $ids = array_map(fn($r) => (int)$r["id"], $rows);
        $arr = "{" . implode(",", $ids) . "}";
        $pdo->prepare("DELETE FROM coupons WHERE id = ANY(:ids)")->execute([":ids"=>$arr]);
        $pdo->commit();
        bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"✅ Removed ".count($ids)." unused coupons from {$type}."]);
      } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"❌ Failed to remove coupons."]);
      }

      set_state($tg_id, null, null);
      http_response_code(200); echo "OK"; exit;
    }
  }

  // If user sent photo but not in correct state
  if ($photo && !in_array($state, ["dep_amazon_screenshot","dep_upi_screenshot"], true)) {
    bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"⚠️ I wasn't expecting a screenshot now. Use 💰 Add Diamonds to start a deposit."]);
    http_response_code(200); echo "OK"; exit;
  }

  // Default fallback
  if ($text !== null) {
    bot("sendMessage", ["chat_id"=>$tg_id, "text"=>"Use /start to open menu."]);
  }

  http_response_code(200);
  echo "OK";
  exit;
}

http_response_code(200);
echo "OK";
