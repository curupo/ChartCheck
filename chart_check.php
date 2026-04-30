#!/usr/bin/env php
<?php
/**
 * trading_bot.php - ARB/USD Trading Bot メインスクリプト
 *
 * データソース : Binance REST API (/api/v3/klines) ARBUSDT
 * 時間足       : 1時間足 / 4時間足 / 8時間足
 * テクニカル指標: ゴールデン/デッドクロス (MA5×MA25), RSI(14), ボリンジャーバンド(20,2σ)
 * 通知         : Telegram Bot
 *
 * cron 設定例:
 *   毎時0分  → 0 * * * * /usr/bin/php /path/to/trading_bot.php >> /path/to/logs/cron.log 2>&1
 *   4時間毎  → 0 * / 4 * * * /usr/bin/php /path/to/trading_bot.php >> /path/to/logs/cron.log 2>&1
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/TechnicalAnalysis.php';
require_once __DIR__ . '/TelegramNotifier.php';
require_once __DIR__ . '/ChartDataFetcher.php';
require_once __DIR__ . '/Logger.php';

$logger = new Logger(LOG_FILE);

try {
    $logger->info('=== ' . DISPLAY_PAIR . ' Trading Bot Started (Binance API) ===');

    $fetcher  = new ChartDataFetcher();
    $telegram = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
    $ta       = new TechnicalAnalysis();
    $signals  = [];

    // ---------------------------------------------------------------
    // 各時間足のデータ取得 & テクニカル分析
    // ---------------------------------------------------------------
    foreach (TIMEFRAMES as $tfKey => $cfg) {
        $logger->info("▶ {$cfg['label']} ({$cfg['interval']}) データ取得中...");

        try {
            $ohlcv = $fetcher->fetchOHLCV($tfKey);
        } catch (Exception $e) {
            $logger->warning("{$cfg['label']} スキップ: " . $e->getMessage());
            continue;
        }

        if (count($ohlcv) < MA_LONG + 5) {
            $logger->warning("{$cfg['label']}: データ不足 (" . count($ohlcv) . "本)");
            continue;
        }

        $closes    = array_column($ohlcv, 'close');
        $lastClose = end($closes);

        // --- ゴールデン/デッドクロス (MA_SHORT × MA_LONG) ---
        $ma5   = $ta->sma($closes, MA_SHORT);
        $ma25  = $ta->sma($closes, MA_LONG);
        $cross = $ta->detectCross($ma5, $ma25);

        // --- RSI (RSI_PERIOD) ---
        $rsiArr = $ta->rsi($closes, RSI_PERIOD);
        $rsiVal = round(end($rsiArr), 2);

        // --- ボリンジャーバンド (BB_PERIOD, BB_STDDEV σ) ---
        $bb       = $ta->bollingerBands($closes, BB_PERIOD, BB_STDDEV);
        $bbSignal = $ta->bbSignal($lastClose, $bb);

        // --- シグナル判定 ---
        $signal = determineSignal($cross, $rsiVal, $bbSignal);

        // MA の有効値（null を除いた末尾）を取得
        $validMa5  = array_filter($ma5,  fn($v) => $v !== null);
        $validMa25 = array_filter($ma25, fn($v) => $v !== null);

        $signals[$tfKey] = [
            'label'     => $cfg['label'],
            'interval'  => $cfg['interval'],
            'close'     => $lastClose,
            'ma_short'  => round(end($validMa5),  4),
            'ma_long'   => round(end($validMa25), 4),
            'cross'     => $cross,
            'rsi'       => $rsiVal,
            'bb_upper'  => round($bb['upper'],  4),
            'bb_middle' => round($bb['middle'], 4),
            'bb_lower'  => round($bb['lower'],  4),
            'bb_signal' => $bbSignal,
            'signal'    => $signal,
        ];

        $logger->info(
            "  close=\${$lastClose} | MA" . MA_SHORT . "={$signals[$tfKey]['ma_short']}" .
            " MA" . MA_LONG . "={$signals[$tfKey]['ma_long']}" .
            " | cross={$cross} | RSI={$rsiVal} | BB={$bbSignal} | → {$signal}"
        );
    }

    if (empty($signals)) {
        $logger->warning('全時間足のデータ取得に失敗しました。終了します。');
        exit(1);
    }

    // ---------------------------------------------------------------
    // 総合判定（時間足ごとの重み付き多数決）
    // ---------------------------------------------------------------
    $overall = calcOverallSignal($signals);
    $logger->info("総合判定: {$overall}");

    // ---------------------------------------------------------------
    // 通知判定（クールダウン + NOTIFY_ONLY_ON_SIGNAL）
    // ---------------------------------------------------------------
//    if (shouldNotify($overall)) {
        $message = buildTelegramMessage($signals, $overall);
        $telegram->send($message);
        saveLastSignal($overall);
        $logger->info('Telegram 通知送信完了');
//    } else {
//        $logger->info('通知スキップ（クールダウン中 or HOLD/WATCH）');
//    }

    $logger->info('=== ' . DISPLAY_PAIR . ' Trading Bot Finished ===');

} catch (Exception $e) {
    $logger->error('Fatal: ' . $e->getMessage());
    try {
        $t = new TelegramNotifier(TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID);
        $t->send("⚠️ *Trading Bot エラー*\n```\n" . $e->getMessage() . "\n```");
    } catch (Exception $ex) { /* silent */ }
    exit(1);
}

