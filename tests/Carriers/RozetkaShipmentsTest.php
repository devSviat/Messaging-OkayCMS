<?php

namespace Modules\Sviat\Messaging\Carriers;

use Okay\Core\Modules\Modules;
use Okay\Modules\Sviat\Messaging\Carriers\Carrier;
use Okay\Modules\Sviat\Messaging\Carriers\CarrierShipment;
use Okay\Modules\Sviat\Messaging\Carriers\RozetkaShipments;
use Okay\Modules\Sviat\Messaging\Carriers\ShipmentEvent;
use Okay\Modules\Sviat\RozetkaDelivery\Integration\RozetkaOrders;
use Okay\Modules\Sviat\RozetkaDelivery\Parcels\ParcelStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!class_exists(RozetkaOrders::class)) {
    require_once __DIR__ . '/../RozetkaOrdersStub.php';
}

/**
 * SMS про ЕН ROZETKA Delivery будуються з груп статусів її модуля. Помилка тут — повідомлення
 * «прибуло» про посилку, від якої одержувач відмовився, або тиша про посилку в точці видачі.
 */
class RozetkaShipmentsTest extends TestCase
{
    private const array EXPECTED = [
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

    private static function parcel(int $orderId, ?string $group, ?string $arrivedAt = null, bool $cod = false): object
    {
        return (object)['orderId' => $orderId, 'trackId' => '1010000000' . $orderId, 'group' => $group, 'cod' => $cod, 'eventAt' => $arrivedAt, 'arrivedAt' => $arrivedAt];
    }

    /** @param list<object> $parcels */
    private function source(bool $active, array $parcels): RozetkaShipments
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

        return new RozetkaShipments($this->modules($active), static fn(): object => $facade);
    }

    private function modules(bool $rozetka): Modules
    {
        $modules = $this->createStub(Modules::class);
        $modules->method('isActiveModule')->willReturnCallback(static fn(string $vendor, string $name): bool => $vendor === 'Sviat' && $name === 'RozetkaDelivery' && $rozetka);

        return $modules;
    }

    #[DataProvider('groupEvents')]
    public function testGroupsMapToEvents(string $group, ?ShipmentEvent $event): void
    {
        $active = $this->source(true, [self::parcel(7, $group)])->active();

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

    /**
     * «Проблема» буває й до відправлення: точка не прийняла посилку за реєстром, і вона лишилася в магазині.
     * «Відправлено» тоді було б неправдою; SMS прийде, коли ЕН перейде в accepted чи in_transit.
     */
    public function testProblemBeforeShipmentSendsNothing(): void
    {
        self::assertNull($this->source(true, [self::parcel(7, 'problem')])->active()[7]->event);
    }

    /** Нова група в модулі ROZETKA без рядка в мапінгу мовчки глушила б SMS. */
    public function testEveryRozetkaGroupIsCovered(): void
    {
        if (!class_exists(ParcelStatus::class)) {
            self::markTestSkipped('модуля RozetkaDelivery у цьому прогоні немає');
        }
        $expected = array_keys(self::EXPECTED);
        sort($expected);
        $groups = ParcelStatus::GROUPS;
        sort($groups);

        self::assertSame($groups, $expected);
    }

    public function testCarrierIsRozetka(): void
    {
        self::assertSame(Carrier::Rozetka, $this->source(true, [])->carrier());
    }

    /** Післяплата з фасаду: від неї залежить нагадування «чекає 2 дні». */
    public function testCarriesTrackArrivalAndCod(): void
    {
        $source = $this->source(true, [self::parcel(7, 'arrived', '2026-11-10 12:00:00', true), self::parcel(8, 'arrived', '2026-11-10 12:00:00')]);

        self::assertEquals(new CarrierShipment(7, Carrier::Rozetka, '10100000007', ShipmentEvent::Arrived, '2026-11-10 12:00:00', true), $source->forOrder(7));
        self::assertFalse($source->forOrder(8)->cod);
        self::assertTrue($source->active()[7]->cod);
        self::assertNull($this->source(true, [])->forOrder(7));
    }

    public function testUnknownGroupRaisesNoEvent(): void
    {
        self::assertNull($this->source(true, [self::parcel(7, null)])->active()[7]->event);
        self::assertNull($this->source(true, [self::parcel(7, 'brand_new')])->active()[7]->event);
    }

    /** Модуль установлений, але вимкнений: фасад не будується, посилок немає. */
    public function testRozetkaDisabledLeavesOthers(): void
    {
        $source = new RozetkaShipments($this->modules(false), static fn(): object => throw new \LogicException('фасад вимкненого модуля не будується'));

        self::assertFalse($source->available());
        self::assertSame([], $source->active());
        self::assertNull($source->forOrder(7));
    }

    /** Чи бере сторінка трекінгу ROZETKA номер з адреси, невідомо, тож номер іде в тексті SMS окремо. */
    public function testTrackingUrlWithoutNumber(): void
    {
        self::assertSame('https://rozetka.delivery/tracking/parcel', Carrier::Rozetka->trackingUrl('101000000007'));
        self::assertNull(Carrier::Rozetka->freeStorageDays());
    }
}
