<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

/** Посилки замовлень в одному модулі доставки. */
interface CarrierShipments extends ShipmentSource
{
    /** Перевізник, чиї посилки віддає адаптер; за ним агрегатор складає мапу адаптерів. */
    public function carrier(): Carrier;
}
