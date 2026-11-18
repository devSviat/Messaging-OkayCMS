<?php

namespace Modules\Sviat\Messaging\Carriers;

use Okay\Core\Modules\Modules;
use Okay\Modules\Sviat\Meest\Integration\MeestOrders;
use Okay\Modules\Sviat\Meest\Parcels\ParcelStatus;
use Okay\Modules\Sviat\Messaging\Carriers\Carrier;
use Okay\Modules\Sviat\Messaging\Carriers\CarrierShipment;
use Okay\Modules\Sviat\Messaging\Carriers\MeestShipments;
use Okay\Modules\Sviat\Messaging\Carriers\ShipmentEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!class_exists(MeestOrders::class)) {
    require_once __DIR__ . '/../MeestOrdersStub.php';
}

/**
 * SMS про посилки Meest будуються з груп статусів його модуля. Помилка тут — повідомлення
 * «прибуло» про посилку, що повертається, або тиша про посилку в пункті видачі.
 */
class MeestShipmentsTest extends TestCase
{
    private const array EXPECTED = [
        'created' => null,
        'in_transit' => ShipmentEvent::Shipped,
        'arrived' => ShipmentEvent::Arrived,
        'storage' => ShipmentEvent::Arrived,
        'problem' => ShipmentEvent::Shipped,
        'returning' => ShipmentEvent::Returned,
        'received' => ShipmentEvent::Received,
        'returned' => ShipmentEvent::Returned,
        'cancelled' => ShipmentEvent::Closed,
    ];

    private static function parcel(int $orderId, ?string $group, ?string $arrivedAt = null): object
    {
        return (object)['orderId' => $orderId, 'barcode' => 'MEEST-' . $orderId, 'group' => $group, 'statusAt' => $arrivedAt, 'arrivedAt' => $arrivedAt];
    }

    /** @param list<object> $parcels */
    private function source(bool $active, array $parcels): MeestShipments
    {
        $byOrder = [];
        foreach ($parcels as $p) {
            $byOrder[$p->orderId] = $p;
        }
        $facade = new class ($byOrder) {
            public function __construct(private array $byOrder)
            {
            }

            public function unfinished(): array
            {
                return $this->byOrder;
            }

            public function parcels(array $orderIds): array
            {
                return array_intersect_key($this->byOrder, array_flip($orderIds));
            }
        };

        return new MeestShipments($this->modules($active), static fn(): object => $facade);
    }

    private function modules(bool $meest): Modules
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(static fn(string $vendor, string $name): bool => $name === 'Meest' && $meest);

        return $modules;
    }

    #[DataProvider('groupEvents')]
    public function testGroupsMapToEvents(string $group, ?ShipmentEvent $event): void
    {
        $active = $this->source(true, [self::parcel(7, $group)])->active();

        self::assertSame($event, $active[7]->event);
        self::assertFalse($active[7]->cod);
    }

    public static function groupEvents(): array
    {
        $cases = [];
        foreach (self::EXPECTED as $group => $event) {
            $cases[$group] = [$group, $event];
        }

        return $cases;
    }

    /** Нова група в модулі Meest без рядка в мапінгу мовчки глушила б SMS. */
    public function testEveryMeestGroupIsCovered(): void
    {
        if (!class_exists(ParcelStatus::class)) {
            self::markTestSkipped('модуля Meest у цьому прогоні немає');
        }
        $expected = array_keys(self::EXPECTED);
        sort($expected);
        $groups = array_values(array_diff(ParcelStatus::FILTER_GROUPS, ['none']));
        sort($groups);

        self::assertSame($groups, $expected);
    }

    public function testCarrierIsMeest(): void
    {
        self::assertSame(Carrier::Meest, $this->source(true, [])->carrier());
    }

    /** Meest працює без післяплати: cod завжди false, тож нагадування «чекає 2 дні» не ставиться. */
    public function testCarriesBarcodeAndArrivalWithoutCod(): void
    {
        $shipment = $this->source(true, [self::parcel(7, 'arrived', '2026-11-10 12:00:00')])->forOrder(7);

        self::assertEquals(new CarrierShipment(7, Carrier::Meest, 'MEEST-7', ShipmentEvent::Arrived, '2026-11-10 12:00:00', false), $shipment);
        self::assertNull($this->source(true, [])->forOrder(7));
    }

    public function testUnknownGroupRaisesNoEvent(): void
    {
        self::assertNull($this->source(true, [self::parcel(7, null)])->active()[7]->event);
        self::assertNull($this->source(true, [self::parcel(7, 'brand_new')])->active()[7]->event);
    }

    /** Модуль установлений, але вимкнений: фасад не будується, посилок немає. */
    public function testMeestDisabledLeavesOthers(): void
    {
        $source = new MeestShipments($this->modules(false), static fn(): object => throw new \LogicException('фасад вимкненого модуля не будується'));

        self::assertFalse($source->available());
        self::assertSame([], $source->active());
        self::assertNull($source->forOrder(7));
    }

    /** Посилка повертається відправнику: подія фінальна, крон її відсіює, покупцю нічого не йде. */
    public function testReturningMeestSendsNothing(): void
    {
        $active = $this->source(true, [self::parcel(7, 'returning'), self::parcel(8, 'returned'), self::parcel(9, 'cancelled')])->active();

        self::assertSame(ShipmentEvent::Returned, $active[7]->event);
        self::assertTrue($active[7]->event->isFinal());
        self::assertTrue($active[8]->event->isFinal());
        self::assertTrue($active[9]->event->isFinal());
    }

    public function testTrackingUrl(): void
    {
        self::assertSame('https://t.meest-group.com/MEEST-7', Carrier::Meest->trackingUrl('MEEST-7'));
        self::assertNull(Carrier::Meest->freeStorageDays());
    }
}
