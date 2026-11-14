<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

use Closure;
use Okay\Core\Modules\Modules;
use Okay\Core\ServiceLocator;
use Okay\Modules\Sviat\Ukrposhta\Integration\UpOrders;

/** Відправлення модуля Sviat/Ukrposhta для SMS; без модуля або коли він вимкнений, їх немає. */
final class UkrposhtaShipments implements CarrierShipments
{
    /** Групи статусів Укрпошти означають ті самі події, що й коди Нової Пошти (специфікація, «Інтеграції»). */
    private const array EVENTS = [
        'created' => null,
        'accepted' => ShipmentEvent::Shipped,
        'in_transit' => ShipmentEvent::Shipped,
        'problem' => ShipmentEvent::Shipped,
        'arrived' => ShipmentEvent::Arrived,
        'storage' => ShipmentEvent::Arrived,
        'received' => ShipmentEvent::Received,
        'returning' => ShipmentEvent::Returned,
        'returned' => ShipmentEvent::Returned,
        'cancelled' => ShipmentEvent::Closed,
    ];

    private readonly Closure $upOrders;

    /** @param ?Closure(): object $upOrders фасад UpOrders; береться лише коли модуль увімкнений */
    public function __construct(private readonly Modules $modules, ?Closure $upOrders = null)
    {
        $this->upOrders = $upOrders ?? static fn(): object => ServiceLocator::getInstance()->getService(UpOrders::class);
    }

    public function available(): bool
    {
        return class_exists(UpOrders::class) && $this->modules->isActiveModule('Sviat', 'Ukrposhta');
    }

    public function active(): array
    {
        if (!$this->available()) {
            return [];
        }
        $result = [];
        foreach (($this->upOrders)()->unfinished() as $shipment) {
            $result[(int)$shipment->orderId] = self::fromFacade($shipment);
        }

        return $result;
    }

    public function forOrder(int $orderId): ?CarrierShipment
    {
        if (!$this->available()) {
            return null;
        }
        $shipment = ($this->upOrders)()->shipments([$orderId])[$orderId] ?? null;

        return $shipment === null ? null : self::fromFacade($shipment);
    }

    private static function fromFacade(object $shipment): CarrierShipment
    {
        return new CarrierShipment(
            (int)$shipment->orderId,
            Carrier::Ukrposhta,
            (string)$shipment->barcode,
            self::EVENTS[(string)$shipment->group] ?? null,
            $shipment->arrivedAt,
            (bool)$shipment->cod,
        );
    }
}
