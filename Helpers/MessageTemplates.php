<?php

namespace Okay\Modules\Sviat\Messaging\Helpers;

use Okay\Modules\Sviat\Messaging\Services\NotificationEventService;

/** Тексти SMS за типами подій. */
class MessageTemplates
{
    private const DEFAULT_PARAMS = [
        'doc_number' => '',
        'order_id' => '',
        'warehouse' => '',
    ];

    public static function getMessage(string $type, array $params = []): string
    {
        $params = array_merge(self::DEFAULT_PARAMS, $params);
        $orderNum = ($params['order_id'] !== '' && $params['order_id'] !== null) ? $params['order_id'] : '—';
        $doc = $params['doc_number'];

        switch ($type) {
            case NotificationEventService::TYPE_SHIPPED:
                return "Ваше замовлення №{$orderNum} відправлено.\nНакладна: {$doc}. Відстежити: https://novaposhta.ua/tracking/{$doc}";
            case NotificationEventService::TYPE_ARRIVED:
                return "Ваше замовлення №{$orderNum} прибуло у відділення.\nНакладна: {$doc}.";
            case NotificationEventService::TYPE_REMINDER_2DAY:
                return "Ваше замовлення №{$orderNum} чекає у відділенні вже 2 дні.\nНакладна: {$doc}.";
            case NotificationEventService::TYPE_STORAGE_WARNING:
                return "Ваше замовлення №{$orderNum}: завтра почнеться платне зберігання. Заберіть будь ласка сьогодні.\nНакладна: {$doc}.";
            default:
                return $params['message'] ?? 'Повідомлення від магазину.';
        }
    }
}
