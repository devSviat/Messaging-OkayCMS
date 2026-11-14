<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

/** Посилки замовлень у модулях доставки, з яких Messaging будує SMS. */
interface CarrierShipments
{
    /** Чи ввімкнений хоч один модуль доставки, що дає посилки. */
    public function available(): bool;

    /** @return array<int, CarrierShipment> за id замовлення; можуть бути й фінальні, їх відсіює крон */
    public function active(): array;

    public function forOrder(int $orderId): ?CarrierShipment;
}
