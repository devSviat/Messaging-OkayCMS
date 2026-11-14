<?php

namespace Okay\Modules\Sviat\Messaging\Helpers;

use Okay\Modules\Sviat\Messaging\Carriers\Carrier;
use Okay\Modules\Sviat\Messaging\Services\NotificationEventService;

/** Тексти SMS за типами подій. */
class MessageTemplates
{
    private const array DEFAULT_PARAMS = [
        'doc_number' => '',
        'order_id' => '',
        'warehouse' => '',
    ];

    public static function getMessage(string $type, array $params = [], Carrier $carrier = Carrier::NovaPoshta): string
    {
        $params = array_merge(self::DEFAULT_PARAMS, $params);
        $orderNum = ($params['order_id'] !== '' && $params['order_id'] !== null) ? $params['order_id'] : '—';
        $doc = $params['doc_number'];

        $text = match ($carrier) {
            Carrier::NovaPoshta => self::novaPoshta($type, $orderNum, $doc),
            Carrier::Ukrposhta => self::ukrposhta($type, $orderNum, $doc),
        };

        return $text ?? $params['message'] ?? 'Повідомлення від магазину.';
    }

    /**
     * Приклади для адмінки за типом події; типів, для яких перевізнику SMS не надсилається, немає.
     *
     * @return array<string, string>
     */
    public static function examples(Carrier $carrier): array
    {
        $params = ['doc_number' => $carrier === Carrier::NovaPoshta ? '20451329330540' : '0500000012345', 'order_id' => 12345];
        $types = [NotificationEventService::TYPE_SHIPPED, NotificationEventService::TYPE_ARRIVED, NotificationEventService::TYPE_REMINDER_2DAY];
        if ($carrier->freeStorageDays() !== null) {
            $types[] = NotificationEventService::TYPE_STORAGE_WARNING;
        }
        $examples = [];
        foreach ($types as $type) {
            $examples[$type] = self::getMessage($type, $params, $carrier);
        }

        return $examples;
    }

    private static function novaPoshta(string $type, mixed $orderNum, mixed $doc): ?string
    {
        return match ($type) {
            NotificationEventService::TYPE_SHIPPED => "Ваше замовлення №{$orderNum} відправлено.\nНакладна: {$doc}. Відстежити: " . Carrier::NovaPoshta->trackingUrl((string)$doc),
            NotificationEventService::TYPE_ARRIVED => "Ваше замовлення №{$orderNum} прибуло у відділення.\nНакладна: {$doc}.",
            NotificationEventService::TYPE_REMINDER_2DAY => "Ваше замовлення №{$orderNum} чекає у відділенні вже 2 дні.\nНакладна: {$doc}.",
            NotificationEventService::TYPE_STORAGE_WARNING => "Ваше замовлення №{$orderNum}: завтра почнеться платне зберігання. Заберіть будь ласка сьогодні.\nНакладна: {$doc}.",
            default => null,
        };
    }

    /** Попередження про платне зберігання немає: строки зберігання Укрпошти не змодельовані. */
    private static function ukrposhta(string $type, mixed $orderNum, mixed $doc): ?string
    {
        return match ($type) {
            NotificationEventService::TYPE_SHIPPED => "Ваше замовлення №{$orderNum} відправлено Укрпоштою.\nНомер відправлення: {$doc}. Відстежити: " . Carrier::Ukrposhta->trackingUrl((string)$doc),
            NotificationEventService::TYPE_ARRIVED => "Ваше замовлення №{$orderNum} прибуло у відділення Укрпошти.\nНомер відправлення: {$doc}.",
            NotificationEventService::TYPE_REMINDER_2DAY => "Ваше замовлення №{$orderNum} чекає у відділенні Укрпошти вже 2 дні.\nНомер відправлення: {$doc}.",
            default => null,
        };
    }
}
