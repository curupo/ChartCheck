<?php
/**
 * TelegramNotifier.php
 * Telegram Bot API 経由でメッセージ送信
 */
class TelegramNotifier
{
    private string $token;
    private string $chatId;
    private string $apiBase = 'https://api.telegram.org/bot';

    public function __construct(string $token, string $chatId)
    {
        $this->token  = $token;
        $this->chatId = $chatId;
    }

    // ---------------------------------------------------------------
    // テキスト送信 (Markdown V2)
    // ---------------------------------------------------------------
    public function send(string $message): bool
    {
        $url = "{$this->apiBase}{$this->token}/sendMessage";

        $payload = [
            'chat_id'    => $this->chatId,
            'text'       => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ];

        return $this->post($url, $payload);
    }

    // ---------------------------------------------------------------
    // 画像付き送信
    // ---------------------------------------------------------------
    public function sendPhoto(string $imagePath, string $caption = ''): bool
    {
        $url = "{$this->apiBase}{$this->token}/sendPhoto";

        $payload = [
            'chat_id' => $this->chatId,
            'photo'   => new CURLFile($imagePath),
            'caption' => $caption,
        ];

        return $this->postMultipart($url, $payload);
    }

    // ---------------------------------------------------------------
    // POST (JSON)
    // ---------------------------------------------------------------
    private function post(string $url, array $data): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new RuntimeException("Telegram API error: HTTP {$httpCode}, Response: {$response}");
        }

        $json = json_decode($response, true);
        return $json['ok'] ?? false;
    }

    // ---------------------------------------------------------------
    // POST (Multipart - 画像用)
    // ---------------------------------------------------------------
    private function postMultipart(string $url, array $data): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new RuntimeException("Telegram Photo API error: HTTP {$httpCode}");
        }

        $json = json_decode($response, true);
        return $json['ok'] ?? false;
    }

    // ---------------------------------------------------------------
    // Chat ID 確認用ヘルパー
    // ---------------------------------------------------------------
    public function getUpdates(): array
    {
        $url      = "{$this->apiBase}{$this->token}/getUpdates";
        $response = file_get_contents($url);
        return json_decode($response, true) ?? [];
    }
}
