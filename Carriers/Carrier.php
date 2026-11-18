<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

enum Carrier: string
{
    case NovaPoshta = 'novaposhta';
    case Ukrposhta = 'ukrposhta';
    case Meest = 'meest';

    public function trackingUrl(string $number): string
    {
        return match ($this) {
            self::NovaPoshta => 'https://novaposhta.ua/tracking/' . $number,
            self::Ukrposhta => 'https://track.ukrposhta.ua/tracking_UA.html?barcode=' . $number,
            self::Meest => 'https://t.meest-group.com/' . $number,
        };
    }

    /** Днів безкоштовного зберігання у відділенні; null, якщо строки не змодельовані: тоді попередження не надсилається. */
    public function freeStorageDays(): ?int
    {
        return match ($this) {
            self::NovaPoshta => 7,
            self::Ukrposhta, self::Meest => null,
        };
    }
}
