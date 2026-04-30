# 🤖 ARB/USD Trading Bot (Binance API)

Binance REST API の `ARBUSDT` ペアを使って ARB/USD のシグナルを判定し、Telegram に通知する PHP ボット。

---

## 📁 ファイル構成

```
alice_trading_bot/
├── trading_bot.php       # メインスクリプト（cronで実行）
├── config.php            # 設定ファイル ⚠️ 要編集
├── TechnicalAnalysis.php # テクニカル指標（SMA / RSI / BB / クロス）
├── ChartDataFetcher.php  # Binance API から ARB/USD OHLCV 取得
├── TelegramNotifier.php  # Telegram Bot 通知
├── Logger.php            # ログ出力
├── setup.sh              # セットアップスクリプト
├── logs/                 # ログ（自動生成）
└── data/cache/           # キャッシュ（自動生成）
```

---

## 📊 データソース: Binance REST API

| 項目 | 内容 |
|------|------|
| エンドポイント | `https://api.binance.com/api/v3/klines` |
| シンボル | `ARBUSDT`（ARB は Binance に直接上場） |
| 認証 | **不要**（公開エンドポイント） |
| レート制限 | 1200 リクエスト/分 |
| 換算 | 不要（USDT ≒ USD として直接利用） |

---

## ⏰ 使用する時間足と重み

| 時間足  | Binance interval | 重み（総合判定） |
|---------|-----------------|----------------|
| 1時間足 | `1h`            | 1（短期参考）   |
| 4時間足 | `4h`            | 2（中期）       |
| 8時間足 | `8h`            | 3（主要）       |

---

## 🚀 セットアップ

### 1. Telegram Bot 作成

1. `@BotFather` → `/newbot` → **Bot Token** をコピー
2. `@userinfobot` に話しかけて **Chat ID** を確認

### 2. config.php 編集（最低限これだけ）

```php
define('TELEGRAM_BOT_TOKEN', 'ここにBotToken');
define('TELEGRAM_CHAT_ID',   'ここにChatID');

// Binance APIキーは OHLCV取得に不要（注文機能を使う場合のみ設定）
define('BINANCE_API_KEY',    '');
define('BINANCE_API_SECRET', '');
```

### 3. テスト実行

```bash
php trading_bot.php
```

---

## ⏰ cron 設定

```bash
crontab -e
```

```cron
# 毎時0分に実行（1h足チェック）
0 * * * * /usr/bin/php /path/to/trading_bot.php >> /path/to/logs/cron.log 2>&1

# 4時間ごとに実行（4h・8h足メイン）
0 */4 * * * /usr/bin/php /path/to/trading_bot.php >> /path/to/logs/cron.log 2>&1
```

---

## 📊 シグナル判定（スコアリング）

| 指標 | 条件 | BUY | SELL |
|------|------|-----|------|
| クロス | ゴールデンクロス | +2 | – |
| クロス | デッドクロス | – | +2 |
| RSI | ≤ 30（売られ過ぎ） | +2 | – |
| RSI | 30〜40 | +1 | – |
| RSI | ≥ 70（買われ過ぎ） | – | +2 |
| RSI | 60〜70 | – | +1 |
| BB | 下限突破 | +2 | – |
| BB | 下半分 | +1 | – |
| BB | 上限突破 | – | +2 |
| BB | 上半分 | – | +1 |

**スコア ≥ 3 かつ相手より高い → BUY or SELL**

---

## 📱 Telegram 通知例

```
🤖 ARB/USD Trading Signal
📡 データソース: Binance API (ARBUSDT)
📅 2024-04-25 09:00:00 JST
━━━━━━━━━━━━━━━━
🏆 総合判定: 🟢 買いシグナル
━━━━━━━━━━━━━━━━

📊 1時間足 (1h)
  💰 現在値: $0.4820
  📈 MA5: 0.4750 / MA25: 0.4600
  🔀 クロス: ✨ ゴールデンクロス
  💹 RSI(14): 34.5 ⚠️ 売られ過ぎ
  📉 BB上限: 0.5200 / 中央: 0.4700 / 下限: 0.4200
  🎯 BB位置: ↘️ 下半分
  🏷 判定: 🟢 買い
...
```

---

## 🔧 依存関係

- PHP 8.0+
- ext-curl, ext-json, ext-mbstring
- Binance API へのネットワーク接続
