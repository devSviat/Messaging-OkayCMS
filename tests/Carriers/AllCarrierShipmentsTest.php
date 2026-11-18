<?php

namespace Modules\Sviat\Messaging\Carriers;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Modules;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\ModulesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Meest\Integration\MeestOrders;
use Okay\Modules\OkayCMS\NovaposhtaCost\Entities\NPCostDeliveryDataEntity;
use Okay\Modules\Sviat\Messaging\Carriers\AllCarrierShipments;
use Okay\Modules\Sviat\Messaging\Carriers\Carrier;
use Okay\Modules\Sviat\Messaging\Carriers\CarrierShipment;
use Okay\Modules\Sviat\Messaging\Carriers\DeliveryCarriers;
use Okay\Modules\Sviat\Messaging\Carriers\MeestShipments;
use Okay\Modules\Sviat\Messaging\Carriers\NovaPoshtaShipments;
use Okay\Modules\Sviat\Messaging\Carriers\UkrposhtaShipments;
use Okay\Modules\Sviat\NovaPoshta\Integration\NpOrders;
use Okay\Modules\Sviat\NovaPoshtaTracking\Entities\NovaPoshtaTrackingEntity;
use Okay\Modules\Sviat\Ukrposhta\Integration\UpOrders;
use PHPUnit\Framework\TestCase;

if (!class_exists(NpOrders::class)) {
    require_once __DIR__ . '/../NpOrdersStub.php';
}
if (!class_exists(NovaPoshtaTrackingEntity::class)) {
    require_once __DIR__ . '/../NovaPoshtaTrackingEntityStub.php';
}
if (!class_exists(UpOrders::class)) {
    require_once __DIR__ . '/../UpOrdersStub.php';
}
if (!class_exists(MeestOrders::class)) {
    require_once __DIR__ . '/../MeestOrdersStub.php';
}

/**
 * Одне замовлення — один потік SMS, навіть коли менеджер змінив перевізника. Покинута накладна
 * Нової Пошти лишається «в дорозі» без строку давності, тож перемагає перевізник поточної доставки.
 */
class AllCarrierShipmentsTest extends TestCase
{
    private const int NP_DELIVERY = 1;
    private const int UP_DELIVERY = 2;
    private const int PICKUP = 3;
    private const int NP_COST_DELIVERY = 4;
    private const int MEEST_DELIVERY = 5;

    /** @param array<int, object> $byOrder */
    private static function facade(array $byOrder): object
    {
        return new class ($byOrder) {
            public function __construct(private array $byOrder)
            {
            }

            public function unfinished(): array
            {
                return array_filter($this->byOrder, static fn(object $s): bool => !in_array($s->statusCode ?? null, [2, 9, 11, 102, 103, 106], true));
            }

            public function shipments(array $orderIds): array
            {
                return array_intersect_key($this->byOrder, array_flip($orderIds));
            }
        };
    }

    /** @param array<int, object> $byOrder */
    private static function meestFacade(array $byOrder): object
    {
        return new class ($byOrder) {
            public function __construct(private array $byOrder)
            {
            }

            public function unfinished(): array
            {
                return array_filter($this->byOrder, static fn(object $p): bool => !in_array($p->group, ['received', 'returning', 'returned', 'cancelled'], true));
            }

            public function parcels(array $orderIds): array
            {
                return array_intersect_key($this->byOrder, array_flip($orderIds));
            }
        };
    }

    /**
     * @param array<int, object> $np
     * @param array<int, object> $up
     * @param array<int, int> $deliveryOf id доставки за id замовлення
     * @param list<object> $oldRows рядки старого NovaPoshtaTracking
     * @param array<int, object> $meest посилки Meest; модуль увімкнений, лише коли їх передано
     */
    private function all(bool $npActive, bool $upActive, array $np = [], array $up = [], array $deliveryOf = [], array $oldRows = [], bool $reversed = false, array $meest = []): AllCarrierShipments
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(static fn(string $vendor, string $name): bool => ($name === 'NovaPoshta' && $npActive)
            || ($name === 'NovaPoshtaTracking' && $oldRows !== []) || ($name === 'Ukrposhta' && $upActive) || ($name === 'Meest' && $meest !== []));
        $npFacade = self::facade($np);
        $upFacade = self::facade($up);
        $meestFacade = self::meestFacade($meest);

