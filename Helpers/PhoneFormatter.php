<?php

namespace Okay\Modules\Sviat\Messaging\Helpers;

/** Формат і валідація номера для SMS (UA, E.164). */
class PhoneFormatter
{
    private const MIN_DIGITS = 9;

    public static function format(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }
        if (strlen(preg_replace('/\D/', '', $phone)) < self::MIN_DIGITS) {
            return null;
        }
        try {
            $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
            $parsed = $phoneUtil->parse($phone, 'UA');
            if (!$phoneUtil->isValidNumber($parsed)) {
                return null;
            }
            return $phoneUtil->format($parsed, \libphonenumber\PhoneNumberFormat::E164);
        } catch (\Exception $e) {
            return null;
        }
    }
}
