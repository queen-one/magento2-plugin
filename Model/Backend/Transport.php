<?php
declare(strict_types=1);

namespace Rejoiner\Acr\Model\Backend;

class Transport
{
    public static function signature(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public function send(string $endpoint, string $body, string $secret): int
    {
        $timestamp = time();
        $handle = curl_init($endpoint);
        if ($handle === false) {
            throw new \RuntimeException('Transport unavailable');
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json',
                    'X-Queenone-Timestamp: ' . $timestamp,
                    'X-Queenone-Signature: ' . self::signature($body, $secret, $timestamp)],
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                // Never retain or log response bodies (they may contain customer data).
                CURLOPT_WRITEFUNCTION => static fn ($curl, string $data): int => strlen($data),
            ]);
            if (curl_exec($handle) === false) {
                throw new \RuntimeException('Transport failed');
            }
            return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            $handle = null;
        }
    }
}
