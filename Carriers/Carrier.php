<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

enum Carrier: string
{
    case NovaPoshta = 'novaposhta';
    case Ukrposhta = 'ukrposhta';
    case Meest = 'meest';
    case Rozetka = 'rozetka';

    /** Чи бере сторінка трекінгу ROZETKA Delivery номер з адреси, невідомо, тож посилання веде на форму без номера. */
    public function trackingUrl(string $number): string
    {
        return match ($this) {
            self::NovaPoshta => 'https://novaposhta.ua/tracking/' . $number,
            self::Ukrposhta => 'https://track.ukrposhta.ua/tracking_UA.html?barcode=' . $number,
            self::Meest => 'https://t.meest-group.com/' . $number,
            self::Rozetka => 'https://rozetka.delivery/tracking/parcel',
        };
    }

    /**
     * Днів безкоштовного зберігання у відділенні без дня прибуття; null, якщо строки не змодельовані: тоді попередження не надсилається.
     * Нова Пошта: 5 днів з 01.10.2026 (novaposhta.ua/for-business/additional-services/parcel-storage).
     */
    public function freeStorageDays(): ?int
    {
        return match ($this) {
            self::NovaPoshta => 5,
            self::Ukrposhta, self::Meest, self::Rozetka => null,
        };
    }
}
