<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

/**
 * `event` null, якщо статус ще невідомий або не означає жодної SMS. `arrivedAt`: коли посилка прибула
 * у відділення. `cod`: отримувач платить при отриманні.
 */
final readonly class CarrierShipment
{
    public function __construct(
        public int $orderId,
        public Carrier $carrier,
        public string $number,
        public ?ShipmentEvent $event,
        public ?string $arrivedAt,
        public bool $cod,
    ) {
    }
}
