<?php
/**
 * TechnicalAnalysis.php
 * ゴールデンクロス/デッドクロス、RSI、ボリンジャーバンド計算
 */
class TechnicalAnalysis
{
    // ---------------------------------------------------------------
    // 単純移動平均 (SMA)
    // ---------------------------------------------------------------
    public function sma(array $data, int $period): array
    {
        $result = [];
        $count  = count($data);

        for ($i = 0; $i < $count; $i++) {
            if ($i < $period - 1) {
                $result[] = null;
                continue;
            }
            $slice    = array_slice($data, $i - $period + 1, $period);
            $result[] = array_sum($slice) / $period;
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // 指数移動平均 (EMA)
    // ---------------------------------------------------------------
    public function ema(array $data, int $period): array
    {
        $result    = [];
        $multiplier = 2 / ($period + 1);
        $prevEma   = null;

        foreach ($data as $i => $price) {
            if ($i < $period - 1) {
                $result[] = null;
                continue;
            }
            if ($prevEma === null) {
                // 最初のEMA = SMAで初期化
                $slice   = array_slice($data, 0, $period);
                $prevEma = array_sum($slice) / $period;
                $result[] = $prevEma;
                continue;
            }
            $ema      = ($price - $prevEma) * $multiplier + $prevEma;
            $prevEma  = $ema;
            $result[] = $ema;
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // ゴールデンクロス / デッドクロス検出
    // ---------------------------------------------------------------
    public function detectCross(array $shortMa, array $longMa): string
    {
        $len = count($shortMa);

        // 最新の2点を取得（null を除く）
        $validShort = array_filter($shortMa, fn($v) => $v !== null);
        $validLong  = array_filter($longMa,  fn($v) => $v !== null);

        if (count($validShort) < 2 || count($validLong) < 2) {
            return 'none';
        }

        $shortVals = array_values($validShort);
        $longVals  = array_values($validLong);

        $n = min(count($shortVals), count($longVals));

        $prevShort = $shortVals[$n - 2];
        $currShort = $shortVals[$n - 1];
        $prevLong  = $longVals[$n - 2];
        $currLong  = $longVals[$n - 1];

        // ゴールデンクロス: 短期が長期を下から上に突き抜け
        if ($prevShort <= $prevLong && $currShort > $currLong) {
            return 'golden';
        }

        // デッドクロス: 短期が長期を上から下に突き抜け
        if ($prevShort >= $prevLong && $currShort < $currLong) {
            return 'dead';
        }

        return 'none';
    }

    // ---------------------------------------------------------------
    // RSI (Relative Strength Index)
    // ---------------------------------------------------------------
    public function rsi(array $closes, int $period = 14): array
    {
        $result  = [];
        $count   = count($closes);
        $gains   = [];
        $losses  = [];

        for ($i = 1; $i < $count; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gains[]  = max(0, $diff);
            $losses[] = max(0, -$diff);
        }

        for ($i = 0; $i < $period - 1; $i++) {
            $result[] = null;
        }
        $result[] = null; // インデックスずれ調整

        if (count($gains) < $period) return $result;

        // 最初のRS = 単純平均
        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        $rs  = $avgLoss == 0 ? 100 : $avgGain / $avgLoss;
        $rsi = 100 - (100 / (1 + $rs));
        $result[] = $rsi;

        // Wilder平滑化
        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = ($avgGain * ($period - 1) + $gains[$i]) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $losses[$i]) / $period;
            $rs  = $avgLoss == 0 ? 100 : $avgGain / $avgLoss;
            $rsi = 100 - (100 / (1 + $rs));
            $result[] = $rsi;
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // ボリンジャーバンド
    // ---------------------------------------------------------------
    public function bollingerBands(array $closes, int $period = 20, float $multiplier = 2.0): array
    {
        $n       = count($closes);
        $slice   = array_slice($closes, $n - $period, $period);
        $middle  = array_sum($slice) / $period;

        // 標準偏差
        $variance = 0;
        foreach ($slice as $v) {
            $variance += ($v - $middle) ** 2;
        }
        $stddev = sqrt($variance / $period);

        return [
            'upper'  => $middle + $multiplier * $stddev,
            'middle' => $middle,
            'lower'  => $middle - $multiplier * $stddev,
            'stddev' => $stddev,
            'bandwidth' => (($middle + $multiplier * $stddev) - ($middle - $multiplier * $stddev)) / $middle * 100,
        ];
    }

    // ---------------------------------------------------------------
    // ボリンジャーバンド シグナル判定
    // ---------------------------------------------------------------
    public function bbSignal(float $price, array $bb): string
    {
        $upper  = $bb['upper'];
        $lower  = $bb['lower'];
        $middle = $bb['middle'];

        if ($price >= $upper)                         return 'overbought';
        if ($price <= $lower)                         return 'oversold';
        if ($price > $middle && $price < $upper)      return 'upper_mid';
        if ($price < $middle && $price > $lower)      return 'lower_mid';
        return 'middle';
    }

    // ---------------------------------------------------------------
    // MACD (おまけ)
    // ---------------------------------------------------------------
    public function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $emaFast   = $this->ema($closes, $fast);
        $emaSlow   = $this->ema($closes, $slow);
        $macdLine  = [];

        for ($i = 0; $i < count($closes); $i++) {
            if ($emaFast[$i] === null || $emaSlow[$i] === null) {
                $macdLine[] = null;
            } else {
                $macdLine[] = $emaFast[$i] - $emaSlow[$i];
            }
        }

        $validMacd   = array_values(array_filter($macdLine, fn($v) => $v !== null));
        $signalLine  = $this->ema($validMacd, $signal);

        return [
            'macd'   => $macdLine,
            'signal' => $signalLine,
        ];
    }
}
