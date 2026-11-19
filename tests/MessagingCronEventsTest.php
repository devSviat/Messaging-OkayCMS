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

/** Які SMS крон ставить у чергу для посилок різних перевізників; справжній провайдер не викликається. */
class MessagingCronEventsTest extends TestCase
{
    /** @var array<int, list<string>> типи подій за id замовлення */
    private array $created = [];

    /** @var array<int, list<string>> */
    private array $messages = [];

    /**
     * @param list<CarrierShipment> $shipments
     * @param list<object> $pending
     */
    private function cron(array $shipments, ?SmsProviderInterface $provider = null, array $pending = []): MessagingCronHelper
    {
        $byOrder = [];
        foreach ($shipments as $s) {
            $byOrder[$s->orderId] = $s;
        }
        $carriers = new class ($byOrder) implements ShipmentSource {
            public function __construct(private array $byOrder)
            {
            }

            public function available(): bool
            {
                return true;
            }

            public function active(): array
            {
                return $this->byOrder;
            }

            public function forOrder(int $orderId): ?CarrierShipment
            {
                return $this->byOrder[$orderId] ?? null;
            }
        };

        $orders = $this->createStub(OrdersEntity::class);
        $orders->method('find')->willReturnCallback(static fn(array $filter): array => array_map(
            static fn(int $id): object => (object)['id' => $id, 'phone' => '067 111 22 33', 'delivery_id' => 1, 'total_price' => 500],
            $filter['id']
        ));
        $deliveries = $this->createStub(DeliveriesEntity::class);
        $deliveries->method('find')->willReturn([(object)['id' => 1, 'name' => 'Доставка у відділення']]);
        $log = $this->createStub(NotificationLogEntity::class);
        $log->method('find')->willReturn($pending);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(static fn(string $class) => match ($class) {
            OrdersEntity::class => $orders,
            DeliveriesEntity::class => $deliveries,
            NotificationLogEntity::class => $log,
        });

        $events = $this->createStub(NotificationEventService::class);
        $events->method('isOrderEligibleForSms')->willReturn(true);
        $events->method('isHighValueOrder')->willReturn(false);
        $events->method('buildEventKey')->willReturnCallback(static fn(int $orderId, string $type): string => "order_{$orderId}_{$type}");
        $events->method('createPendingNotification')->willReturnCallback(function (int $orderId, string $phone, string $channel, string $type, string $message): object {
            $this->created[$orderId][] = $type;
            $this->messages[$orderId][] = $message;

            return (object)['id' => count($this->created)];
        });

        if ($provider === null) {
            $provider = $this->createMock(SmsProviderInterface::class);
            $provider->expects(self::never())->method('send');
        }
        $provider->method('getName')->willReturn('stub');
        $factory = $this->createStub(SmsProviderFactory::class);
        $factory->method('getProvider')->willReturn($provider);
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturn(1);

        return new MessagingCronHelper($entityFactory, $events, $factory, $settings, $carriers);
    }

    private static function ago(float $days): string
    {
        return date('Y-m-d H:i:s', (int)(time() - $days * 86400));
    }

    /**
     * Строки безкоштовного зберігання Укрпошти не змодельовані, тож попередження про платне
     * зберігання їй не йде; Нова Пошта в тому самому вікні його отримує (контроль).
     */
    public function testUkrposhtaArrivedCodGetsReminderButNoStorageWarning(): void
    {
        $created = $this->cron([
            new CarrierShipment(7, Carrier::Ukrposhta, '0500000000007', ShipmentEvent::Arrived, self::ago(6.5), true),
            new CarrierShipment(8, Carrier::NovaPoshta, '20450000000008', ShipmentEvent::Arrived, self::ago(6.5), true),
            new CarrierShipment(9, Carrier::Ukrposhta, '0500000000009', ShipmentEvent::Arrived, self::ago(7), true),
        ])->createEvents();

        self::assertSame([NotificationEventService::TYPE_ARRIVED, NotificationEventService::TYPE_REMINDER_2DAY], $this->created[7]);
        self::assertSame([NotificationEventService::TYPE_ARRIVED, NotificationEventService::TYPE_REMINDER_2DAY, NotificationEventService::TYPE_STORAGE_WARNING], $this->created[8]);
        self::assertSame([NotificationEventService::TYPE_ARRIVED, NotificationEventService::TYPE_REMINDER_2DAY], $this->created[9]);
        self::assertSame(7, $created);
        self::assertStringContainsString('Укрпошти', $this->messages[7][0]);
        self::assertStringContainsString('0500000000007', $this->messages[7][0]);
        self::assertStringContainsString('Накладна: 20450000000008', $this->messages[8][0]);
    }

