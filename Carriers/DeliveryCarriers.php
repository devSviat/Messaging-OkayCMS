<?php

namespace Okay\Modules\Sviat\Messaging\Carriers;

use Okay\Core\EntityFactory;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\ModulesEntity;
use Okay\Entities\OrdersEntity;

/** Перевізник поточної доставки замовлення за модулем, до якого прив'язаний спосіб доставки. */
final class DeliveryCarriers
{
    private const array MODULES = [
        'Sviat/NovaPoshta' => Carrier::NovaPoshta,
        'Sviat/NovaPoshtaTracking' => Carrier::NovaPoshta,
        'OkayCMS/NovaposhtaCost' => Carrier::NovaPoshta,
        'Sviat/Ukrposhta' => Carrier::Ukrposhta,
    ];

    public function __construct(private readonly EntityFactory $entityFactory)
    {
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, Carrier> за id замовлення; доставки без модуля перевізника (самовивіз тощо) пропущено
     */
    public function ofOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $deliveryOf = [];
        foreach ($this->entityFactory->get(OrdersEntity::class)->find(['id' => $orderIds, 'limit' => count($orderIds)]) as $order) {
            $deliveryOf[(int)$order->id] = (int)$order->delivery_id;
        }
        $deliveryIds = array_values(array_unique(array_filter($deliveryOf)));
        if ($deliveryIds === []) {
            return [];
        }
        $moduleOf = [];
        foreach ($this->entityFactory->get(DeliveriesEntity::class)->find(['id' => $deliveryIds, 'limit' => count($deliveryIds)]) as $delivery) {
            $moduleOf[(int)$delivery->id] = (int)($delivery->module_id ?? 0);
        }
        $moduleIds = array_values(array_unique(array_filter($moduleOf)));
        if ($moduleIds === []) {
            return [];
        }
        $carrierOf = [];
        foreach ($this->entityFactory->get(ModulesEntity::class)->find(['id' => $moduleIds, 'limit' => count($moduleIds)]) as $module) {
            $carrier = self::MODULES[$module->vendor . '/' . $module->module_name] ?? null;
            if ($carrier !== null) {
                $carrierOf[(int)$module->id] = $carrier;
            }
        }

        $result = [];
        foreach ($deliveryOf as $orderId => $deliveryId) {
            $carrier = $carrierOf[$moduleOf[$deliveryId] ?? 0] ?? null;
            if ($carrier !== null) {
                $result[$orderId] = $carrier;
            }
        }

        return $result;
    }
}
