#!/bin/bash
# setup.sh - セットアップスクリプト

set -e

echo "=== セットアップ ==="

# ディレクトリ作成
mkdir -p logs data/cache

# パーミッション設定
chmod 755 chart_check.php
chmod 600 config.php

echo "✅ ディレクトリ作成完了"
echo ""
echo "=== 次のステップ ==="
echo ""
echo "1. config.php を編集してください:"
echo "   - TELEGRAM_BOT_TOKEN: @BotFather から取得"
echo "   - TELEGRAM_CHAT_ID  : @userinfobot で確認"
echo ""
echo "2. Telegram Chat ID の確認方法:"
echo "   php -r \""
echo "     require 'config.php';"
echo "     require 'TelegramNotifier.php';"
echo "     \\\$t = new TelegramNotifier(TELEGRAM_BOT_TOKEN, '');"
echo "     print_r(\\\$t->getUpdates());"
echo "   \""
echo ""
echo "3. 手動テスト実行:"
echo "   php chart_check.php"
echo ""
echo "4. crontab 設定 (crontab -e):"
echo ""
echo "   # 1時間ごとに実行"
echo "   0 * * * * /usr/bin/php $(pwd)/chart_check.php >> $(pwd)/logs/cron.log 2>&1"
echo ""
echo "   # 4時間ごとに実行"
echo "   0 */4 * * * /usr/bin/php $(pwd)/chart_check.php >> $(pwd)/logs/cron.log 2>&1"
echo ""
echo "   # 8時間ごとに実行"
echo "   0 */8 * * * /usr/bin/php $(pwd)/chart_check.php >> $(pwd)/logs/cron.log 2>&1"
echo ""
echo "5. ログ確認:"
echo "   tail -f logs/chart_check.log"
echo ""
echo "=== PHP 要件確認 ==="
php -r "
  \$ok = true;
  \$exts = ['curl', 'json', 'mbstring'];
  foreach (\$exts as \$ext) {
    if (extension_loaded(\$ext)) {
      echo \"✅ \$ext\n\";
    } else {
      echo \"❌ \$ext (要インストール)\n\";
      \$ok = false;
    }
  }
  echo \$ok ? '✅ 全てOK' : '❌ 不足あり';
"
