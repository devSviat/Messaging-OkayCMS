<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

use Closure;
use Okay\Core\Modules\Modules;
use Okay\Core\ServiceLocator;
use Okay\Modules\Sviat\Meest\Integration\MeestOrders;

/** Посилки модуля Sviat/Meest для SMS; без модуля або коли він вимкнений, їх немає. */
final class MeestShipments implements CarrierShipments
{
    /** Групи статусів Meest означають ті самі події, що й групи Укрпошти (специфікація, «Адаптери»). */
    private const array EVENTS = [
        'created' => null,
        'in_transit' => ShipmentEvent::Shipped,
        'problem' => ShipmentEvent::Shipped,
        'arrived' => ShipmentEvent::Arrived,
        'storage' => ShipmentEvent::Arrived,
        'received' => ShipmentEvent::Received,
        'returning' => ShipmentEvent::Returned,
        'returned' => ShipmentEvent::Returned,
        'cancelled' => ShipmentEvent::Closed,
    ];

    private readonly Closure $meestOrders;

    /** @param ?Closure(): object $meestOrders фасад MeestOrders; береться лише коли модуль увімкнений */
    public function __construct(private readonly Modules $modules, ?Closure $meestOrders = null)
    {
        $this->meestOrders = $meestOrders ?? static fn(): object => ServiceLocator::getInstance()->getService(MeestOrders::class);
    }

    public function carrier(): Carrier
    {
        return Carrier::Meest;
    }

    public function available(): bool
    {
        return class_exists(MeestOrders::class) && $this->modules->isActiveModule('Sviat', 'Meest');
    }

    public function active(): array
    {
        if (!$this->available()) {
            return [];
        }
        $result = [];
        foreach (($this->meestOrders)()->unfinished() as $parcel) {
            $result[(int)$parcel->orderId] = self::fromFacade($parcel);
        }

        return $result;
    }

    public function forOrder(int $orderId): ?CarrierShipment
    {
        if (!$this->available()) {
            return null;
        }
        $parcel = ($this->meestOrders)()->parcels([$orderId])[$orderId] ?? null;

        return $parcel === null ? null : self::fromFacade($parcel);
    }

    /** Посилки Meest без післяплати (специфікація, «Адаптери»), тож `cod` завжди false. */
    private static function fromFacade(object $parcel): CarrierShipment
    {
        return new CarrierShipment(
            (int)$parcel->orderId,
            Carrier::Meest,
            (string)$parcel->barcode,
            self::EVENTS[(string)$parcel->group] ?? null,
            $parcel->arrivedAt,
            false,
        );
    }
}