        $tracking = $this->createStub(NovaPoshtaTrackingEntity::class);
        $tracking->method('find')->willReturn($oldRows);
        $tracking->method('findOne')->willReturnCallback(static function (array $filter) use ($oldRows) {
            foreach ($oldRows as $row) {
                if ((int)$row->order_id === (int)$filter['order_id']) {
                    return $row;
                }
            }

            return false;
        });
        $cost = $this->createStub(NPCostDeliveryDataEntity::class);
        $cost->method('find')->willReturn([]);
        $orders = $this->createStub(OrdersEntity::class);
        $orders->method('find')->willReturnCallback(static fn(array $filter): array => array_map(
            static fn(int $id): object => (object)['id' => $id, 'delivery_id' => $deliveryOf[$id] ?? 0],
            array_values(array_filter($filter['id'], static fn(int $id): bool => isset($deliveryOf[$id])))
        ));
        $deliveries = $this->createStub(DeliveriesEntity::class);
        $deliveries->method('find')->willReturn([
            (object)['id' => self::NP_DELIVERY, 'module_id' => 11],
            (object)['id' => self::UP_DELIVERY, 'module_id' => 12],
            (object)['id' => self::PICKUP, 'module_id' => 0],
            (object)['id' => self::NP_COST_DELIVERY, 'module_id' => 13],
            (object)['id' => self::MEEST_DELIVERY, 'module_id' => 14],
        ]);
        $modulesEntity = $this->createStub(ModulesEntity::class);
        $modulesEntity->method('find')->willReturn([
            (object)['id' => 11, 'vendor' => 'Sviat', 'module_name' => 'NovaPoshta'],
            (object)['id' => 12, 'vendor' => 'Sviat', 'module_name' => 'Ukrposhta'],
            (object)['id' => 13, 'vendor' => 'OkayCMS', 'module_name' => 'NovaposhtaCost'],
            (object)['id' => 14, 'vendor' => 'Sviat', 'module_name' => 'Meest'],
        ]);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(static fn(string $class) => match ($class) {
            NovaPoshtaTrackingEntity::class => $tracking,
            NPCostDeliveryDataEntity::class => $cost,
            OrdersEntity::class => $orders,
            DeliveriesEntity::class => $deliveries,
            ModulesEntity::class => $modulesEntity,
        });

        $adapters = [
            new NovaPoshtaShipments($modules, $entityFactory, static fn(): object => $npFacade),
            new UkrposhtaShipments($modules, static fn(): object => $upFacade),
            new MeestShipments($modules, static fn(): object => $meestFacade),
        ];

