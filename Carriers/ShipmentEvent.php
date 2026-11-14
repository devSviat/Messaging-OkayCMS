<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

/** Стан посилки, з якого крон вирішує, яку SMS ставити в чергу; однаковий для всіх перевізників. */
enum ShipmentEvent
{
    case Shipped;
    case Arrived;
    case Received;
    case Returned;
    case Closed;

    /** Після фінальної події SMS про посилку вже не надсилаються. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Received, self::Returned, self::Closed => true,
            self::Shipped, self::Arrived => false,
        };
    }
}
