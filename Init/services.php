<?php

namespace Okay\Modules\Sviat\Messaging;

use Okay\Core\EntityFactory;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Core\Settings;
use Okay\Modules\Sviat\Messaging\Backend\Controllers\MessagingAdmin;
use Okay\Modules\Sviat\Messaging\Helpers\MessagingCronHelper;
use Okay\Modules\Sviat\Messaging\Carriers\AllCarrierShipments;
use Okay\Modules\Sviat\Messaging\Carriers\ShipmentSource;
use Okay\Modules\Sviat\Messaging\Carriers\DeliveryCarriers;
use Okay\Modules\Sviat\Messaging\Carriers\MeestShipments;
use Okay\Modules\Sviat\Messaging\Carriers\NovaPoshtaShipments;
use Okay\Modules\Sviat\Messaging\Carriers\RozetkaShipments;
use Okay\Modules\Sviat\Messaging\Carriers\UkrposhtaShipments;
use Okay\Core\Modules\Modules;
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
    NovaPoshtaShipments::class => [
        'class' => NovaPoshtaShipments::class,
        'arguments' => [
            new SR(Modules::class),
            new SR(EntityFactory::class),
        ],
    ],
    UkrposhtaShipments::class => [
        'class' => UkrposhtaShipments::class,
        'arguments' => [
            new SR(Modules::class),
        ],
    ],
    MeestShipments::class => [
        'class' => MeestShipments::class,
        'arguments' => [
            new SR(Modules::class),
        ],
    ],
    RozetkaShipments::class => [
        'class' => RozetkaShipments::class,
        'arguments' => [
            new SR(Modules::class),
        ],
    ],
    DeliveryCarriers::class => [
        'class' => DeliveryCarriers::class,
        'arguments' => [
            new SR(EntityFactory::class),
        ],
    ],
    ShipmentSource::class => [
        'class' => AllCarrierShipments::class,
        'arguments' => [
            new SR(DeliveryCarriers::class),
            new SR(NovaPoshtaShipments::class),
            new SR(UkrposhtaShipments::class),
            new SR(MeestShipments::class),
            new SR(RozetkaShipments::class),
        ],
    ],
    NotificationEventService::class => [
        'class' => NotificationEventService::class,
        'arguments' => [
            new SR(EntityFactory::class),
            new SR(Settings::class),
            new SR(ShipmentSource::class),
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
            new SR(ShipmentSource::class),
        ],
    ],
];