// ================================================================
// ヘルパー関数
// ================================================================

/**
 * シグナルスコアリング
 * BUY/SELL スコアが 3 以上かつ相手より高い → BUY or SELL
 * 同点かつ ≥2  → WATCH
 * それ以外      → HOLD
 */
function determineSignal(string $cross, float $rsi, string $bbSignal): string
{
    $buy = $sell = 0;

    // クロス (+2)
    if ($cross === 'golden') $buy  += 2;
    if ($cross === 'dead')   $sell += 2;

    // RSI
    if ($rsi <= 30)      $buy  += 2;
    elseif ($rsi <= 40)  $buy  += 1;
    if ($rsi >= 70)      $sell += 2;
    elseif ($rsi >= 60)  $sell += 1;

    // ボリンジャーバンド
    match ($bbSignal) {
        'oversold'   => $buy  += 2,
        'lower_mid'  => $buy  += 1,
        'overbought' => $sell += 2,
        'upper_mid'  => $sell += 1,
        default      => null,
    };

    if ($buy >= 3 && $buy > $sell)   return 'BUY';
    if ($sell >= 3 && $sell > $buy)  return 'SELL';
    if ($buy === $sell && $buy >= 2) return 'WATCH';
    return 'HOLD';
}

/**
 * 総合判定（重み付き投票）
 * 8h:3点 / 4h:2点 / 1h:1点
 */
function calcOverallSignal(array $signals): string
{
    $weights = ['8h' => 3, '4h' => 2, '1h' => 1];
    $votes   = ['BUY' => 0, 'SELL' => 0, 'WATCH' => 0, 'HOLD' => 0];

    foreach ($signals as $tfKey => $d) {
        $w = $weights[$tfKey] ?? 1;
        $votes[$d['signal']] += $w;
    }

    arsort($votes);
    return array_key_first($votes);
}

/**
 * 通知するかどうか判定
 */
function shouldNotify(string $overall): bool
{
    if (NOTIFY_ONLY_ON_SIGNAL && in_array($overall, ['HOLD', 'WATCH'])) {
        return false;
    }

    if (!file_exists(LAST_SIGNAL_FILE)) return true;

    $last = json_decode(file_get_contents(LAST_SIGNAL_FILE), true);
    if (!$last) return true;

    $elapsed = (time() - ($last['timestamp'] ?? 0)) / 60;
    if ($elapsed < SIGNAL_COOLDOWN_MINUTES && $last['signal'] === $overall) {
        return false;
    }

    return true;
}

