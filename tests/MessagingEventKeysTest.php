<?php

namespace Modules\Sviat\Messaging;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Messaging\Carriers\Carrier;
use Okay\Modules\Sviat\Messaging\Carriers\CarrierShipment;
use Okay\Modules\Sviat\Messaging\Carriers\ShipmentSource;
use Okay\Modules\Sviat\Messaging\Carriers\ShipmentEvent;
use Okay\Modules\Sviat\Messaging\Entities\NotificationLogEntity;
use Okay\Modules\Sviat\Messaging\Helpers\MessagingCronHelper;
use Okay\Modules\Sviat\Messaging\Providers\SmsProviderFactory;
use Okay\Modules\Sviat\Messaging\Providers\SmsProviderInterface;
use Okay\Modules\Sviat\Messaging\Services\NotificationEventService;
use PHPUnit\Framework\TestCase;

/**
 * Ключ події захищає від повторної SMS. Ключі Нової Пошти лишаються як були, інакше після оновлення
 * пішли б повтори для всіх посилок у дорозі; після зміни перевізника нова посилка має отримати свою SMS.
 */
class MessagingEventKeysTest extends TestCase
{
    /** @var array<int, array<string, mixed>> журнал SMS у пам'яті */
    private array $log = [];

    private EntityFactory $entityFactory;

    private static function np(): CarrierShipment
    {
        return new CarrierShipment(7, Carrier::NovaPoshta, '20450000000007', ShipmentEvent::Shipped, null, false);
    }

    private static function up(string $barcode = '0500000000007'): CarrierShipment
    {
        return new CarrierShipment(7, Carrier::Ukrposhta, $barcode, ShipmentEvent::Shipped, null, false);
    }

    private function sent(string $eventKey): void
    {
        $at = date('Y-m-d H:i:s', time() - 2 * 86400);
        $this->log[count($this->log) + 1] = ['id' => count($this->log) + 1, 'order_id' => 7, 'phone' => '+380671112233', 'channel' => 'sms',
            'type' => 'shipped', 'status' => 'sent', 'event_key' => $eventKey, 'created_at' => $at, 'sent_at' => $at];
    }

    private function rowMatches(array $row, array $filter): bool
    {
        foreach (array_diff_key($filter, ['limit' => 1, 'order' => 1]) as $field => $value) {
            if (($row[$field] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function events(CarrierShipment $shipment): NotificationEventService
    {
        $log = $this->createStub(NotificationLogEntity::class);
        $log->method('findOne')->willReturnCallback(function (array $filter) {
            foreach ($this->log as $row) {
                if ($this->rowMatches($row, $filter)) {
                    return (object)$row;
                }
            }

            return false;
        });
        $log->method('find')->willReturnCallback(fn(array $filter): array => array_values(array_map(
            static fn(array $row): object => (object)$row,
            array_filter($this->log, fn(array $row): bool => $this->rowMatches($row, $filter))
        )));
        $log->method('add')->willReturnCallback(function (array $row): int {
            $id = count($this->log) + 1;
            $this->log[$id] = ['id' => $id] + $row;

            return $id;
        });
        $log->method('get')->willReturnCallback(fn($id) => isset($this->log[(int)$id]) ? (object)$this->log[(int)$id] : false);

        $orders = $this->createStub(OrdersEntity::class);
        $orders->method('find')->willReturn([(object)['id' => 7, 'phone' => '067 111 22 33', 'delivery_id' => 1, 'total_price' => 500]]);
        $deliveries = $this->createStub(DeliveriesEntity::class);
        $deliveries->method('find')->willReturn([(object)['id' => 1, 'name' => 'Доставка у відділення']]);
        $this->entityFactory = $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(static fn(string $class) => match ($class) {
            OrdersEntity::class => $orders,
            DeliveriesEntity::class => $deliveries,
            NotificationLogEntity::class => $log,
        });
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturn(1);

        $carriers = $this->createStub(ShipmentSource::class);
        $carriers->method('active')->willReturn([7 => $shipment]);
        $carriers->method('forOrder')->willReturn($shipment);

        return new NotificationEventService($entityFactory, $settings, $carriers);
    }

    private function createEvents(CarrierShipment $shipment): int
    {
        $events = $this->events($shipment);
        $provider = $this->createMock(SmsProviderInterface::class);
        $provider->expects(self::never())->method('send');
        $provider->method('getName')->willReturn('stub');
        $factory = $this->createStub(SmsProviderFactory::class);
        $factory->method('getProvider')->willReturn($provider);
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturn(1);
        $carriers = $this->createStub(ShipmentSource::class);
        $carriers->method('active')->willReturn([7 => $shipment]);

        return new MessagingCronHelper($this->entityFactory, $events, $factory, $settings, $carriers)->createEvents();
    }

    /** @return list<string> */
    private function keys(): array
    {
        return array_values(array_map(static fn(array $row): string => $row['event_key'], $this->log));
    }

    public function testNpKeysUnchanged(): void
    {
        $events = $this->events(self::np());

        self::assertSame('order_7_shipped', $events->buildEventKey(7, NotificationEventService::TYPE_SHIPPED));
        self::assertSame('order_7_shipped', $events->buildEventKey(7, NotificationEventService::TYPE_SHIPPED, self::np()));
        self::assertSame('order_7_arrived', $events->buildEventKey(7, NotificationEventService::TYPE_ARRIVED, self::np()));
        self::assertSame('order_7_shipped_ukrposhta_0500000000007', $events->buildEventKey(7, NotificationEventService::TYPE_SHIPPED, self::up()));
    }

    public function testSwitchFromNpToUkrposhtaSendsUkrposhtaShipped(): void
    {
        $this->sent('order_7_shipped');

        self::assertSame(1, $this->createEvents(self::up()));
        self::assertSame(['order_7_shipped', 'order_7_shipped_ukrposhta_0500000000007'], $this->keys());
    }

    public function testSwitchFromUkrposhtaToNpSendsNpShipped(): void
    {
        $this->sent('order_7_shipped_ukrposhta_0500000000007');

        self::assertSame(1, $this->createEvents(self::np()));
        self::assertSame(['order_7_shipped_ukrposhta_0500000000007', 'order_7_shipped'], $this->keys());
    }

    public function testSameShipmentIsNotRepeated(): void
    {
        $this->sent('order_7_shipped');
        $this->sent('order_7_shipped_ukrposhta_0500000000007');

        self::assertSame(0, $this->createEvents(self::np()));
        self::assertSame(0, $this->createEvents(self::up()));
        self::assertSame(1, $this->createEvents(self::up('0500000000099')), 'нове відправлення Укрпошти має інший номер');
    }
}
