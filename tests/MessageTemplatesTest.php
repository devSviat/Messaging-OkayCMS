<?php

namespace Modules\Sviat\Messaging;

use Okay\Modules\Sviat\Messaging\Carriers\Carrier;
use Okay\Modules\Sviat\Messaging\Helpers\MessageTemplates;
use Okay\Modules\Sviat\Messaging\Services\NotificationEventService;
use PHPUnit\Framework\TestCase;

/** Покупці вже отримують ці тексти; зміна в одному символі — інша SMS і інша ціна за неї. */
class MessageTemplatesTest extends TestCase
{
    private const array PARAMS = ['doc_number' => '20450000000123', 'order_id' => 123];

    public function testNpTextsUnchanged(): void
    {
        self::assertSame(
            "Ваше замовлення №123 відправлено.\nНакладна: 20450000000123. Відстежити: https://novaposhta.ua/tracking/20450000000123",
            MessageTemplates::getMessage(NotificationEventService::TYPE_SHIPPED, self::PARAMS)
        );
        self::assertSame(
            "Ваше замовлення №123 прибуло у відділення.\nНакладна: 20450000000123.",
            MessageTemplates::getMessage(NotificationEventService::TYPE_ARRIVED, self::PARAMS, Carrier::NovaPoshta)
        );
        self::assertSame(
            "Ваше замовлення №123 чекає у відділенні вже 2 дні.\nНакладна: 20450000000123.",
            MessageTemplates::getMessage(NotificationEventService::TYPE_REMINDER_2DAY, self::PARAMS)
        );
        self::assertSame(
            "Ваше замовлення №123: завтра почнеться платне зберігання. Заберіть будь ласка сьогодні.\nНакладна: 20450000000123.",
            MessageTemplates::getMessage(NotificationEventService::TYPE_STORAGE_WARNING, self::PARAMS)
        );
        self::assertSame(
            "Ваше замовлення №— відправлено.\nНакладна: . Відстежити: https://novaposhta.ua/tracking/",
            MessageTemplates::getMessage(NotificationEventService::TYPE_SHIPPED)
        );
    }

    public function testUkrposhtaTexts(): void
    {
        $params = ['doc_number' => '0500000000123', 'order_id' => 123];

        self::assertSame(
            "Ваше замовлення №123 відправлено Укрпоштою.\nНомер відправлення: 0500000000123. Відстежити: https://track.ukrposhta.ua/tracking_UA.html?barcode=0500000000123",
            MessageTemplates::getMessage(NotificationEventService::TYPE_SHIPPED, $params, Carrier::Ukrposhta)
        );
        self::assertSame(
            "Ваше замовлення №123 прибуло у відділення Укрпошти.\nНомер відправлення: 0500000000123.",
            MessageTemplates::getMessage(NotificationEventService::TYPE_ARRIVED, $params, Carrier::Ukrposhta)
        );
        self::assertSame(
            "Ваше замовлення №123 чекає у відділенні Укрпошти вже 2 дні.\nНомер відправлення: 0500000000123.",
            MessageTemplates::getMessage(NotificationEventService::TYPE_REMINDER_2DAY, $params, Carrier::Ukrposhta)
        );
    }

    public function testManualMessagePassesThrough(): void
    {
        self::assertSame('Привіт', MessageTemplates::getMessage(NotificationEventService::TYPE_MANUAL, ['message' => 'Привіт'], Carrier::Ukrposhta));
        self::assertSame('Повідомлення від магазину.', MessageTemplates::getMessage(NotificationEventService::TYPE_MANUAL));
    }

    /** Адмінка показує ці приклади; Укрпошта без попередження про зберігання. */
    public function testAdminExamples(): void
    {
        $np = MessageTemplates::examples(Carrier::NovaPoshta);
        $up = MessageTemplates::examples(Carrier::Ukrposhta);

        self::assertSame([NotificationEventService::TYPE_SHIPPED, NotificationEventService::TYPE_ARRIVED, NotificationEventService::TYPE_REMINDER_2DAY, NotificationEventService::TYPE_STORAGE_WARNING], array_keys($np));
        self::assertSame([NotificationEventService::TYPE_SHIPPED, NotificationEventService::TYPE_ARRIVED, NotificationEventService::TYPE_REMINDER_2DAY], array_keys($up));
        self::assertSame(MessageTemplates::getMessage(NotificationEventService::TYPE_SHIPPED, ['doc_number' => '20451329330540', 'order_id' => 12345]), $np[NotificationEventService::TYPE_SHIPPED]);
        self::assertStringContainsString('Укрпошт', $up[NotificationEventService::TYPE_ARRIVED]);
    }
}
