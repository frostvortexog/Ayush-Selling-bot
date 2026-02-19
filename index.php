<?php

// ================= CONFIG =================
$BOT_TOKEN = "8455750320:AAHB5NrVyKH_fTR7AFr4hZCadyK-O0k8Jxk";
$ADMIN_ID = 8135256584;

$DB_HOST = "aws-1-ap-southeast-2.pooler.supabase.com";
$DB_USER = "postgres.dmwkpbyynjngjlpuyfog";
$DB_PASS = "RadheyRadhe";
$DB_NAME = "postgres";

// ================= CONNECT =================
$conn = new mysqli($DB_HOST,$DB_USER,$DB_PASS,$DB_NAME);
if($conn->connect_error) exit;

// ================= BOT FUNCTION =================
function bot($method,$data=[]){
    global $BOT_TOKEN;
    $ch = curl_init("https://api.telegram.org/bot$BOT_TOKEN/$method");
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
    curl_exec($ch);
}

// ================= GET UPDATE =================
$update=json_decode(file_get_contents("php://input"),true);
$message=$update['message']??null;
$callback=$update['callback_query']??null;

// ================= USER HELPER =================
function getUser($id,$username){
    global $conn;
    $res=$conn->query("SELECT * FROM users WHERE telegram_id=$id");
    if($res->num_rows==0){
        $conn->query("INSERT INTO users (telegram_id,username) VALUES ($id,'$username')");
    }
}

function setState($id,$state,$temp=null){
    global $conn;
    $temp=$temp?json_encode($temp):NULL;
    $conn->query("UPDATE users SET state='$state', temp='$temp' WHERE telegram_id=$id");
}

function getState($id){
    global $conn;
    $res=$conn->query("SELECT state,temp FROM users WHERE telegram_id=$id");
    return $res->fetch_assoc();
}

function mainMenu($chat_id){
    bot("sendMessage",[
        "chat_id"=>$chat_id,
        "text"=>"Welcome 💎",
        "reply_markup"=>json_encode([
            "keyboard"=>[
                [["💰 Add Diamonds"],["🛒 Buy Coupon"]],
                [["📦 My Orders"],["💎 Balance"]]
            ],
            "resize_keyboard"=>true
        ])
    ]);
}

