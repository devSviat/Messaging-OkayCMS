<?php

namespace Okay\Modules\Sviat\Messaging\Providers;

use Okay\Core\Settings;

/** TurboSMS API (Bearer token): send, balance, status. */
class TurboSmsProvider implements SmsProviderInterface
{
    private const API_BASE = 'https://api.turbosms.ua';
    private const API_SEND_URL = self::API_BASE . '/message/send.json';
    private const API_STATUS_URL = self::API_BASE . '/message/status.json';
    private const API_BALANCE_URL = self::API_BASE . '/user/balance.json';

    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function getName(): string
    {
        return 'turbosms';
    }

    public function send(string $phone, string $message): array
    {
        $token = trim((string)$this->settings->get('sviat__messaging__turbosms_token'));
        $sender = trim((string)$this->settings->get('sviat__messaging__turbosms_sender'));

        if (empty($token)) {
            return [
                'success' => false,
                'provider_message_id' => null,
                'error' => 'TurboSMS: не налаштовано (токен)',
            ];
        }

        if (empty($sender)) {
            $sender = 'Ok';
        }

        $response = $this->sendRequest($phone, $message, $sender, $token);

        if ($response['success']) {
            return [
                'success' => true,
                'provider_message_id' => $response['message_id'] ?? null,
                'error' => null,
            ];
        }

        return [
            'success' => false,
            'provider_message_id' => null,
            'error' => $response['error'] ?? 'Unknown TurboSMS error',
        ];
    }

    private function sendRequest(string $phone, string $message, string $sender, string $token): array
    {
        $body = [
            'recipients' => [$phone],
            'sms' => [
                'sender' => $sender,
                'text' => $message,
            ],
        ];

        $ch = curl_init(self::API_SEND_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'CURL: ' . $curlError];
        }

        $data = $responseBody ? json_decode($responseBody, true) : null;
        if ($httpCode !== 200 || !is_array($data)) {
            return ['success' => false, 'error' => $responseBody ?: 'HTTP ' . $httpCode];
        }

        $responseCode = (int)($data['response_code'] ?? -1);
        $result = $data['response_result'] ?? null;
        $firstMessageId = null;
        if (is_array($result) && isset($result[0]['message_id'])) {
            $firstMessageId = $result[0]['message_id'];
        }

        if (in_array($responseCode, [0, 800, 801, 802, 803], true) && $firstMessageId) {
            return ['success' => true, 'message_id' => $firstMessageId];
        }
        if (in_array($responseCode, [0, 800, 801], true)) {
            return ['success' => true, 'message_id' => $firstMessageId];
        }

        return [
            'success' => false,
            'error' => $data['response_status'] ?? ($responseBody ?: 'HTTP ' . $httpCode),
        ];
    }

    public function getBalance(): array
    {
        $token = trim((string)$this->settings->get('sviat__messaging__turbosms_token'));
        if (empty($token)) {
            return ['success' => false, 'balance' => null, 'currency' => null, 'error' => 'Токен не налаштовано'];
        }

        $ch = curl_init(self::API_BALANCE_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 10,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return ['success' => false, 'balance' => null, 'currency' => null, 'error' => 'CURL: ' . $curlError];
        }

        $data = $responseBody ? json_decode($responseBody, true) : null;
        if ($httpCode === 200 && is_array($data) && (int)($data['response_code'] ?? -1) === 0) {
            $result = $data['response_result'] ?? null;
            if (is_array($result) && isset($result['balance'])) {
                return [
                    'success' => true,
                    'balance' => (float)$result['balance'],
                    'currency' => 'UAH',
                    'error' => null,
                ];
            }
        }
        return ['success' => false, 'balance' => null, 'currency' => null, 'error' => $data['response_status'] ?? $responseBody ?: 'HTTP ' . $httpCode];
    }

    public function getStatus(array $messageIds): array
    {
        $token = trim((string)$this->settings->get('sviat__messaging__turbosms_token'));
        if (empty($token)) {
            return ['success' => false, 'info' => [], 'error' => 'Токен не налаштовано'];
        }
        if (empty($messageIds)) {
            return ['success' => true, 'info' => [], 'error' => null];
        }

        $body = json_encode(['messages' => array_values($messageIds)]);

        $ch = curl_init(self::API_STATUS_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 10,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return ['success' => false, 'info' => [], 'error' => 'CURL: ' . $curlError];
        }

        $data = $responseBody ? json_decode($responseBody, true) : null;
        if ($httpCode === 200 && is_array($data) && (int)($data['response_code'] ?? -1) === 0) {
            $result = $data['response_result'] ?? [];
            $info = [];
            if (is_array($result)) {
                foreach ($result as $item) {
                    if (is_array($item) && isset($item['message_id'])) {
                        $info[(string)$item['message_id']] = $item['status'] ?? $item['response_status'] ?? '—';
                    }
                }
            }
            return ['success' => true, 'info' => $info, 'error' => null];
        }
        return ['success' => false, 'info' => [], 'error' => $data['response_status'] ?? $responseBody ?: 'HTTP ' . $httpCode];
    }
}