        return new AllCarrierShipments(new DeliveryCarriers($entityFactory), ...($reversed ? array_reverse($adapters) : $adapters));
    }

    private static function np(int $orderId, ?int $code = 4): object
    {
        return (object)['orderId' => $orderId, 'number' => '2045000000' . $orderId, 'statusCode' => $code, 'statusAt' => null, 'arrivedAt' => null, 'cod' => false];
    }

    private static function up(int $orderId, string $group = 'in_transit'): object
    {
        return (object)['orderId' => $orderId, 'barcode' => '05000000' . $orderId, 'group' => $group, 'statusAt' => null, 'arrivedAt' => null, 'cod' => false];
    }

    public function testCurrentDeliveryPicksTheCarrier(): void
    {
        $all = $this->all(true, true,
            [5 => self::np(5, null), 6 => self::np(6), 7 => self::np(7), 8 => self::np(8), 9 => self::np(9)],
            [5 => self::up(5), 6 => self::up(6), 7 => self::up(7), 8 => self::up(8), 10 => self::up(10)],
            [5 => self::UP_DELIVERY, 6 => self::NP_DELIVERY, 7 => self::PICKUP, 8 => self::NP_COST_DELIVERY],
        );
        $active = $all->active();
        ksort($active);

        self::assertSame([5, 6, 7, 8, 9, 10], array_keys($active));
        self::assertSame(Carrier::Ukrposhta, $active[5]->carrier, 'покинута накладна НП не глушить Укрпошту поточної доставки');
        self::assertSame(Carrier::NovaPoshta, $active[6]->carrier);
        self::assertSame(Carrier::NovaPoshta, $active[7]->carrier, 'доставка без перевізника: як раніше, Нова Пошта');
        self::assertSame(Carrier::NovaPoshta, $active[8]->carrier);
        self::assertSame(Carrier::NovaPoshta, $active[9]->carrier);
        self::assertSame(Carrier::Ukrposhta, $active[10]->carrier);
        self::assertSame('20450000006', $active[6]->number);

        self::assertSame(Carrier::Ukrposhta, $all->forOrder(5)->carrier);
        self::assertSame(Carrier::NovaPoshta, $all->forOrder(6)->carrier);
        self::assertSame(Carrier::NovaPoshta, $all->forOrder(7)->carrier);
        self::assertSame(Carrier::Ukrposhta, $all->forOrder(10)->carrier);
        self::assertTrue($all->available());
    }

    /** Отримана накладна НП не ховає живу посилку Укрпошти ні в active(), ні в forOrder(). */
    public function testFinalNpDoesNotHideUkrposhta(): void
    {
        $all = $this->all(true, true, [5 => self::np(5, 9)], [5 => self::up(5)], [5 => self::UP_DELIVERY]);

        self::assertSame(Carrier::Ukrposhta, $all->active()[5]->carrier);
        self::assertSame(Carrier::Ukrposhta, $all->forOrder(5)->carrier);
    }

    /** Старий трекінг віддає й фінальні рядки; результат має бути той самий, що з новим модулем. */
    public function testOldBackendFinalRowDoesNotSilenceUkrposhta(): void
    {
        $old = [(object)['order_id' => 5, 'int_doc_number' => '20450000000005', 'status_code' => '9', 'actual_delivery_at' => null]];
        $all = $this->all(false, true, [], [5 => self::up(5)], [5 => self::PICKUP], $old);

        self::assertSame(Carrier::Ukrposhta, $all->active()[5]->carrier);
        self::assertSame(Carrier::Ukrposhta, $all->forOrder(5)->carrier);
    }

    /** Повернення Укрпошти фінальне: заміну від НП не глушить, хоч доставка замовлення ще Укрпошта. */
    public function testFinalUkrposhtaDoesNotHideNovaPoshtaReplacement(): void
    {
        $all = $this->all(true, true, [5 => self::np(5)], [5 => self::up(5, 'returning')], [5 => self::UP_DELIVERY]);

        self::assertSame(Carrier::NovaPoshta, $all->active()[5]->carrier);
        self::assertSame(Carrier::NovaPoshta, $all->forOrder(5)->carrier);
    }

    public function testBothActiveWithUkrposhtaDeliveryStaysUkrposhta(): void
    {
        $all = $this->all(true, true, [5 => self::np(5)], [5 => self::up(5)], [5 => self::UP_DELIVERY]);

        self::assertSame(Carrier::Ukrposhta, $all->active()[5]->carrier);
        self::assertSame(Carrier::Ukrposhta, $all->forOrder(5)->carrier);
    }

    public function testFinalNpAloneStaysForCodLookup(): void
    {
        $all = $this->all(true, true, [5 => self::np(5, 9)], [], [5 => self::NP_DELIVERY]);

        self::assertArrayNotHasKey(5, $all->active());
        self::assertSame(Carrier::NovaPoshta, $all->forOrder(5)->carrier);
    }

    public function testOnlyUkrposhta(): void
    {
        $all = $this->all(false, true, [5 => self::np(5)], [5 => self::up(5)]);

        self::assertTrue($all->available());
        self::assertSame(Carrier::Ukrposhta, $all->active()[5]->carrier);
        self::assertSame(Carrier::Ukrposhta, $all->forOrder(5)->carrier);
    }

    public function testAdapterOrderDoesNotMatter(): void
    {
        $np = [5 => self::np(5, null), 6 => self::np(6), 7 => self::np(7), 8 => self::np(8), 9 => self::np(9)];
        $up = [5 => self::up(5), 6 => self::up(6), 7 => self::up(7), 8 => self::up(8), 10 => self::up(10)];
        $deliveryOf = [5 => self::UP_DELIVERY, 6 => self::NP_DELIVERY, 7 => self::PICKUP, 8 => self::NP_COST_DELIVERY];
        $straight = $this->all(true, true, $np, $up, $deliveryOf);
        $reversed = $this->all(true, true, $np, $up, $deliveryOf, [], true);

        $carrierOf = static fn(array $shipments): array => array_map(static fn(CarrierShipment $s): Carrier => $s->carrier, $shipments);
        $activeStraight = $straight->active();
        $activeReversed = $reversed->active();
        ksort($activeStraight);
        ksort($activeReversed);

        self::assertSame($carrierOf($activeStraight), $carrierOf($activeReversed));
        foreach ([5, 6, 7, 8, 9, 10] as $orderId) {
            self::assertSame($straight->forOrder($orderId)->carrier, $reversed->forOrder($orderId)->carrier, 'замовлення ' . $orderId);
        }
        self::assertSame(Carrier::Ukrposhta, $reversed->forOrder(5)->carrier);
        self::assertTrue($reversed->available());
    }

    /** Обидві накладні фінальні й доставка НП: forOrder віддає Укрпошту, бо фінальність НП перевіряється першою. */
    public function testBothFinalKeepsTodaysResult(): void
    {
        $all = $this->all(true, true, [5 => self::np(5, 9)], [5 => self::up(5, 'returning')], [5 => self::NP_DELIVERY]);

        self::assertArrayNotHasKey(5, $all->active());
        self::assertSame(Carrier::Ukrposhta, $all->forOrder(5)->carrier);
    }

    private static function meest(int $orderId, string $group = 'in_transit'): object
    {
        return (object)['orderId' => $orderId, 'barcode' => 'MEEST-' . $orderId, 'group' => $group, 'statusAt' => null, 'arrivedAt' => null];
    }

    /** Покупець обрав Meest після того, як менеджер уже створив накладну НП чи відправлення Укрпошти. */
    public function testMeestWinsWhenDeliveryIsMeest(): void
    {
        $all = $this->all(true, true, [5 => self::np(5)], [6 => self::up(6)], [5 => self::MEEST_DELIVERY, 6 => self::MEEST_DELIVERY], [], false, [5 => self::meest(5), 6 => self::meest(6)]);
        $active = $all->active();

        self::assertSame(Carrier::Meest, $active[5]->carrier);
        self::assertSame(Carrier::Meest, $active[6]->carrier);
        self::assertSame(Carrier::Meest, $all->forOrder(5)->carrier);
        self::assertSame(Carrier::Meest, $all->forOrder(6)->carrier);
    }

    /** Менеджер створив посилку Meest, потім перевів замовлення на іншу доставку: покинута посилка мовчить. */
    public function testAbandonedMeestLosesToCurrentDelivery(): void
    {
        foreach ([false, true] as $reversed) {
            $all = $this->all(true, true, [5 => self::np(5)], [6 => self::up(6)], [5 => self::NP_DELIVERY, 6 => self::UP_DELIVERY], [], $reversed, [5 => self::meest(5), 6 => self::meest(6)]);
            $active = $all->active();

            self::assertSame(Carrier::NovaPoshta, $active[5]->carrier);
            self::assertSame(Carrier::Ukrposhta, $active[6]->carrier);
            self::assertSame(Carrier::NovaPoshta, $all->forOrder(5)->carrier);
            self::assertSame(Carrier::Ukrposhta, $all->forOrder(6)->carrier);
        }
    }

    /** Повернення Meest фінальне й не глушить заміну, навіть коли доставка замовлення ще Meest; і навпаки. */
    public function testFinalMeestLosesToNonFinal(): void
    {
        $all = $this->all(true, true, [5 => self::np(5), 7 => self::np(7, 9)], [6 => self::up(6, 'returning')], [5 => self::MEEST_DELIVERY, 6 => self::UP_DELIVERY, 7 => self::NP_DELIVERY], [], false,
            [5 => self::meest(5, 'returning'), 6 => self::meest(6), 7 => self::meest(7)]);
        $active = $all->active();

        self::assertSame(Carrier::NovaPoshta, $active[5]->carrier);
        self::assertSame(Carrier::Meest, $active[6]->carrier);
        self::assertSame(Carrier::Meest, $active[7]->carrier);
        self::assertSame(Carrier::NovaPoshta, $all->forOrder(5)->carrier);
        self::assertSame(Carrier::Meest, $all->forOrder(6)->carrier);
        self::assertSame(Carrier::Meest, $all->forOrder(7)->carrier);
    }

    public function testNoCarrierDeliveryFallsBackToNp(): void
    {
        $all = $this->all(true, true, [5 => self::np(5)], [6 => self::up(6)], [5 => self::PICKUP, 6 => self::PICKUP], [], false, [5 => self::meest(5), 6 => self::meest(6)]);
        $reversed = $this->all(true, true, [5 => self::np(5)], [6 => self::up(6)], [5 => self::PICKUP, 6 => self::PICKUP], [], true, [5 => self::meest(5), 6 => self::meest(6)]);

        self::assertSame(Carrier::NovaPoshta, $all->active()[5]->carrier);
        self::assertSame(Carrier::NovaPoshta, $all->forOrder(5)->carrier);
        self::assertSame($all->active()[6]->carrier, $reversed->active()[6]->carrier, 'без НП вибір не залежить від порядку адаптерів');
        self::assertSame($all->forOrder(6)->carrier, $reversed->forOrder(6)->carrier);
    }

    public function testMeestAloneIsTaken(): void
    {
        $all = $this->all(true, true, [], [], [5 => self::NP_DELIVERY, 6 => self::PICKUP], [], false, [5 => self::meest(5), 6 => self::meest(6, 'arrived'), 7 => self::meest(7)]);
        $active = $all->active();
        ksort($active);

        self::assertSame([5, 6, 7], array_keys($active));
        self::assertSame([Carrier::Meest, Carrier::Meest, Carrier::Meest], array_values(array_map(static fn(CarrierShipment $s): Carrier => $s->carrier, $active)));
        self::assertSame('MEEST-6', $all->forOrder(6)->number);
        self::assertTrue($this->all(false, false, [], [], [], [], false, [5 => self::meest(5)])->available());
    }

    public function testNeitherAvailable(): void
    {
        $all = $this->all(false, false, [5 => self::np(5)], [6 => self::up(6)]);

        self::assertFalse($all->available());
        self::assertSame([], $all->active());
        self::assertNull($all->forOrder(5));
        self::assertNull($all->forOrder(6));
    }
}
