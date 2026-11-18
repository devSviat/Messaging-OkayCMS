<?php

namespace Modules\Sviat\Messaging;

use PHPUnit\Framework\TestCase;

/**
 * Адмінка Messaging працює з Новою Поштою, Укрпоштою й Meest; тексти не мають обіцяти лише одну, а
 * попередження про платне зберігання надсилається тільки для Нової Пошти.
 */
class TranslationsTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../../../Okay/Modules/Sviat/Messaging/Backend';

    /** @return array<string, string> */
    private static function lang(string $code): array
    {
        $lang = [];
        include self::DIR . "/lang/{$code}.php";

        return $lang;
    }

    public function testKeysMatchAcrossLanguages(): void
    {
        $ua = array_keys(self::lang('ua'));
        sort($ua);
        foreach (['en', 'ru'] as $code) {
            $keys = array_keys(self::lang($code));
            sort($keys);
            self::assertSame($ua, $keys, $code);
        }
    }

    public function testTemplateKeysExist(): void
    {
        preg_match_all('/\$btr->(sviat_messaging_\w+)/', (string)file_get_contents(self::DIR . '/design/html/messaging_admin.tpl'), $m);
        self::assertNotEmpty($m[1]);
        foreach (['ua', 'en', 'ru'] as $code) {
            $lang = self::lang($code);
            foreach (array_unique($m[1]) as $key) {
                self::assertArrayHasKey($key, $lang, "{$code}: {$key}");
            }
        }
    }

    public function testTextsAreNotNovaPoshtaOnly(): void
    {
        $npOnly = ['ua' => ['Нова Пошта', 'НП'], 'en' => ['Nova Poshta', 'NP'], 'ru' => ['Новая Почта', 'Нова Пошта', 'НП']];
        foreach ($npOnly as $code => $words) {
            $lang = self::lang($code);
            foreach (['sviat_messaging_type_shipped_desc', 'sviat_messaging_type_arrived_desc'] as $key) {
                foreach ($words as $word) {
                    self::assertStringNotContainsString($word, $lang[$key], "{$code}: {$key}");
                }
            }
            self::assertStringContainsString($code === 'en' ? 'Ukrposhta' : ($code === 'ru' ? 'Укрпочта' : 'Укрпошта'), $lang['sviat_messaging_title'], $code);
            self::assertStringContainsString('Meest', $lang['sviat_messaging_title'], $code);
            self::assertStringContainsString('Sviat/Meest', $lang['sviat_messaging_np_tracking_required_description'], $code);
            self::assertStringContainsString('Meest', $lang['sviat_messaging_example_title_meest'], $code);
        }
    }

    public function testStorageWarningSaysNovaPoshtaOnlyAndDaySix(): void
    {
        foreach (['ua' => 'Нова Пошта', 'en' => 'Nova Poshta', 'ru' => 'Новая Почта'] as $code => $np) {
            $text = self::lang($code)['sviat_messaging_type_storage_warning_desc'];
            self::assertStringContainsString($np, $text, $code);
            self::assertStringContainsString('6', $text, $code);
        }
    }

    public function testNoDashConnectorsInUkrainian(): void
    {
        foreach (self::lang('ua') as $key => $text) {
            self::assertStringNotContainsString(' — ', $text, $key);
            self::assertStringNotContainsString(' - ', $text, $key);
        }
    }
}
