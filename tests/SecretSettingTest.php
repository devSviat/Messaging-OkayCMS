<?php

namespace Modules\Sviat\Messaging;

use Okay\Modules\Sviat\Messaging\Helpers\SecretSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Сторінку модуля відкривають менеджери без повного доступу, тож токен провайдера
 * в HTML не повертається. Через це порожнє поле форми означає «лишити як є», а
 * стерти токен можна лише окремою галочкою.
 */
class SecretSettingTest extends TestCase
{
    /** @return iterable<string, array{?string, mixed, mixed, string}> */
    public static function cases(): iterable
    {
        yield 'empty field keeps the stored token' => ['stored-aaaa', '', null, 'stored-aaaa'];
        yield 'missing field keeps the stored token' => ['stored-aaaa', null, null, 'stored-aaaa'];
        yield 'whitespace keeps the stored token' => ['stored-aaaa', '   ', null, 'stored-aaaa'];
        yield 'new value replaces it' => ['stored-aaaa', ' new-bbbb ', null, 'new-bbbb'];
        yield 'clear wipes it' => ['stored-aaaa', '', '1', ''];
        yield 'clear wins over a typed value' => ['stored-aaaa', 'new-bbbb', '1', ''];
        yield 'nothing stored yet' => [null, '', null, ''];
        yield 'array is not a token' => ['stored-aaaa', ['x'], null, 'stored-aaaa'];
    }

    #[DataProvider('cases')]
    public function testNext(?string $stored, mixed $posted, mixed $clear, string $expected): void
    {
        $this->assertSame($expected, SecretSetting::next($stored, $posted, $clear));
    }

    public function testTheTemplateNeverPrintsATokenValue(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Sviat/Messaging/Backend/design/html/messaging_admin.tpl');

        $this->assertStringNotContainsString('$turbosms_token|', $template);
        $this->assertStringNotContainsString('$smsclub_token|', $template);
        $this->assertSame(2, substr_count($template, 'type="password"'));
    }
}
