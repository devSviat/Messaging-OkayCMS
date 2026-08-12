<?php

namespace Okay\Modules\Sviat\Messaging\Providers;

use Okay\Core\Settings;

/** SMS Club API (Bearer token): send, balance, status, originators. */
class SmsClubProvider implements SmsProviderInterface
{
    private const API_BASE = 'https://im.smsclub.mobi/sms';
    private const API_SEND = self::API_BASE . '/send';
    private const API_BALANCE = self::API_BASE . '/balance';
    private const API_STATUS = self::API_BASE . '/status';
    private const API_ORIGINATOR = self::API_BASE . '/originator';

    private $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function getName(): string
    {
        return 'smsclub';
    }

    public function send(string $phone, string $message): array
    {
        $token = trim((string)$this->settings->get('sviat__messaging__smsclub_token'));
        $srcAddr = trim((string)$this->settings->get('sviat__messaging__smsclub_src_addr'));

        if (empty($token)) {
            return [
                'success' => false,
                'provider_message_id' => null,
                'error' => 'SMS Club: не налаштовано (токен)',
            ];
        }

        if (empty($srcAddr)) {
            $srcAddr = 'Info';
        }

        $response = $this->sendRequest($phone, $message, $srcAddr, $token);

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
            'error' => $response['error'] ?? 'Unknown SMS Club error',
        ];
    }

    private function sendRequest(string $phone, string $message, string $srcAddr, string $token): array
    {
        $data = json_encode([
            'phone' => [$phone],
            'message' => $message,
            'src_addr' => $srcAddr,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::API_SEND,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'CURL: ' . $curlError];
        }

        $dataDecoded = $responseBody ? json_decode($responseBody, true) : null;

        // Успіх: 200, success_request.info — "id_sms": "phone"
        if ($httpCode === 200 && is_array($dataDecoded) && !empty($dataDecoded['success_request']['info'])) {
            $info = $dataDecoded['success_request']['info'];
            $messageId = is_array($info) ? (string)key($info) : (string)$info;
            return ['success' => true, 'message_id' => $messageId];
        }

        $errorMsg = $responseBody;
        if (is_array($dataDecoded) && isset($dataDecoded['error_request']['message'])) {
            $errorMsg = $dataDecoded['error_request']['message'];
        } elseif (is_array($dataDecoded) && !empty($dataDecoded['errors'])) {
            $errorMsg = is_array($dataDecoded['errors']) ? implode('; ', $dataDecoded['errors']) : (string)$dataDecoded['errors'];
        }
        return ['success' => false, 'error' => $errorMsg ?: 'HTTP ' . $httpCode];
    }

    public function getBalance(): array
    {
        $token = trim((string)$this->settings->get('sviat__messaging__smsclub_token'));
        if (empty($token)) {
            return ['success' => false, 'balance' => null, 'currency' => null, 'error' => 'Токен не налаштовано'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::API_BALANCE,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
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
        if ($httpCode === 200 && is_array($data) && isset($data['success_request']['info'])) {
            $info = $data['success_request']['info'];
            if (isset($info['money'])) {
                return [
                    'success' => true,
                    'balance' => $info['money'],
                    'currency' => $info['currency'] ?? null,
                    'error' => null,
                ];
            }
            if (is_array($info) && isset($info[0]) && is_array($info[0])) {
                $first = $info[0];
                return [
                    'success' => true,
                    'balance' => $first['money'] ?? null,
                    'currency' => $first['currency'] ?? null,
                    'error' => null,
                ];
            }
        }
        return ['success' => false, 'balance' => null, 'currency' => null, 'error' => $responseBody ?: 'HTTP ' . $httpCode];
    }

    public function getStatus(array $idSms): array
    {
        $token = trim((string)$this->settings->get('sviat__messaging__smsclub_token'));
        if (empty($token)) {
            return ['success' => false, 'info' => [], 'error' => 'Токен не налаштовано'];
        }
        if (empty($idSms)) {
            return ['success' => true, 'info' => [], 'error' => null];
        }

        $data = json_encode(['id_sms' => array_values($idSms)]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::API_STATUS,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return ['success' => false, 'info' => [], 'error' => 'CURL: ' . $curlError];
        }

        $decoded = $responseBody ? json_decode($responseBody, true) : null;
        if ($httpCode === 200 && is_array($decoded) && !empty($decoded['success_request']['info'])) {
            return ['success' => true, 'info' => $decoded['success_request']['info'], 'error' => null];
        }
        return ['success' => false, 'info' => [], 'error' => $responseBody ?: 'HTTP ' . $httpCode];
    }

    public function getOriginators(): array
    {
        $token = trim((string)$this->settings->get('sviat__messaging__smsclub_token'));
        if (empty($token)) {
            return ['success' => false, 'originators' => [], 'error' => 'Токен не налаштовано'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::API_ORIGINATOR,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return ['success' => false, 'originators' => [], 'error' => 'CURL: ' . $curlError];
        }

        $data = $responseBody ? json_decode($responseBody, true) : null;
        if ($httpCode === 200 && is_array($data) && !empty($data['success_request']['info'])) {
            $info = $data['success_request']['info'];
            $list = is_array($info) ? $info : [];
            return ['success' => true, 'originators' => array_values($list), 'error' => null];
        }
        return ['success' => false, 'originators' => [], 'error' => $responseBody ?: 'HTTP ' . $httpCode];
    }
}
