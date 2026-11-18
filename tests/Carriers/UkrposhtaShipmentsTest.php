<?php

namespace Modules\Sviat\Messaging\Carriers;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Modules;
use Okay\Modules\Sviat\Messaging\Carriers\AllCarrierShipments;
use Okay\Modules\Sviat\Messaging\Carriers\Carrier;
use Okay\Modules\Sviat\Messaging\Carriers\CarrierShipment;
use Okay\Modules\Sviat\Messaging\Carriers\DeliveryCarriers;
use Okay\Modules\Sviat\Messaging\Carriers\NovaPoshtaShipments;
use Okay\Modules\Sviat\Messaging\Carriers\ShipmentEvent;
use Okay\Modules\Sviat\Messaging\Carriers\UkrposhtaShipments;
use Okay\Modules\Sviat\NovaPoshta\Integration\NpOrders;
use Okay\Modules\Sviat\NovaPoshtaTracking\Entities\NovaPoshtaTrackingEntity;
use Okay\Modules\Sviat\Ukrposhta\Integration\UpOrders;
use Okay\Modules\Sviat\Ukrposhta\Shipments\ShipmentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!class_exists(UpOrders::class)) {
    require_once __DIR__ . '/../UpOrdersStub.php';
}
if (!class_exists(NpOrders::class)) {
    require_once __DIR__ . '/../NpOrdersStub.php';
}
if (!class_exists(NovaPoshtaTrackingEntity::class)) {
    require_once __DIR__ . '/../NovaPoshtaTrackingEntityStub.php';
}

/**
 * SMS про посилки Укрпошти будуються з груп статусів її модуля. Помилка тут — повідомлення
 * «відправлено» про повернену посилку або тиша про посилку, що чекає у відділенні.
 */
class UkrposhtaShipmentsTest extends TestCase
{
    /** Таблиця зі специфікації: групи Укрпошти означають ті самі події, що й коди Нової Пошти. */
    private const array EXPECTED = [
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

    private static function shipment(int $orderId, ?string $group, ?string $arrivedAt = null, bool $cod = false): object
    {
        return (object)['orderId' => $orderId, 'barcode' => '05000000' . $orderId, 'group' => $group, 'statusAt' => $arrivedAt, 'arrivedAt' => $arrivedAt, 'cod' => $cod];
    }

    /** @param list<object> $shipments */
    private function source(bool $active, array $shipments): UkrposhtaShipments
    {
        $byOrder = [];
        foreach ($shipments as $s) {
            $byOrder[$s->orderId] = $s;
        }
        $facade = new class ($byOrder) {
            public function __construct(private array $byOrder)
            {
            }

            public function unfinished(): array
            {
                return $this->byOrder;
            }

            public function shipments(array $orderIds): array
            {
                return array_intersect_key($this->byOrder, array_flip($orderIds));
            }
        };

        return new UkrposhtaShipments($this->modules($active), static fn(): object => $facade);
    }

    private function modules(bool $ukrposhta): Modules
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(static fn(string $vendor, string $name): bool => $name === 'Ukrposhta' && $ukrposhta);

        return $modules;
    }

    #[DataProvider('groupEvents')]
    public function testGroupsMapToEvents(string $group, ?ShipmentEvent $event): void
    {
        $active = $this->source(true, [self::shipment(7, $group)])->active();

        self::assertSame($event, $active[7]->event);
    }

    public static function groupEvents(): array
    {
        $cases = [];
        foreach (self::EXPECTED as $group => $event) {
            $cases[$group] = [$group, $event];
        }

        return $cases;
    }

    public function testCarrierIsUkrposhta(): void
    {
        self::assertSame(Carrier::Ukrposhta, $this->source(true, [])->carrier());
    }

    /** Нова група в модулі Укрпошти без рядка в мапінгу мовчки глушила б SMS. */
    public function testEveryUkrposhtaGroupIsCovered(): void
    {
        if (!class_exists(ShipmentStatus::class)) {
            self::markTestSkipped('модуля Укрпошти в цьому прогоні немає');
        }
        $groups = array_keys(ShipmentStatus::FILTER_GROUPS);

        self::assertSame(array_values(array_diff($groups, ['none'])), array_keys(self::EXPECTED));
    }

    public function testCarriesBarcodeArrivalAndCod(): void
    {
        $shipment = $this->source(true, [self::shipment(7, 'arrived', '2026-11-10 12:00:00', true)])->forOrder(7);

        self::assertEquals(new CarrierShipment(7, Carrier::Ukrposhta, '050000007', ShipmentEvent::Arrived, '2026-11-10 12:00:00', true), $shipment);
        self::assertNull($this->source(true, [])->forOrder(7));
    }

    public function testUnknownGroupRaisesNoEvent(): void
    {
        self::assertNull($this->source(true, [self::shipment(7, null)])->active()[7]->event);
        self::assertNull($this->source(true, [self::shipment(7, 'brand_new')])->active()[7]->event);
    }

    public function testUkrposhtaDisabledLeavesNpOnly(): void
    {
        $source = new UkrposhtaShipments($this->modules(false), static fn(): object => throw new \LogicException('фасад вимкненого модуля не будується'));

        self::assertFalse($source->available());
        self::assertSame([], $source->active());
        self::assertNull($source->forOrder(7));

        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(static fn(string $vendor, string $name): bool => $name === 'NovaPoshta');
        $np = new class {
            public function unfinished(): array
            {
                return [5 => (object)['orderId' => 5, 'number' => '20450000005', 'statusCode' => 4, 'statusAt' => null, 'arrivedAt' => null, 'cod' => false]];
            }

            public function shipments(array $orderIds): array
            {
                return array_intersect_key($this->unfinished(), array_flip($orderIds));
            }
        };
        $all = new AllCarrierShipments(
            new DeliveryCarriers($this->createStub(EntityFactory::class)),
            new NovaPoshtaShipments($modules, $this->createStub(EntityFactory::class), static fn(): object => $np),
            new UkrposhtaShipments($modules, static fn(): object => throw new \LogicException('фасад вимкненого модуля не будується')),
        );

        self::assertSame([5], array_keys($all->active()));
        self::assertSame(Carrier::NovaPoshta, $all->forOrder(5)->carrier);
        self::assertNull($all->forOrder(7));
    }

    /** Посилка повертається відправнику: подія фінальна, крон її відсіює, покупцю нічого не йде. */
    public function testReturningUkrposhtaSendsNothing(): void
    {
        $active = $this->source(true, [self::shipment(7, 'returning'), self::shipment(8, 'returned')])->active();

        self::assertSame(ShipmentEvent::Returned, $active[7]->event);
        self::assertTrue($active[7]->event->isFinal());
        self::assertTrue($active[8]->event->isFinal());
    }
}
