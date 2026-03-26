<?php

namespace Okay\Modules\Sviat\Messaging;

use Okay\Core\EntityFactory;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Messaging\Backend\Controllers\MessagingAdmin;
use Okay\Modules\Sviat\Messaging\Helpers\MessagingCronHelper;
use Okay\Modules\Sviat\Messaging\Providers\SmsClubProvider;
use Okay\Modules\Sviat\Messaging\Providers\SmsProviderFactory;
use Okay\Modules\Sviat\Messaging\Providers\TurboSmsProvider;
use Okay\Modules\Sviat\Messaging\Services\NotificationEventService;

return [
    MessagingAdmin::class => [
        'class' => MessagingAdmin::class,
        'arguments' => [
            new SR(SmsClubProvider::class),
            new SR(TurboSmsProvider::class),
            new SR(EntityFactory::class),
        ],
    ],
    NotificationEventService::class => [
        'class' => NotificationEventService::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Settings::class),
        ],
    ],
    TurboSmsProvider::class => [
        'class' => TurboSmsProvider::class,
        'arguments' => [
            new SR(Settings::class),
        ],
    ],
    SmsClubProvider::class => [
        'class' => SmsClubProvider::class,
        'arguments' => [
            new SR(Settings::class),
        ],
    ],
    SmsProviderFactory::class => [
        'class' => SmsProviderFactory::class,
        'arguments' => [
            new SR(Settings::class),
            new SR(TurboSmsProvider::class),
            new SR(SmsClubProvider::class),
        ],
    ],
    MessagingCronHelper::class => [
        'class' => MessagingCronHelper::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(NotificationEventService::class),
            new SR(SmsProviderFactory::class),
            new SR(Settings::class),
        ],
    ],
];