// ================= MESSAGE =================
if($message){

    $chat_id=$message['chat']['id'];
    $text=$message['text']??null;
    $username=$message['from']['username']??"user";
    $photo=$message['photo']??null;

    getUser($chat_id,$username);
    $state=getState($chat_id);
    $current_state=$state['state'];
    $temp=json_decode($state['temp'],true);

    if($text=="/start"){
        setState($chat_id,NULL);
        mainMenu($chat_id);
    }

    // BALANCE
    elseif($text=="💎 Balance"){
        $bal=$conn->query("SELECT diamonds FROM users WHERE telegram_id=$chat_id")->fetch_assoc()['diamonds'];
        bot("sendMessage",["chat_id"=>$chat_id,"text"=>"💎 Balance: $bal"]);
    }

    // ================= ADD DIAMONDS =================
    elseif($text=="💰 Add Diamonds"){
        bot("sendMessage",[
            "chat_id"=>$chat_id,
            "text"=>"💳 Select Payment Method:",
            "reply_markup"=>json_encode([
                "inline_keyboard"=>[
                    [["text"=>"🛍 Amazon","callback_data"=>"amazon"]],
                    [["text"=>"💳 UPI","callback_data"=>"upi"]]
                ]
            ])
        ]);
    }

    // AMAZON ENTER DIAMONDS
    elseif($current_state=="amazon_diamonds"){
        if($text>=10){
            setState($chat_id,"amazon_amount",["diamonds"=>$text]);
            bot("sendMessage",["chat_id"=>$chat_id,"text"=>"Enter Amazon Gift Card Amount for $text:"]);
        }else{
            bot("sendMessage",["chat_id"=>$chat_id,"text"=>"Minimum 10 diamonds"]);
        }
    }

    // AMAZON ENTER AMOUNT
    elseif($current_state=="amazon_amount"){
        $diamonds=$temp['diamonds'];
        setState($chat_id,"amazon_screenshot",["diamonds"=>$diamonds,"amount"=>$text]);
        bot("sendMessage",["chat_id"=>$chat_id,"text"=>"📸 Upload screenshot"]);
    }

    // AMAZON SCREENSHOT
    elseif($current_state=="amazon_screenshot" && $photo){
        $file_id=end($photo)['file_id'];
        $diamonds=$temp['diamonds'];
        $amount=$temp['amount'];

        $conn->query("INSERT INTO deposits (telegram_id,method,diamonds,amount,screenshot)
        VALUES ($chat_id,'amazon',$diamonds,$amount,'$file_id')");

        $dep_id=$conn->insert_id;

        bot("sendMessage",["chat_id"=>$chat_id,"text"=>"⏳ Waiting for admin approval"]);

        bot("sendPhoto",[
            "chat_id"=>$ADMIN_ID,
            "photo"=>$file_id,
            "caption"=>"New Amazon Deposit\nUser:$chat_id\nDiamonds:$diamonds",
            "reply_markup"=>json_encode([
                "inline_keyboard"=>[
                    [
                        ["text"=>"✅ Accept","callback_data"=>"approve_$dep_id"],
                        ["text"=>"❌ Decline","callback_data"=>"decline_$dep_id"]
                    ]
                ]
            ])
        ]);

        setState($chat_id,NULL);
    }

    // ================= BUY COUPON =================
    elseif($text=="🛒 Buy Coupon"){
        $res=$conn->query("SELECT * FROM prices");
        $btn=[];
        while($row=$res->fetch_assoc()){
            $type=$row['type'];
            $cost=$row['cost'];
            $stock=$conn->query("SELECT COUNT(*) c FROM coupons WHERE type='$type' AND is_used=0")->fetch_assoc()['c'];
            $btn[][]=["text"=>"$type OFF ($cost💎 | Stock:$stock)","callback_data"=>"buy_$type"];
        }
        bot("sendMessage",[
            "chat_id"=>$chat_id,
            "text"=>"Select coupon:",
            "reply_markup"=>json_encode(["inline_keyboard"=>$btn])
        ]);
    }

    elseif($current_state=="buy_qty"){
        $type=$temp['type'];
        $qty=(int)$text;
        $cost=$conn->query("SELECT cost FROM prices WHERE type='$type'")->fetch_assoc()['cost'];
        $total=$cost*$qty;

        $balance=$conn->query("SELECT diamonds FROM users WHERE telegram_id=$chat_id")->fetch_assoc()['diamonds'];
        $stock=$conn->query("SELECT COUNT(*) c FROM coupons WHERE type='$type' AND is_used=0")->fetch_assoc()['c'];

        if($qty>$stock){
            bot("sendMessage",["chat_id"=>$chat_id,"text"=>"❌ Not enough stock"]);
        }elseif($balance<$total){
            bot("sendMessage",["chat_id"=>$chat_id,"text"=>"❌ Not enough diamonds"]);
        }else{
            $conn->query("UPDATE users SET diamonds=diamonds-$total WHERE telegram_id=$chat_id");

            $codes=[];
            $res=$conn->query("SELECT id,code FROM coupons WHERE type='$type' AND is_used=0 LIMIT $qty");
            while($row=$res->fetch_assoc()){
                $codes[]=$row['code'];
                $conn->query("UPDATE coupons SET is_used=1 WHERE id=".$row['id']);
            }

            $conn->query("INSERT INTO orders (telegram_id,type,quantity,total_cost)
            VALUES ($chat_id,'$type',$qty,$total)");

            bot("sendMessage",[
                "chat_id"=>$chat_id,
                "text"=>"🎉 Purchase Successful!\n\n".implode("\n",$codes)
            ]);
        }

        setState($chat_id,NULL);
    }

    // ================= MY ORDERS =================
    elseif($text=="📦 My Orders"){
        $res=$conn->query("SELECT * FROM orders WHERE telegram_id=$chat_id ORDER BY id DESC LIMIT 10");
        $msg="Your Orders:\n";
        while($row=$res->fetch_assoc()){
            $msg.=$row['type']." x".$row['quantity']."\n";
        }
        bot("sendMessage",["chat_id"=>$chat_id,"text"=>$msg]);
    }

}

// ================= CALLBACK =================
if($callback){

    $data=$callback['data'];
    $chat_id=$callback['message']['chat']['id'];

    // AMAZON SELECT
    if($data=="amazon"){
        setState($chat_id,"amazon_diamonds");
        bot("sendMessage",["chat_id"=>$chat_id,"text"=>"Enter diamonds (min 10):"]);
    }

    // UPI SELECT
    if($data=="upi"){
        setState($chat_id,"amazon_diamonds");
        bot("sendMessage",["chat_id"=>$chat_id,"text"=>"Enter diamonds (min 10):"]);
    }

    // BUY TYPE
    if(strpos($data,"buy_")===0){
        $type=str_replace("buy_","",$data);
        setState($chat_id,"buy_qty",["type"=>$type]);
        bot("sendMessage",["chat_id"=>$chat_id,"text"=>"How many $type coupons?"]);
    }

    // APPROVE
    if(strpos($data,"approve_")===0){
        $id=str_replace("approve_","",$data);
        $dep=$conn->query("SELECT * FROM deposits WHERE id=$id")->fetch_assoc();
        $conn->query("UPDATE deposits SET status='approved' WHERE id=$id");
        $conn->query("UPDATE users SET diamonds=diamonds+".$dep['diamonds']." WHERE telegram_id=".$dep['telegram_id']);
        bot("sendMessage",["chat_id"=>$dep['telegram_id'],"text"=>"✅ Approved! Diamonds added"]);
    }

    // DECLINE
    if(strpos($data,"decline_")===0){
        $id=str_replace("decline_","",$data);
        $dep=$conn->query("SELECT telegram_id FROM deposits WHERE id=$id")->fetch_assoc();
        $conn->query("UPDATE deposits SET status='rejected' WHERE id=$id");
        bot("sendMessage",["chat_id"=>$dep['telegram_id'],"text"=>"❌ Deposit Rejected"]);
    }

    bot("answerCallbackQuery",["callback_query_id"=>$callback['id']]);
}
