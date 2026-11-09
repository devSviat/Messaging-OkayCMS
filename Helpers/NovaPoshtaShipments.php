<?php

namespace Okay\Modules\Sviat\Messaging\Helpers;

use Closure;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Modules;
use Okay\Core\ServiceLocator;
use Okay\Modules\OkayCMS\NovaposhtaCost\Entities\NPCostDeliveryDataEntity;
use Okay\Modules\Sviat\NovaPoshta\Integration\NpOrders;
use Okay\Modules\Sviat\NovaPoshtaTracking\Entities\NovaPoshtaTrackingEntity;

/**
 * Накладні для SMS з модуля Sviat/NovaPoshta і старого NovaPoshtaTracking — того, що ввімкнений;
 * якщо накладна замовлення є в обох, береться нова.
 *
 * Кожна — об'єкт {order_id, int_doc_number, status_code (рядок), actual_delivery_at, cod}: форма
 * рядка старого трекінгу, з якою працює крон, плюс `cod` — отримувач платить при отриманні.
 */
final class NovaPoshtaShipments
{
    private readonly Closure $npOrders;

    /** @param ?Closure(): object $npOrders фасад NpOrders; береться лише коли модуль увімкнений */
    public function __construct(
        private readonly Modules $modules,
        private readonly EntityFactory $entityFactory,
        ?Closure $npOrders = null,
    ) {
        $this->npOrders = $npOrders ?? static fn(): object => ServiceLocator::getInstance()->getService(NpOrders::class);
    }

    public function available(): bool
    {
        return $this->newActive() || $this->oldActive();
    }

    /** @return array<int, object> за id замовлення; фінальні статуси відсіює крон */
    public function active(): array
    {
        $result = [];
        if ($this->oldActive()) {
            $rows = array_values(array_filter($this->entityFactory->get(NovaPoshtaTrackingEntity::class)->find(), static fn(object $t): bool => !empty($t->int_doc_number)));
            $cod = $this->oldCod(array_map(static fn(object $t): int => (int)$t->order_id, $rows));
            foreach ($rows as $t) {
                $result[(int)$t->order_id] = self::fromOld($t, $cod[(int)$t->order_id] ?? false);
            }
        }
        if ($this->newActive()) {
            $npOrders = ($this->npOrders)();
            // Накладна в новому модулі перекриває стару й тоді, коли вона вже фінальна, а старий трекінг відстав.
            if ($result !== []) {
                $result = array_diff_key($result, $npOrders->shipments(array_keys($result)));
            }
            foreach ($npOrders->unfinished() as $shipment) {
                $result[(int)$shipment->orderId] = self::fromNew($shipment);
            }
        }

        return $result;
    }

    public function forOrder(int $orderId): ?object
    {
        if ($this->newActive()) {
            $shipment = ($this->npOrders)()->shipments([$orderId])[$orderId] ?? null;
            if ($shipment !== null) {
                return self::fromNew($shipment);
            }
        }
        if ($this->oldActive()) {
            $t = $this->entityFactory->get(NovaPoshtaTrackingEntity::class)->findOne(['order_id' => $orderId]);
            if ($t && !empty($t->int_doc_number)) {
                return self::fromOld($t, $this->oldCod([$orderId])[$orderId] ?? false);
            }
        }

        return null;
    }

    private static function fromNew(object $shipment): object
    {
        return (object)[
            'order_id' => (int)$shipment->orderId,
            'int_doc_number' => (string)$shipment->number,
            'status_code' => $shipment->statusCode === null ? '' : (string)$shipment->statusCode,
            'actual_delivery_at' => $shipment->arrivedAt,
            'cod' => (bool)$shipment->cod,
        ];
    }

    private static function fromOld(object $t, bool $cod): object
    {
        $code = (string)($t->status_code ?? '');

        return (object)[
            'order_id' => (int)$t->order_id,
            'int_doc_number' => (string)$t->int_doc_number,
            'status_code' => $code !== '' ? (string)(int)$code : '',
            'actual_delivery_at' => $t->actual_delivery_at ?? null,
            'cod' => $cod,
        ];
    }

    /**
     * Післяплата старого модуля — контроль оплати в даних NovaposhtaCost, як рахував Messaging досі.
     *
     * @param list<int> $orderIds
     * @return array<int, bool>
     */
    private function oldCod(array $orderIds): array
    {
        if ($orderIds === [] || !class_exists(NPCostDeliveryDataEntity::class)) {
            return [];
        }
        $cod = [];
        foreach ($this->entityFactory->get(NPCostDeliveryDataEntity::class)->find(['order_id' => $orderIds]) as $np) {
            $cod[(int)$np->order_id] = !empty($np->control_payment);
        }

        return $cod;
    }

    private function newActive(): bool
    {
        return class_exists(NpOrders::class) && $this->modules->isActiveModule('Sviat', 'NovaPoshta');
    }

    private function oldActive(): bool
    {
        return class_exists(NovaPoshtaTrackingEntity::class) && $this->modules->isActiveModule('Sviat', 'NovaPoshtaTracking');
    }
}
