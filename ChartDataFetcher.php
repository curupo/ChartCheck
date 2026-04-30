<?php
/**
 * ChartDataFetcher.php
 *
 * Binance REST API から ARB/USD (ARBUSDT) の OHLCV を取得する。
 *
 * ■ エンドポイント（認証不要）
 *   GET https://api.binance.com/api/v3/klines
 *     ?symbol=ARBUSDT
 *     &interval=1h   ← 1h / 4h / 8h
 *     &limit=100
 *
 * ■ Binance Kline レスポンス形式（配列インデックス）
 *   [0]  Open time (ms)
 *   [1]  Open price  (USDT)
 *   [2]  High price  (USDT)
 *   [3]  Low price   (USDT)
 *   [4]  Close price (USDT)
 *   [5]  Volume      (ARB)
 *   [6]  Close time  (ms)
 *   [7..11] 出来高系・無視フィールド
 *
 * ARB/USDT は Binance に直接上場しているため換算処理は不要。
 * USDT ≒ USD として USD建て価格として扱う。
 */
class ChartDataFetcher
{
    private string $apiBase;
    private string $cacheDir;

    // 時間足ごとのキャッシュ TTL（秒）
    private array $ttlMap = [
        '1h' => 180,   // 3分
        '4h' => 600,   // 10分
        '8h' => 1200,  // 20分
    ];

    public function __construct()
    {
        $this->apiBase  = BINANCE_API_BASE;
        $this->cacheDir = CACHE_DIR;
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    // ---------------------------------------------------------------
    // メイン: ARB/USD OHLCV 取得
    // $tfKey: '1h' | '4h' | '8h'
    // ---------------------------------------------------------------
    public function fetchOHLCV(string $tfKey): array
    {
        $timeframes = TIMEFRAMES;
        if (!isset($timeframes[$tfKey])) {
            throw new InvalidArgumentException("Unknown timeframe: {$tfKey}");
        }

        $interval = $timeframes[$tfKey]['interval'];
        $candles  = $timeframes[$tfKey]['candles'];
        $cacheKey = "binance_arb_usd_{$tfKey}";
        $ttl      = $this->ttlMap[$tfKey] ?? 300;

        $cached = $this->getCache($cacheKey);
        if ($cached !== null) return $cached;

        $ohlcv = $this->fetchKlines(SYMBOL_ARB, $interval, $candles);

        if (empty($ohlcv)) {
            throw new RuntimeException(
                "Binance klines 取得失敗: " . SYMBOL_ARB . " ({$interval})"
            );
        }

        $this->setCache($cacheKey, $ohlcv, $ttl);
        return $ohlcv;
    }

    // ---------------------------------------------------------------
    // Binance GET /api/v3/klines → 統一フォーマットに変換
    // ---------------------------------------------------------------
    private function fetchKlines(string $symbol, string $interval, int $limit): array
    {
        $url = sprintf(
            '%s/api/v3/klines?symbol=%s&interval=%s&limit=%d',
            $this->apiBase,
            $symbol,
            $interval,
            $limit
        );

        $response = $this->httpGet($url);
        if ($response === null) return [];

        $raw = json_decode($response, true);
        if (!is_array($raw) || empty($raw)) return [];

        // Binance 形式 → 統一フォーマット変換
        return array_map(fn($c) => [
            'timestamp' => (int)($c[0] / 1000),  // ms → sec
            'open'      => (float)$c[1],
            'high'      => (float)$c[2],
            'low'       => (float)$c[3],
            'close'     => (float)$c[4],
            'volume'    => (float)$c[5],          // ARB 建て出来高
        ], $raw);
    }

    // ---------------------------------------------------------------
    // HTTP GET（Binance 公開エンドポイント用）
    // ---------------------------------------------------------------
    private function httpGet(string $url, int $timeout = 10): ?string
    {
        $headers = ['Accept: application/json'];
        if (defined('BINANCE_API_KEY') && BINANCE_API_KEY !== '') {
            $headers[] = 'X-MBX-APIKEY: ' . BINANCE_API_KEY;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Chart-Check/0.1',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("[ChartDataFetcher] cURL error: {$curlErr}");
            return null;
        }
        if ($httpCode !== 200) {
            error_log("[ChartDataFetcher] HTTP {$httpCode}: {$url} → {$response}");
            return null;
        }

        return $response;
    }

    // ---------------------------------------------------------------
    // ファイルキャッシュ
    // ---------------------------------------------------------------
    private function getCache(string $key): mixed
    {
        $file = $this->cacheDir . md5($key) . '.json';
        if (!file_exists($file)) return null;

        $data = json_decode(file_get_contents($file), true);
        if (!$data || $data['expires'] < time()) {
            @unlink($file);
            return null;
        }
        return $data['value'];
    }

    private function setCache(string $key, mixed $value, int $ttl): void
    {
        $file = $this->cacheDir . md5($key) . '.json';
        file_put_contents($file, json_encode([
            'expires' => time() + $ttl,
            'value'   => $value,
        ]), LOCK_EX);
    }
}