/**
 * Telegram メッセージ組み立て
 */
function buildTelegramMessage(array $signals, string $overall): string
{
    $overallEmoji = [
        'BUY'   => '🟢 *買いシグナル*',
        'SELL'  => '🔴 *売りシグナル*',
        'WATCH' => '🟡 *要注目*',
        'HOLD'  => '⚪ *様子見*',
    ];
    $crossLabel = [
        'golden' => '✨ ゴールデンクロス',
        'dead'   => '💀 デッドクロス',
        'none'   => '➖ なし',
    ];
    $bbLabel = [
        'overbought' => '📈 上限突破（過買い）',
        'upper_mid'  => '↗️ 上半分',
        'middle'     => '➡️ 中央付近',
        'lower_mid'  => '↘️ 下半分',
        'oversold'   => '📉 下限突破（過売り）',
    ];
    $sigEmoji = [
        'BUY'   => '🟢 買い',
        'SELL'  => '🔴 売り',
        'WATCH' => '🟡 注目',
        'HOLD'  => '⚪ 様子見',
    ];

    date_default_timezone_set('Asia/Tokyo'); //日本のタイムゾーンに設定
    $now  = date('Y-m-d H:i:s') . ' JST';
    $pair = DISPLAY_PAIR;
    $msg  = "🤖 *{$pair} Trading Signal*\n";
    $msg .= "📡 _データソース: Binance API_\n";
    $msg .= "📅 {$now}\n";
    $msg .= "━━━━━━━━━━━━━━━━\n";
    $msg .= "🏆 総合判定: " . ($overallEmoji[$overall] ?? $overall) . "\n";
    $msg .= "━━━━━━━━━━━━━━━━\n";

    $close_flg = false;
    foreach ($signals as $tfKey => $d) {

	if ($close_flg === false) {
            $msg .= "💰 現在値: `\${$d['close']}`\n\n";
	    $close_flg = true;
	}

        $rsiWarn = '';
        if ($d['rsi'] <= 30)     $rsiWarn = ' ⚠️ 売られ過ぎ';
        elseif ($d['rsi'] >= 70) $rsiWarn = ' ⚠️ 買われ過ぎ';

        $msg .= "📊 *{$d['label']}* (`{$d['interval']}`)\n";
        //$msg .= "  💰 現在値: `\${$d['close']}`\n";
        $msg .= "  📈 MA" . MA_SHORT . ": `{$d['ma_short']}` / MA" . MA_LONG . ": `{$d['ma_long']}`\n";
        $msg .= "  🔀 クロス: " . ($crossLabel[$d['cross']] ?? $d['cross']) . "\n";
        $msg .= "  💹 RSI(" . RSI_PERIOD . "): `{$d['rsi']}`{$rsiWarn}\n";
        //$msg .= "  📉 BB上限: `{$d['bb_upper']}` / 中央: `{$d['bb_middle']}` / 下限: `{$d['bb_lower']}`\n";
        $msg .= "  🎯 BB位置: " . ($bbLabel[$d['bb_signal']] ?? $d['bb_signal']) . "\n";
        $msg .= "  🏷 判定: " . ($sigEmoji[$d['signal']] ?? $d['signal']) . "\n\n";
    }

    //$msg .= "━━━━━━━━━━━━━━━━\n";
    //$msg .= "⚠️ _本シグナルは参考情報です。投資は自己責任でお願いします。_";

    return $msg;
}

/**
 * 最後のシグナルを保存（クールダウン用）
 */
function saveLastSignal(string $signal): void
{
    $dir = dirname(LAST_SIGNAL_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(LAST_SIGNAL_FILE, json_encode([
        'signal'    => $signal,
        'timestamp' => time(),
    ]), LOCK_EX);
}
