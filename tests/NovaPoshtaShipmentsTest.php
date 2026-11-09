<?php

namespace Modules\Sviat\Messaging;

use Okay\Core\EntityFactory;
use Okay\Core\Modules\Modules;
use Okay\Modules\OkayCMS\NovaposhtaCost\Entities\NPCostDeliveryDataEntity;
use Okay\Modules\Sviat\Messaging\Helpers\NovaPoshtaShipments;
use Okay\Modules\Sviat\Messaging\Services\NotificationEventService;
use Okay\Modules\Sviat\NovaPoshta\Integration\NpOrders;
use Okay\Modules\Sviat\NovaPoshtaTracking\Entities\NovaPoshtaTrackingEntity;
use PHPUnit\Framework\TestCase;

// Сусідні модулі необов'язкові: без них класів немає, а перевірки доступності питають саме про клас.
if (!class_exists(NpOrders::class)) {
    require_once __DIR__ . '/NpOrdersStub.php';
}
if (!class_exists(NovaPoshtaTrackingEntity::class)) {
    require_once __DIR__ . '/NovaPoshtaTrackingEntityStub.php';
}

/**
 * SMS про посилку будуються з накладних обох модулів Нової Пошти. Помилка тут — або тиша там, де
 * покупець чекає нагадування, або нагадування «заберіть, післяплата» без післяплати.
 */
class NovaPoshtaShipmentsTest extends TestCase
{
    private static function shipment(int $orderId, ?int $code, ?string $arrivedAt, bool $cod): object
    {
        return (object)['orderId' => $orderId, 'number' => '2045000000' . $orderId, 'statusCode' => $code, 'statusAt' => $arrivedAt, 'arrivedAt' => $arrivedAt, 'cod' => $cod];
    }

    /**
     * @param list<object> $newShipments
     * @param list<object> $oldRows
     * @param list<object> $costRows
     */
    private function source(bool $new, bool $old, array $newShipments = [], array $oldRows = [], array $costRows = []): NovaPoshtaShipments
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(static fn(string $vendor, string $name): bool => ($name === 'NovaPoshta' && $new) || ($name === 'NovaPoshtaTracking' && $old));

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
        $cost->method('find')->willReturn($costRows);
        $cost->method('findOne')->willReturnCallback(static function (array $filter) use ($costRows) {
            foreach ($costRows as $row) {
                if ((int)$row->order_id === (int)$filter['order_id']) {
                    return $row;
                }
            }

            return false;
        });
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(static fn(string $class) => $class === NPCostDeliveryDataEntity::class ? $cost : $tracking);

        $byOrder = [];
        foreach ($newShipments as $s) {
            $byOrder[$s->orderId] = $s;
        }
        $facade = new class ($byOrder) {
            public function __construct(private array $byOrder)
            {
            }

            public function unfinished(): array
            {
                return array_filter($this->byOrder, static fn(object $s): bool => !in_array($s->statusCode, [2, 9, 11, 102, 103, 106], true));
            }

            public function shipments(array $orderIds): array
            {
                return array_intersect_key($this->byOrder, array_flip($orderIds));
            }
        };

        return new NovaPoshtaShipments($modules, $entityFactory, static fn(): object => $facade);
    }

    public function testNewModuleShipmentTakesTheShapeTheCronReads(): void
    {
        $active = $this->source(true, false, [self::shipment(101, 7, '2026-11-05 10:00:00', true)])->active();

        self::assertSame([101], array_keys($active));
        self::assertSame('2045000000101', $active[101]->int_doc_number);
        self::assertSame('7', $active[101]->status_code);
        self::assertSame('2026-11-05 10:00:00', $active[101]->actual_delivery_at);
        self::assertTrue($active[101]->cod);
    }

    public function testNewModuleWithoutStatusYet(): void
    {
        $active = $this->source(true, false, [self::shipment(101, null, null, false)])->active();

        self::assertSame('', $active[101]->status_code);
        self::assertNull($active[101]->actual_delivery_at);
    }

    public function testOldModuleCodIsPaymentControl(): void
    {
        $old = [(object)['order_id' => 201, 'int_doc_number' => '20450000000201', 'status_code' => '7', 'actual_delivery_at' => '2026-11-04 09:00:00']];
        $active = $this->source(false, true, [], $old, [(object)['order_id' => 201, 'control_payment' => 1]])->active();

        self::assertSame('20450000000201', $active[201]->int_doc_number);
        self::assertSame('2026-11-04 09:00:00', $active[201]->actual_delivery_at);
        self::assertTrue($active[201]->cod);
        self::assertFalse($this->source(false, true, [], $old, [(object)['order_id' => 201, 'control_payment' => 0]])->active()[201]->cod);
    }

    public function testOldRowsWithoutNumberAreSkipped(): void
    {
        $old = [(object)['order_id' => 201, 'int_doc_number' => '', 'status_code' => '1', 'actual_delivery_at' => null]];

        self::assertSame([], $this->source(false, true, [], $old)->active());
    }

    public function testBothModulesNewWins(): void
    {
        $old = [
            (object)['order_id' => 101, 'int_doc_number' => '20450000000999', 'status_code' => '4', 'actual_delivery_at' => null],
            (object)['order_id' => 202, 'int_doc_number' => '20450000000202', 'status_code' => '4', 'actual_delivery_at' => null],
        ];
        $active = $this->source(true, true, [self::shipment(101, 7, '2026-11-05 10:00:00', false)], $old)->active();

        $keys = array_keys($active);
        sort($keys);
        self::assertSame([101, 202], $keys);
        self::assertSame('2045000000101', $active[101]->int_doc_number);
    }

    /** Накладна вже отримана в новому модулі, а старий трекінг відстав: нагадувати нема про що. */
    public function testFinalNewWaybillHidesStaleOldRow(): void
    {
        $old = [(object)['order_id' => 101, 'int_doc_number' => '20450000000101', 'status_code' => '7', 'actual_delivery_at' => '2026-11-01 10:00:00']];
        $active = $this->source(true, true, [self::shipment(101, 9, null, true)], $old)->active();

        self::assertArrayNotHasKey(101, $active);
    }

    public function testDisabledOldModuleIsNotRead(): void
    {
        $old = [(object)['order_id' => 201, 'int_doc_number' => '20450000000201', 'status_code' => '4', 'actual_delivery_at' => null]];
        $source = $this->source(false, false, [], $old);

        self::assertFalse($source->available());
        self::assertSame([], $source->active());
        self::assertNull($source->forOrder(201));
    }

    public function testForOrderPrefersNewModule(): void
    {
        $old = [(object)['order_id' => 101, 'int_doc_number' => '20450000000999', 'status_code' => '4', 'actual_delivery_at' => null]];
        $source = $this->source(true, true, [self::shipment(101, 8, '2026-11-05 10:00:00', true)], $old);

        self::assertSame('2045000000101', $source->forOrder(101)->int_doc_number);
        self::assertSame('20450000000999', $this->source(false, true, [], $old)->forOrder(101)->int_doc_number);
    }

    public function testMoneyTransferCountsAsCashOnDelivery(): void
    {
        // Рішення автора: нагадування «заберіть, післяплата» — для будь-якої післяплати НП.
        $source = $this->source(true, false, [self::shipment(101, 7, '2026-11-05 10:00:00', true)]);
        $events = new NotificationEventService($this->createStub(EntityFactory::class), $this->createStub(\Okay\Core\Settings::class), $source);

        self::assertTrue($events->isCashOnDelivery((object)['id' => 101]));
        self::assertFalse($events->isCashOnDelivery((object)['id' => 102]));
    }
}
