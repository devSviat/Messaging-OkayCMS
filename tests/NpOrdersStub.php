<?php

/**
 * Заглушка фасаду сусіднього модуля Sviat/NovaPoshta.
 *
 * Модуль необов'язковий; у прогоні релізу в рушій кладуть лише Messaging. Файл
 * підключається лише за відсутності оригіналу, тож на стенді працює справжній клас.
 */

namespace Okay\Modules\Sviat\NovaPoshta\Integration;

final class NpOrders
{
    public function unfinished(): array
    {
        return [];
    }

    public function shipments(array $orderIds): array
    {
        return [];
    }
}
