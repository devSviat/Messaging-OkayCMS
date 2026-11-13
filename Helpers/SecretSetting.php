<?php

namespace Okay\Modules\Sviat\Messaging\Helpers;

/**
 * Значення секретного налаштування після збереження форми.
 *
 * Токен у форму не повертається, бо сторінку відкривають і менеджери без повного
 * доступу. Тож порожнє поле означає «лишити збережений», а стерти можна лише
 * окремою галочкою.
 */
final class SecretSetting
{
    public static function next(?string $stored, mixed $posted, mixed $clear): string
    {
        if (!empty($clear)) {
            return '';
        }

        $posted = is_string($posted) ? trim($posted) : '';

        return $posted !== '' ? $posted : (string) $stored;
    }
}
