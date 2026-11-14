<?php

namespace Modules\Sviat\Messaging;

use Okay\Modules\Sviat\Messaging\Carriers\ShipmentEvent;
use Okay\Modules\Sviat\Messaging\Helpers\DeliveryStatusMapping;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * З цієї таблиці народжуються SMS покупцеві. Помилка в один код — або зайве
 * повідомлення «посилка в дорозі» на вже отриману, або тиша там, де клієнт
 * чекає сповіщення.
 */
class DeliveryStatusMappingTest extends TestCase
{
    /** @dataProvider inTransitProvider */
    #[DataProvider('inTransitProvider')]
    public function testInTransitCodesRaiseTheShippedEvent(string $code): void
    {
        $this->assertSame(
            [DeliveryStatusMapping::SYSTEM_IN_TRANSIT, DeliveryStatusMapping::EVENT_SHIPPED],
            DeliveryStatusMapping::fromNovaPoshta($code)
        );
        $this->assertTrue(DeliveryStatusMapping::isNovaPoshtaInTransit($code));
        $this->assertFalse(DeliveryStatusMapping::isNovaPoshtaArrived($code));
        $this->assertFalse(DeliveryStatusMapping::isNovaPoshtaReceived($code));
    }

    public static function inTransitProvider(): array
    {
        return [['4'], ['41'], ['5'], ['6'], ['101']];
    }

    /** @dataProvider arrivedProvider */
    #[DataProvider('arrivedProvider')]
    public function testArrivedCodesRaiseTheArrivedEvent(string $code): void
    {
        $this->assertSame(
            [DeliveryStatusMapping::SYSTEM_ARRIVED, DeliveryStatusMapping::EVENT_ARRIVED],
            DeliveryStatusMapping::fromNovaPoshta($code)
        );
        $this->assertTrue(DeliveryStatusMapping::isNovaPoshtaArrived($code));
        $this->assertFalse(DeliveryStatusMapping::isNovaPoshtaInTransit($code));
    }

    public static function arrivedProvider(): array
    {
        return [['7'], ['8']];
    }

    /** @dataProvider receivedProvider */
    #[DataProvider('receivedProvider')]
    public function testReceivedCodesAreFinal(string $code): void
    {
        $this->assertSame(
            [DeliveryStatusMapping::SYSTEM_RECEIVED, DeliveryStatusMapping::EVENT_RECEIVED],
            DeliveryStatusMapping::fromNovaPoshta($code)
        );
        $this->assertTrue(DeliveryStatusMapping::isNovaPoshtaReceived($code));
        $this->assertTrue(
            DeliveryStatusMapping::isNovaPoshtaFinalStatus($code),
            'отримана посилка більше не має породжувати подій'
        );
    }

    public static function receivedProvider(): array
    {
        return [['9'], ['10'], ['11']];
    }

    /** Відмова, повернення й видалення теж закривають відправлення. */
    /** @dataProvider otherFinalProvider */
    #[DataProvider('otherFinalProvider')]
    public function testRefusalsAndReturnsAreFinalButRaiseNoEvent(string $code): void
    {
        $this->assertTrue(DeliveryStatusMapping::isNovaPoshtaFinalStatus($code));
        $this->assertNull(
            DeliveryStatusMapping::fromNovaPoshta($code),
            'для цього коду SMS не передбачена'
        );
    }

    public static function otherFinalProvider(): array
    {
        return [['2'], ['3'], ['102'], ['103'], ['105'], ['106']];
    }

    public function testUnknownCodeMapsToNothingAndIsNotFinal(): void
    {
        $this->assertNull(DeliveryStatusMapping::fromNovaPoshta('999'));
        $this->assertFalse(DeliveryStatusMapping::isNovaPoshtaFinalStatus('999'));
        $this->assertFalse(DeliveryStatusMapping::isNovaPoshtaInTransit('999'));
    }

    /**
     * Коди приходять з API рядками, і саме рядками лежать у таблиці. Якщо
     * десь дорогою станеться приведення до int, зіставлення має вижити.
     */
    public function testCodesAreComparedAsStrings(): void
    {
        $this->assertSame(
            DeliveryStatusMapping::fromNovaPoshta('7'),
            DeliveryStatusMapping::fromNovaPoshta((string) 7)
        );
    }

    /** Крон читає подію замість предикатів: розбіжність хоч в одному коді змінила б SMS для Нової Пошти. */
    public function testEventAgreesWithLegacyPredicates(): void
    {
        for ($i = 0; $i <= 200; $i++) {
            $code = (string)$i;
            $event = DeliveryStatusMapping::event($code);
            self::assertSame(DeliveryStatusMapping::isNovaPoshtaInTransit($code), $event === ShipmentEvent::Shipped, "shipped {$code}");
            self::assertSame(DeliveryStatusMapping::isNovaPoshtaArrived($code), $event === ShipmentEvent::Arrived, "arrived {$code}");
            self::assertSame(DeliveryStatusMapping::isNovaPoshtaReceived($code), $event === ShipmentEvent::Received, "received {$code}");
            self::assertSame(DeliveryStatusMapping::isNovaPoshtaFinalStatus($code), $event?->isFinal() ?? false, "final {$code}");
        }
        self::assertSame(ShipmentEvent::Returned, DeliveryStatusMapping::event('102'));
        self::assertSame(ShipmentEvent::Closed, DeliveryStatusMapping::event('2'));
        self::assertNull(DeliveryStatusMapping::event(''));
    }
}
