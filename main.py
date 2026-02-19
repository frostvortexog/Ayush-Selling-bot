import asyncpg
import io
from datetime import datetime
from fastapi import FastAPI, Request
from telegram import (
    Update,
    InlineKeyboardButton,
    InlineKeyboardMarkup,
    InputFile
)
from telegram.ext import (
    Application,
    CommandHandler,
    CallbackQueryHandler,
    MessageHandler,
    ContextTypes,
    filters,
)

# ================= CONFIG =================
BOT_TOKEN = "8455750320:AAHB5NrVyKH_fTR7AFr4hZCadyK-O0k8Jxk"
DATABASE_URL = "postgresql://postgres.dmwkpbyynjngjlpuyfog:RadheyRadhe@aws-1-ap-southeast-2.pooler.supabase.com:5432/postgres"
WEBHOOK_URL = "https://ayush-selling-bot.onrender.com/webhook"
ADMIN_IDS = [8135256584]
# ==========================================

app = FastAPI()
application = Application.builder().token(BOT_TOKEN).build()
db_pool = None

# ================= DATABASE =================

async def init_db():
    return await asyncpg.create_pool(DATABASE_URL)

async def db_fetchval(q, *a):
    async with db_pool.acquire() as conn:
        return await conn.fetchval(q, *a)

async def db_fetch(q, *a):
    async with db_pool.acquire() as conn:
        return await conn.fetch(q, *a)

async def db_execute(q, *a):
    async with db_pool.acquire() as conn:
        return await conn.execute(q, *a)

# ================= UTIL =================

def is_admin(uid):
    return uid in ADMIN_IDS

def set_state(ctx, state):
    ctx.user_data["state"] = state

def get_state(ctx):
    return ctx.user_data.get("state")

def clear_state(ctx):
    ctx.user_data.clear()

# ================= USER =================

async def create_user(user):
    await db_execute(
        "INSERT INTO users (user_id, username) VALUES ($1,$2) ON CONFLICT DO NOTHING",
        user.id,
        user.username,
    )

async def get_balance(uid):
    return await db_fetchval(
        "SELECT diamonds FROM users WHERE user_id=$1",
        uid,
    )

# ================= MAIN MENU =================

def main_keyboard():
    return InlineKeyboardMarkup([
        [InlineKeyboardButton("💰 Add Coins", callback_data="addcoins")],
        [InlineKeyboardButton("🛒 Buy Coupon", callback_data="buy")],
        [InlineKeyboardButton("📦 My Orders", callback_data="orders")],
        [InlineKeyboardButton("💎 Balance", callback_data="balance")]
    ])

# ================= START =================

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await create_user(update.effective_user)
    await update.message.reply_text(
        "🔥 Elite Enterprise Store 🔥",
        reply_markup=main_keyboard()
    )

# ================= BALANCE =================

