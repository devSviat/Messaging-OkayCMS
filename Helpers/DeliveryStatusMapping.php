<?php

namespace Okay\Modules\Sviat\Messaging\Helpers;

/**
 * Мапінг кодів статусів Нова Пошта → події для SMS. Коди згідно офіційної документації НП.
 */
class DeliveryStatusMapping
{
    public const SYSTEM_IN_TRANSIT = 'in_transit';
    public const SYSTEM_ARRIVED = 'arrived';
    public const SYSTEM_RECEIVED = 'received';

    public const EVENT_SHIPPED = 'shipped';
    public const EVENT_ARRIVED = 'arrived';
    public const EVENT_RECEIVED = 'received';

    /** Код НП => [system_status, event]. В дорозі: 4,41,5,6,101. Прибуло: 7,8. Отримано: 9,10,11. */
    private const NOVA_POSHTA_MAP = [
        '4' => [self::SYSTEM_IN_TRANSIT, self::EVENT_SHIPPED],
        '41' => [self::SYSTEM_IN_TRANSIT, self::EVENT_SHIPPED],
        '5' => [self::SYSTEM_IN_TRANSIT, self::EVENT_SHIPPED],
        '6' => [self::SYSTEM_IN_TRANSIT, self::EVENT_SHIPPED],
        '101' => [self::SYSTEM_IN_TRANSIT, self::EVENT_SHIPPED],
        '7' => [self::SYSTEM_ARRIVED, self::EVENT_ARRIVED],
        '8' => [self::SYSTEM_ARRIVED, self::EVENT_ARRIVED],
        '9' => [self::SYSTEM_RECEIVED, self::EVENT_RECEIVED],
        '10' => [self::SYSTEM_RECEIVED, self::EVENT_RECEIVED],
        '11' => [self::SYSTEM_RECEIVED, self::EVENT_RECEIVED],
    ];

    private const NP_IN_TRANSIT = ['4' => true, '41' => true, '5' => true, '6' => true, '101' => true];
    private const NP_ARRIVED = ['7' => true, '8' => true];
    private const NP_RECEIVED = ['9' => true, '10' => true, '11' => true];

    /** Статуси, для яких не створюємо події: отримано, видалено, відмова, повернення, тощо. */
    public const NP_FINAL_STATUSES = ['2', '3', '9', '10', '11', '102', '103', '105', '106'];

    public static function fromNovaPoshta(string $statusCode): ?array
    {
        return self::NOVA_POSHTA_MAP[$statusCode] ?? null;
    }

    public static function isNovaPoshtaInTransit(string $statusCode): bool
    {
        return isset(self::NP_IN_TRANSIT[$statusCode]);
    }

    public static function isNovaPoshtaArrived(string $statusCode): bool
    {
        return isset(self::NP_ARRIVED[$statusCode]);
    }

    public static function isNovaPoshtaReceived(string $statusCode): bool
    {
        return isset(self::NP_RECEIVED[$statusCode]);
    }

    public static function isNovaPoshtaFinalStatus(string $statusCode): bool
    {
        return \in_array($statusCode, self::NP_FINAL_STATUSES, true);
    }
}
