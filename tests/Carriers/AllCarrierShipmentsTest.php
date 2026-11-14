<?php

namespace Modules\Sviat\Messaging\Carriers;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Modules;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\ModulesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Modules\OkayCMS\NovaposhtaCost\Entities\NPCostDeliveryDataEntity;
use Okay\Modules\Sviat\Messaging\Carriers\AllCarrierShipments;
use Okay\Modules\Sviat\Messaging\Carriers\Carrier;
use Okay\Modules\Sviat\Messaging\Carriers\DeliveryCarriers;
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

    /**
     * @param array<int, object> $np
     * @param array<int, object> $up
     * @param array<int, int> $deliveryOf id доставки за id замовлення
     * @param list<object> $oldRows рядки старого NovaPoshtaTracking
     */
    private function all(bool $npActive, bool $upActive, array $np = [], array $up = [], array $deliveryOf = [], array $oldRows = []): AllCarrierShipments
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(static fn(string $vendor, string $name): bool => ($name === 'NovaPoshta' && $npActive)
            || ($name === 'NovaPoshtaTracking' && $oldRows !== []) || ($name === 'Ukrposhta' && $upActive));
        $npFacade = self::facade($np);
        $upFacade = self::facade($up);

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
        ]);
        $modulesEntity = $this->createStub(ModulesEntity::class);
        $modulesEntity->method('find')->willReturn([
            (object)['id' => 11, 'vendor' => 'Sviat', 'module_name' => 'NovaPoshta'],
            (object)['id' => 12, 'vendor' => 'Sviat', 'module_name' => 'Ukrposhta'],
            (object)['id' => 13, 'vendor' => 'OkayCMS', 'module_name' => 'NovaposhtaCost'],
        ]);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(static fn(string $class) => match ($class) {
            NovaPoshtaTrackingEntity::class => $tracking,
            NPCostDeliveryDataEntity::class => $cost,
            OrdersEntity::class => $orders,
            DeliveriesEntity::class => $deliveries,
            ModulesEntity::class => $modulesEntity,
        });

        return new AllCarrierShipments(
            new NovaPoshtaShipments($modules, $entityFactory, static fn(): object => $npFacade),
            new UkrposhtaShipments($modules, static fn(): object => $upFacade),
            new DeliveryCarriers($entityFactory),
        );
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

    public function testNeitherAvailable(): void
    {
        $all = $this->all(false, false, [5 => self::np(5)], [6 => self::up(6)]);

        self::assertFalse($all->available());
        self::assertSame([], $all->active());
        self::assertNull($all->forOrder(5));
        self::assertNull($all->forOrder(6));
    }
}
