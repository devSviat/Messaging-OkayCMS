<?php

namespace Modules\Sviat\Messaging;

use Okay\Modules\Sviat\Messaging\Helpers\PhoneFormatter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Номер із цього форматера йде в SMS-шлюз, тож помилка тут не видно ніде,
 * крім недоставленого повідомлення.
 *
 * Окремий привід тримати тест: міграція підняла libphonenumber з 8 на 9, де
 * PhoneNumberFormat перетворився з набору int-констант на enum.
 */
class PhoneFormatterTest extends TestCase
{
    /** @dataProvider ukrainianNumberProvider */
    #[DataProvider('ukrainianNumberProvider')]
    public function testUkrainianNumbersBecomeE164(string $input): void
    {
        $this->assertSame('+380671112233', PhoneFormatter::format($input));
    }

    public static function ukrainianNumberProvider(): array
    {
        return [
            'без коду країни'   => ['0671112233'],
            'уже E.164'         => ['+380671112233'],
            'з пробілами'       => ['38 067 111 22 33'],
            'з дефісами'        => ['067-111-22-33'],
            'у дужках'          => ['(067) 111-22-33'],
            'з пробілами довкола' => ['  0671112233  '],
        ];
    }

    /** @dataProvider rejectedProvider */
    #[DataProvider('rejectedProvider')]
    public function testUnusableInputGivesNull(string $input): void
    {
        $this->assertNull(PhoneFormatter::format($input));
    }

    public static function rejectedProvider(): array
    {
        return [
            'порожній рядок'      => [''],
            'самі пробіли'        => ['   '],
            'закоротко'           => ['12345'],
            'літери'              => ['abc'],
            'неіснуючий оператор' => ['+380001112233'],
        ];
    }

    /**
     * Формат результату — саме E.164: без пробілів, з плюсом і кодом країни.
     * Шлюз інших не приймає.
     */
    public function testResultIsAlwaysDigitsWithLeadingPlus(): void
    {
        $formatted = PhoneFormatter::format('067 111 22 33');

        $this->assertMatchesRegularExpression('~^\+\d{10,15}$~', (string) $formatted);
    }
}
