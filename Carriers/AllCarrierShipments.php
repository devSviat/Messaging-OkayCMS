<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

/**
 * Посилки всіх перевізників, одна на замовлення. Коли посилка замовлення є в обох, береться перевізник
 * поточної доставки: менеджер міг створити накладну НП, а потім перевести замовлення на Укрпошту, і
 * покинута накладна лишається незавершеною без строку давності. Доставка без перевізника: Нова Пошта.
 */
final class AllCarrierShipments implements CarrierShipments
{
    public function __construct(
        private readonly NovaPoshtaShipments $novaPoshta,
        private readonly UkrposhtaShipments $ukrposhta,
        private readonly DeliveryCarriers $deliveryCarriers,
    ) {
    }

    public function available(): bool
    {
        return $this->novaPoshta->available() || $this->ukrposhta->available();
    }

    public function active(): array
    {
        // Старий трекінг віддає й фінальні накладні, новий модуль ні; повернення Укрпошти ще в unfinished().
        // Фінальна накладна не має виграшу в перетині, інакше глушила б заміну від іншого перевізника.
        $notFinal = static fn(CarrierShipment $s): bool => !$s->event?->isFinal();
        $novaPoshta = array_filter($this->novaPoshta->active(), $notFinal);
        $ukrposhta = array_filter($this->ukrposhta->active(), $notFinal);
        $both = array_keys(array_intersect_key($novaPoshta, $ukrposhta));
        $carriers = $this->deliveryCarriers->ofOrders($both);
        foreach ($both as $orderId) {
            if (($carriers[$orderId] ?? null) === Carrier::Ukrposhta) {
                unset($novaPoshta[$orderId]);
            } else {
                unset($ukrposhta[$orderId]);
            }
        }

        return $novaPoshta + $ukrposhta;
    }

    public function forOrder(int $orderId): ?CarrierShipment
    {
        $novaPoshta = $this->novaPoshta->forOrder($orderId);
        $ukrposhta = $this->ukrposhta->forOrder($orderId);
        if ($novaPoshta === null || $ukrposhta === null) {
            return $novaPoshta ?? $ukrposhta;
        }
        if ($novaPoshta->event?->isFinal()) {
            return $ukrposhta;
        }
        if ($ukrposhta->event?->isFinal()) {
            return $novaPoshta;
        }

        return ($this->deliveryCarriers->ofOrders([$orderId])[$orderId] ?? null) === Carrier::Ukrposhta ? $ukrposhta : $novaPoshta;
    }
}
