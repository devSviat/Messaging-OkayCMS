<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

use Closure;
use Okay\Core\Modules\Modules;
use Okay\Core\ServiceLocator;
use Okay\Modules\Sviat\RozetkaDelivery\Integration\RozetkaOrders;

/** ЕН модуля Sviat/RozetkaDelivery для SMS; без модуля або коли він вимкнений, їх немає. */
final class RozetkaShipments implements CarrierShipments
{
    /**
     * Відмова одержувача означає, що посилка їде назад, тож вона закриває SMS так само, як повернення.
     * «Проблема» буває й до відправлення (точка не прийняла посилку за реєстром), тож SMS про неї немає.
     */
    private const array EVENTS = [
        'created' => null,
        'accepted' => ShipmentEvent::Shipped,
        'in_transit' => ShipmentEvent::Shipped,
        'problem' => null,
        'arrived' => ShipmentEvent::Arrived,
        'received' => ShipmentEvent::Received,
        'refused' => ShipmentEvent::Returned,
        'returning' => ShipmentEvent::Returned,
        'returned' => ShipmentEvent::Returned,
        'cancelled' => ShipmentEvent::Closed,
    ];

    private readonly Closure $rozetkaOrders;

    /** @param ?Closure(): object $rozetkaOrders фасад RozetkaOrders; береться лише коли модуль увімкнений */
    public function __construct(private readonly Modules $modules, ?Closure $rozetkaOrders = null)
    {
        $this->rozetkaOrders = $rozetkaOrders ?? static fn(): object => ServiceLocator::getInstance()->getService(RozetkaOrders::class);
    }

    public function carrier(): Carrier
    {
        return Carrier::Rozetka;
    }

    public function available(): bool
    {
        return class_exists(RozetkaOrders::class) && $this->modules->isActiveModule('Sviat', 'RozetkaDelivery');
    }

    public function active(): array
    {
        if (!$this->available()) {
            return [];
        }
        $result = [];
        foreach (($this->rozetkaOrders)()->unfinished() as $parcel) {
            $result[(int)$parcel->orderId] = self::fromFacade($parcel);
        }

        return $result;
    }

    public function forOrder(int $orderId): ?CarrierShipment
    {
        if (!$this->available()) {
            return null;
        }
        $parcel = ($this->rozetkaOrders)()->parcels([$orderId])[$orderId] ?? null;

        return $parcel === null ? null : self::fromFacade($parcel);
    }

    private static function fromFacade(object $parcel): CarrierShipment
    {
        return new CarrierShipment(
            (int)$parcel->orderId,
            Carrier::Rozetka,
            (string)$parcel->trackId,
            self::EVENTS[(string)$parcel->group] ?? null,
            $parcel->arrivedAt,
            (bool)$parcel->cod,
        );
    }
}
