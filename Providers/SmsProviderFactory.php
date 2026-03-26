<?php

namespace Okay\Modules\Sviat\Messaging\Providers;

use Okay\Core\Settings;

/** Повертає активний SMS-провайдер з налаштувань. */
class SmsProviderFactory
{
    public const PROVIDER_TURBOSMS = 'turbosms';
    public const PROVIDER_SMSCLUB = 'smsclub';

    private $settings;
    private $turboSmsProvider;
    private $smsClubProvider;

    public function __construct(
        Settings $settings,
        TurboSmsProvider $turboSmsProvider,
        SmsClubProvider $smsClubProvider
    ) {
        $this->settings = $settings;
        $this->turboSmsProvider = $turboSmsProvider;
        $this->smsClubProvider = $smsClubProvider;
    }

    public function getProvider(): SmsProviderInterface
    {
        $provider = $this->settings->get('sviat__messaging__provider');
        if ($provider === self::PROVIDER_SMSCLUB) {
            return $this->smsClubProvider;
        }
        return $this->turboSmsProvider;
    }

    public function getProviderName(): string
    {
        return $this->settings->get('sviat__messaging__provider') ?: self::PROVIDER_TURBOSMS;
    }
}
