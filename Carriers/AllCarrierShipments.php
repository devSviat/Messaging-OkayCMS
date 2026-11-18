<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

/**
 * Посилки всіх перевізників, одна на замовлення. Посилки двох перевізників бувають, коли менеджер створив
 * посилку, а потім перевів замовлення на іншу доставку, і покинута посилка лишається незавершеною без строку
 * давності. Тоді фінальна посилка програє нефінальній, далі перемагає перевізник поточної доставки, а за
 * доставки без перевізника — Нова Пошта, якщо в неї є посилка, інакше той, що лишився.
 */
final class AllCarrierShipments implements ShipmentSource
{
    /** @var array<string, CarrierShipments> за Carrier::value */
    private readonly array $byCarrier;

    /** Адаптери — варіадиком, бо контейнер не розв'язує ServiceReference усередині масиву. */
    public function __construct(private readonly DeliveryCarriers $deliveryCarriers, CarrierShipments ...$adapters)
    {
        $byCarrier = [];
        foreach ($adapters as $adapter) {
            $byCarrier[$adapter->carrier()->value] = $adapter;
        }
        $this->byCarrier = $byCarrier;
    }

    public function available(): bool
    {
        foreach ($this->byCarrier as $adapter) {
            if ($adapter->available()) {
                return true;
            }
        }

        return false;
    }

    public function active(): array
    {
        // Старий трекінг віддає й фінальні накладні, нові модулі ні; повернення Укрпошти ще в unfinished().
        // Фінальна посилка не має виграшу в перетині, інакше глушила б заміну від іншого перевізника.
        /** @var array<int, array<string, CarrierShipment>> $candidates */
        $candidates = [];
        foreach ($this->byCarrier as $carrier => $adapter) {
            foreach ($adapter->active() as $orderId => $shipment) {
                if (!$shipment->event?->isFinal()) {
                    $candidates[$orderId][$carrier] = $shipment;
                }
            }
        }
        $contested = array_keys(array_filter($candidates, static fn(array $c): bool => count($c) > 1));
        $deliveryOf = $this->deliveryCarriers->ofOrders($contested);

        $result = [];
        foreach ($candidates as $orderId => $byCarrier) {
            $result[$orderId] = self::pick($byCarrier, $deliveryOf[$orderId] ?? null);
        }

        return $result;
    }

    public function forOrder(int $orderId): ?CarrierShipment
    {
        $candidates = [];
        foreach ($this->byCarrier as $carrier => $adapter) {
            $shipment = $adapter->forOrder($orderId);
            if ($shipment !== null) {
                $candidates[$carrier] = $shipment;
            }
        }
        $candidates = self::withoutLosers($candidates);
        if ($candidates === []) {
            return null;
        }
        if (count($candidates) === 1) {
            return reset($candidates);
        }

        return self::pick($candidates, $this->deliveryCarriers->ofOrders([$orderId])[$orderId] ?? null);
    }

    /**
     * Фінальні посилки програють нефінальним. Коли фінальні всі, поступається Нова Пошта: так forOrder()
     * вибирав між НП і Укрпоштою до Meest.
     *
     * @param array<string, CarrierShipment> $candidates
     * @return array<string, CarrierShipment>
     */
    private static function withoutLosers(array $candidates): array
    {
        $live = array_filter($candidates, static fn(CarrierShipment $s): bool => !$s->event?->isFinal());
        if ($live !== []) {
            return $live;
        }
        if (count($candidates) > 1) {
            unset($candidates[Carrier::NovaPoshta->value]);
        }

        return $candidates;
    }

    /**
     * Порядок адаптерів на вибір не впливає: якщо після Нової Пошти лишилося кілька, береться перший за
     * порядком випадків Carrier.
     *
     * @param non-empty-array<string, CarrierShipment> $candidates
     */
    private static function pick(array $candidates, ?Carrier $delivery): CarrierShipment
    {
        if ($delivery !== null && isset($candidates[$delivery->value])) {
            return $candidates[$delivery->value];
        }
        if (isset($candidates[Carrier::NovaPoshta->value])) {
            return $candidates[Carrier::NovaPoshta->value];
        }
        foreach (Carrier::cases() as $carrier) {
            if (isset($candidates[$carrier->value])) {
                return $candidates[$carrier->value];
            }
        }

        return reset($candidates);
    }
}