    /** Meest без післяплати й без змодельованих строків зберігання: лише «прибуло», хоч посилка чекає 6,5 дня. */
    public function testMeestArrivedGetsNoReminderOrStorageWarning(): void
    {
        $created = $this->cron([
            new CarrierShipment(7, Carrier::Meest, '711-0000007', ShipmentEvent::Arrived, self::ago(6.5), false),
            new CarrierShipment(8, Carrier::Meest, '711-0000008', ShipmentEvent::Shipped, null, false),
        ])->createEvents();

        self::assertSame([NotificationEventService::TYPE_ARRIVED], $this->created[7]);
        self::assertSame([NotificationEventService::TYPE_SHIPPED], $this->created[8]);
        self::assertSame(2, $created);
        self::assertStringContainsString('пункт видачі Meest', $this->messages[7][0]);
        self::assertStringContainsString('711-0000007', $this->messages[7][0]);
        self::assertStringContainsString('https://t.meest-group.com/711-0000008', $this->messages[8][0]);
    }

    /** ROZETKA з післяплатою отримує нагадування, без неї ні; строки зберігання не змодельовані, тож попередження немає. */
    public function testRozetkaArrivedCodGetsReminderButNoStorageWarning(): void
    {
        $created = $this->cron([
            new CarrierShipment(7, Carrier::Rozetka, '101000000007', ShipmentEvent::Arrived, self::ago(6.5), true),
            new CarrierShipment(8, Carrier::Rozetka, '101000000008', ShipmentEvent::Arrived, self::ago(6.5), false),
            new CarrierShipment(9, Carrier::Rozetka, '101000000009', ShipmentEvent::Shipped, null, true),
        ])->createEvents();

        self::assertSame([NotificationEventService::TYPE_ARRIVED, NotificationEventService::TYPE_REMINDER_2DAY], $this->created[7]);
        self::assertSame([NotificationEventService::TYPE_ARRIVED], $this->created[8]);
        self::assertSame([NotificationEventService::TYPE_SHIPPED], $this->created[9]);
        self::assertSame(4, $created);
        self::assertStringContainsString('точку видачі ROZETKA Delivery', $this->messages[7][0]);
        self::assertStringContainsString('101000000007', $this->messages[7][1]);
        self::assertStringContainsString('https://rozetka.delivery/tracking/parcel', $this->messages[9][0]);
        self::assertStringContainsString('101000000009', $this->messages[9][0]);
    }

    public function testShippedUsesCarrierText(): void
    {
        $this->cron([new CarrierShipment(7, Carrier::Ukrposhta, '0500000000007', ShipmentEvent::Shipped, null, false)])->createEvents();

        self::assertSame([NotificationEventService::TYPE_SHIPPED], $this->created[7]);
        self::assertStringContainsString('https://track.ukrposhta.ua/tracking_UA.html?barcode=0500000000007', $this->messages[7][0]);
    }

    public function testReturningUkrposhtaSendsNothing(): void
    {
        $created = $this->cron([
            new CarrierShipment(7, Carrier::Ukrposhta, '0500000000007', ShipmentEvent::Returned, self::ago(3), true),
            new CarrierShipment(8, Carrier::Ukrposhta, '0500000000008', ShipmentEvent::Received, self::ago(3), true),
            new CarrierShipment(9, Carrier::Ukrposhta, '0500000000009', ShipmentEvent::Closed, null, false),
            new CarrierShipment(10, Carrier::Ukrposhta, '0500000000010', null, null, false),
        ])->createEvents();

        self::assertSame(0, $created);
        self::assertSame([], $this->created);
    }

    public function testProcessEventsAndSendKeepsStats(): void
    {
        $provider = $this->createMock(SmsProviderInterface::class);
        $provider->expects(self::exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            ['success' => true, 'provider_message_id' => 'x'],
            ['success' => false, 'error' => 'stub'],
        );
        $pending = [
            (object)['id' => 1, 'phone' => '+380671112233', 'message' => 'a', 'attempts' => 0],
            (object)['id' => 2, 'phone' => '+380671112233', 'message' => 'b', 'attempts' => 0],
        ];
        $stats = $this->cron([new CarrierShipment(7, Carrier::NovaPoshta, '20450000000007', ShipmentEvent::Shipped, null, false)], $provider, $pending)->processEventsAndSend();

        self::assertSame(['events_created' => 1, 'sent' => 1, 'errors' => 1, 'skipped' => 0], $stats);
    }
}