async def balance(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    await q.answer()
    bal = await get_balance(q.from_user.id)
    await q.message.edit_text(
        f"💎 Your Balance: {bal}",
        reply_markup=main_keyboard()
    )

# ================= DEPOSIT MENU =================

async def addcoins_menu(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    await q.answer()

    keyboard = InlineKeyboardMarkup([
        [InlineKeyboardButton("🛍 Amazon Gift Card", callback_data="dep_amazon")],
        [InlineKeyboardButton("🏦 UPI Payment", callback_data="dep_upi")]
    ])

    await q.message.edit_text(
        "💳 Select Payment Method:",
        reply_markup=keyboard
    )

# ================= AMAZON / UPI START =================

async def dep_amazon(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    await q.answer()
    set_state(context, "AMOUNT_AMAZON")
    await q.message.reply_text("Enter diamonds to add (Minimum 10):")

async def dep_upi(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    await q.answer()
    set_state(context, "AMOUNT_UPI")
    await q.message.reply_text("Enter diamonds to add (Minimum 10):")

# ================= HANDLE DEPOSIT AMOUNT =================

async def handle_amount(update: Update, context: ContextTypes.DEFAULT_TYPE):
    state = get_state(context)

    if state not in ["AMOUNT_AMAZON", "AMOUNT_UPI"]:
        return

    try:
        amount = int(update.message.text)
    except:
        await update.message.reply_text("Send valid number.")
        return

    if amount < 10:
        await update.message.reply_text("❌ Minimum is 10.")
        return

    context.user_data["deposit_amount"] = amount

    summary = f"""
📝 Order Summary
━━━━━━━━━━━━━━━
💵 Amount: {amount}
💎 Diamonds: {amount}
━━━━━━━━━━━━━━━
"""

    keyboard = InlineKeyboardMarkup([
        [InlineKeyboardButton("✅ Proceed", callback_data="dep_proceed")]
    ])

    await update.message.reply_text(summary, reply_markup=keyboard)

# ================= PROCEED =================

async def dep_proceed(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    await q.answer()

    state = get_state(context)
    amount = context.user_data["deposit_amount"]

    if state == "AMOUNT_AMAZON":
        set_state(context, "SCREEN_AMAZON")
        await q.message.reply_text("📸 Upload Amazon Gift Card screenshot:")

    elif state == "AMOUNT_UPI":
        qr = await db_fetchval("SELECT value FROM settings WHERE key='upi_qr'")
        if not qr:
            await q.message.reply_text("UPI QR not set.")
            clear_state(context)
            return

        set_state(context, "SCREEN_UPI")

        await q.message.reply_photo(
            photo=qr,
            caption=f"💎 Diamonds: {amount}\n\nPay and upload screenshot."
        )

# ================= HANDLE SCREENSHOT =================

async def handle_screenshot(update: Update, context: ContextTypes.DEFAULT_TYPE):
    state = get_state(context)

    if state not in ["SCREEN_AMAZON", "SCREEN_UPI"]:
        return

    photo = update.message.photo[-1].file_id
    uid = update.effective_user.id
    amount = context.user_data["deposit_amount"]

    method = "amazon" if state == "SCREEN_AMAZON" else "upi"

    dep_id = await db_fetchval(
        """
        INSERT INTO deposits (user_id, method, amount, screenshot, status)
        VALUES ($1,$2,$3,$4,'pending')
        RETURNING id
        """,
        uid, method, amount, photo
    )

    clear_state(context)

    await update.message.reply_text("⏳ Waiting for admin approval.")

    keyboard = InlineKeyboardMarkup([
        [
            InlineKeyboardButton("✅ Accept", callback_data=f"admacc_{dep_id}"),
            InlineKeyboardButton("❌ Decline", callback_data=f"admdec_{dep_id}")
        ]
    ])

    for admin in ADMIN_IDS:
        await update.bot.send_photo(
            admin,
            photo,
            caption=f"New Deposit\nUser: {uid}\nMethod: {method}\nAmount: {amount}",
            reply_markup=keyboard
        )
# ================= ADMIN APPROVE / DECLINE =================

async def admin_accept(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    await q.answer()

    if not is_admin(q.from_user.id):
        return

    dep_id = int(q.data.split("_")[1])

    row = await db_fetch(
        "SELECT user_id, amount FROM deposits WHERE id=$1 AND status='pending'",
        dep_id
    )

    if not row:
        await q.message.edit_caption("Already processed.")
        return

    uid = row[0]["user_id"]
    amount = row[0]["amount"]

    async with db_pool.acquire() as conn:
        async with conn.transaction():
            await conn.execute(
                "UPDATE users SET diamonds = diamonds + $1 WHERE user_id=$2",
                amount, uid
            )
            await conn.execute(
                "UPDATE deposits SET status='accepted' WHERE id=$1",
                dep_id
            )

    await context.bot.send_message(uid, f"✅ Deposit Approved!\n💎 {amount} diamonds added.")
    await q.message.edit_caption("Approved ✅")


async def admin_decline(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    await q.answer()

    if not is_admin(q.from_user.id):
        return

    dep_id = int(q.data.split("_")[1])

    await db_execute(
        "UPDATE deposits SET status='declined' WHERE id=$1",
        dep_id
    )

    row = await db_fetch("SELECT user_id FROM deposits WHERE id=$1", dep_id)
    if row:
        await context.bot.send_message(row[0]["user_id"], "❌ Deposit Declined.")

    await q.message.edit_caption("Declined ❌")

# ================= BUY MENU =================

async def buy_menu(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    await q.answer()

    s500 = await db_fetchval("SELECT COUNT(*) FROM coupons WHERE type='500' AND is_used=false")
    s1k = await db_fetchval("SELECT COUNT(*) FROM coupons WHERE type='1k' AND is_used=false")
    s2k = await db_fetchval("SELECT COUNT(*) FROM coupons WHERE type='2k' AND is_used=false")
    s4k = await db_fetchval("SELECT COUNT(*) FROM coupons WHERE type='4k' AND is_used=false")

    keyboard = InlineKeyboardMarkup([
        [InlineKeyboardButton(f"500 (Stock {s500})", callback_data="buytype_500")],
        [InlineKeyboardButton(f"1K (Stock {s1k})", callback_data="buytype_1k")],
        [InlineKeyboardButton(f"2K (Stock {s2k})", callback_data="buytype_2k")],
        [InlineKeyboardButton(f"4K (Stock {s4k})", callback_data="buytype_4k")]
    ])

    await q.message.edit_text("Select Coupon Type:", reply_markup=keyboard)

async def buy_select(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    await q.answer()
    context.user_data["buy_type"] = q.data.split("_")[1]
    set_state(context, "BUY_QTY")
    await q.message.reply_text("Enter quantity:")

# ================= PROCESS PURCHASE =================

async def process_purchase(update: Update, context: ContextTypes.DEFAULT_TYPE):
    if get_state(context) != "BUY_QTY":
        return

    try:
        qty = int(update.message.text)
    except:
        await update.message.reply_text("Send valid quantity.")
        return

    uid = update.effective_user.id
    ctype = context.user_data["buy_type"]

    async with db_pool.acquire() as conn:
        async with conn.transaction():

            stock = await conn.fetchval(
                "SELECT COUNT(*) FROM coupons WHERE type=$1 AND is_used=false",
                ctype
            )

            if qty > stock:
                await update.message.reply_text(f"❌ Not enough stock! Available: {stock}")
                clear_state(context)
                return

            price = int(await conn.fetchval(
                "SELECT value FROM settings WHERE key=$1",
                f"price_{ctype}"
            ))

            total = price * qty

            balance = await conn.fetchval(
                "SELECT diamonds FROM users WHERE user_id=$1 FOR UPDATE",
                uid
            )

            if balance < total:
                await update.message.reply_text(
                    f"❌ Not enough diamonds! Needed: {total} | You have: {balance}"
                )
                clear_state(context)
                return

            rows = await conn.fetch(
                """
                SELECT id, code FROM coupons
                WHERE type=$1 AND is_used=false
                LIMIT $2
                FOR UPDATE
                """,
                ctype, qty
            )

            for r in rows:
                await conn.execute(
                    "UPDATE coupons SET is_used=true WHERE id=$1",
                    r["id"]
                )

            await conn.execute(
                "UPDATE users SET diamonds = diamonds - $1 WHERE user_id=$2",
                total, uid
            )

            await conn.execute(
                "INSERT INTO orders (user_id,type,quantity,total_cost) VALUES ($1,$2,$3,$4)",
                uid, ctype, qty, total
            )

    codes = "\n".join([r["code"] for r in rows])

    await update.message.reply_text(
        f"✅ Purchase Successful!\n\nYour Codes:\n{codes}"
    )

    buffer = io.BytesIO(codes.encode())
    buffer.name = "coupons.txt"
    await update.message.reply_document(InputFile(buffer))

    clear_state(context)

# ================= MY ORDERS =================

async def my_orders(update: Update, context: ContextTypes.DEFAULT_TYPE):
    q = update.callback_query
    await q.answer()

    rows = await db_fetch(
        """
        SELECT type, quantity, total_cost, created_at
        FROM orders
        WHERE user_id=$1
        ORDER BY id DESC
        LIMIT 10
        """,
        q.from_user.id
    )

    if not rows:
        await q.message.edit_text("No orders found.", reply_markup=main_keyboard())
        return

    text = "📦 Your Orders:\n\n"
    for r in rows:
        text += f"{r['type']} x{r['quantity']} | {r['total_cost']} 💎 | {r['created_at'].strftime('%Y-%m-%d')}\n"

    await q.message.edit_text(text, reply_markup=main_keyboard())

# ================= WEBHOOK =================

@app.post("/webhook")
async def webhook(req: Request):
    data = await req.json()
    update = Update.de_json(data, application.bot)
    await application.process_update(update)
    return {"ok": True}

@app.on_event("startup")
async def startup():
    global db_pool
    db_pool = await init_db()
    await application.initialize()
    await application.bot.set_webhook(WEBHOOK_URL)

# ================= HANDLERS =================

application.add_handler(CommandHandler("start", start))

application.add_handler(CallbackQueryHandler(balance, pattern="balance"))
application.add_handler(CallbackQueryHandler(addcoins_menu, pattern="addcoins"))
application.add_handler(CallbackQueryHandler(dep_amazon, pattern="dep_amazon"))
application.add_handler(CallbackQueryHandler(dep_upi, pattern="dep_upi"))
application.add_handler(CallbackQueryHandler(dep_proceed, pattern="dep_proceed"))

application.add_handler(CallbackQueryHandler(admin_accept, pattern="admacc_"))
application.add_handler(CallbackQueryHandler(admin_decline, pattern="admdec_"))

application.add_handler(CallbackQueryHandler(buy_menu, pattern="buy"))
application.add_handler(CallbackQueryHandler(buy_select, pattern="buytype_"))
application.add_handler(CallbackQueryHandler(my_orders, pattern="orders"))

application.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_amount))
application.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, process_purchase))
application.add_handler(MessageHandler(filters.PHOTO, handle_screenshot))
