<?php

namespace Okay\Modules\Sviat\Messaging\Helpers;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\Messaging\Carriers\ShipmentSource;
use Okay\Modules\Sviat\Messaging\Carriers\ShipmentEvent;
use Okay\Modules\Sviat\Messaging\Entities\NotificationLogEntity;
use Okay\Modules\Sviat\Messaging\Providers\SmsProviderFactory;
use Okay\Modules\Sviat\Messaging\Providers\SmsProviderInterface;
use Okay\Modules\Sviat\Messaging\Services\NotificationEventService;

/** Крон: події доставки перевізників → створення/відправка SMS; повтор при помилках. */
class MessagingCronHelper
{
    private const int SEC_PER_HOUR = 3600;
    private const int SEC_PER_DAY = 86400;

    private const string SETTING_SHIPPED = 'sviat__messaging__shipped_enabled';
    private const string SETTING_ARRIVED = 'sviat__messaging__arrived_enabled';
    private const string SETTING_REMINDER_2DAY = 'sviat__messaging__reminder_2day_enabled';
    private const string SETTING_STORAGE_WARNING = 'sviat__messaging__storage_warning_enabled';

    private $entityFactory;
    private $eventService;
    private $providerFactory;
    private $settings;

    public function __construct(
        EntityFactory $entityFactory,
        NotificationEventService $eventService,
        SmsProviderFactory $providerFactory,
        Settings $settings,
        private readonly ShipmentSource $shipments
    ) {
        $this->entityFactory = $entityFactory;
        $this->eventService = $eventService;
        $this->providerFactory = $providerFactory;
        $this->settings = $settings;
    }

    private function getSmsProvider(): SmsProviderInterface
    {
        return $this->providerFactory->getProvider();
    }

    public function processEventsAndSend(): array
    {
        $eventsCreated = $this->createEvents();
        $sent = $this->sendPending();

        return ['events_created' => $eventsCreated, 'sent' => $sent['sent'], 'errors' => $sent['errors'], 'skipped' => 0];
    }

    /** Ставить у чергу SMS за станом посилок, нічого не надсилаючи. */
    public function createEvents(): int
    {
        $created = 0;

        $shipmentsByOrderId = [];
        foreach ($this->shipments->active() as $orderId => $shipment) {
            if ($shipment->event?->isFinal()) {
                continue;
            }
            $shipmentsByOrderId[$orderId] = $shipment;
        }
        if ($shipmentsByOrderId === []) {
            return 0;
        }

        $orderIds = array_keys($shipmentsByOrderId);
        // Без ліміту сутність віддала б лише 100 замовлень, а накладних у дорозі буває більше.
        $orders = $this->entityFactory->get(OrdersEntity::class)->find(['id' => $orderIds, 'limit' => count($orderIds)]);
        $ordersById = [];
        foreach ($orders as $o) {
            $ordersById[$o->id] = $o;
        }

        $deliveryIds = array_unique(array_filter(array_column($orders, 'delivery_id')));
        $deliveries = $deliveryIds ? $this->entityFactory->get(DeliveriesEntity::class)->find(['id' => $deliveryIds]) : [];
        $deliveriesById = [];
        foreach ($deliveries as $d) {
            $deliveriesById[$d->id] = $d;
        }

        $providerName = $this->getSmsProvider()->getName();

        foreach ($shipmentsByOrderId as $orderId => $shipment) {
            $order = $ordersById[$orderId] ?? null;
            if (!$order) {
                continue;
            }
            $delivery = isset($order->delivery_id) ? ($deliveriesById[$order->delivery_id] ?? null) : null;
            $phone = PhoneFormatter::format($order->phone ?? '');
            if (!$phone) {
                continue;
            }
            if (!$this->eventService->isOrderEligibleForSms($order, $shipment, $delivery)) {
                continue;
            }

            $isHighValue = $this->eventService->isHighValueOrder($order, 1000);
            $arrivedAt = $shipment->arrivedAt ? strtotime($shipment->arrivedAt) : null;
            $isArrived = $shipment->event === ShipmentEvent::Arrived;
            $queue = function (string $type, int $priority) use ($orderId, $phone, $shipment, $providerName, &$created): void {
                $message = MessageTemplates::getMessage($type, ['doc_number' => $shipment->number, 'order_id' => $orderId], $shipment->carrier);
                $eventKey = $this->eventService->buildEventKey($orderId, $type, $shipment);
                if ($this->eventService->createPendingNotification($orderId, $phone, NotificationEventService::CHANNEL_SMS, $type, $message, $eventKey, $providerName, $priority)) {
                    $created++;
                }
            };

            if ($this->settings->get(self::SETTING_SHIPPED) && $shipment->event === ShipmentEvent::Shipped) {
                $queue(NotificationEventService::TYPE_SHIPPED, 8);
            }

            if ($this->settings->get(self::SETTING_ARRIVED) && $isArrived) {
                $queue(NotificationEventService::TYPE_ARRIVED, 10);
            }

            if ($this->settings->get(self::SETTING_REMINDER_2DAY) && $arrivedAt && $shipment->cod && $isArrived) {
                $hoursSinceArrival = (time() - $arrivedAt) / self::SEC_PER_HOUR;
                if ($hoursSinceArrival >= 48) {
                    $queue(NotificationEventService::TYPE_REMINDER_2DAY, 6);
                }
            }

            $freeDays = $shipment->carrier->freeStorageDays();
            if ($freeDays !== null && $this->settings->get(self::SETTING_STORAGE_WARNING) && $arrivedAt && ($shipment->cod || $isHighValue) && $isArrived) {
                $daysSinceArrival = (time() - $arrivedAt) / self::SEC_PER_DAY;
                // Попередження за добу до кінця безкоштовного зберігання.
                if ($daysSinceArrival >= $freeDays - 1 && $daysSinceArrival < $freeDays) {
                    $queue(NotificationEventService::TYPE_STORAGE_WARNING, 7);
                }
            }
        }

        return $created;
    }

    /**
     * Надсилає чергу через провайдера.
     *
     * @return array{sent: int, errors: int}
     */
    public function sendPending(): array
    {
        $stats = ['sent' => 0, 'errors' => 0];
        $smsProvider = $this->getSmsProvider();
        $logEntity = $this->entityFactory->get(NotificationLogEntity::class);
        $pendingList = $logEntity->find([
            'status' => NotificationEventService::STATUS_PENDING,
            'limit' => 100,
            'order' => ['priority_desc', 'id_asc'],
        ]);
        foreach ($pendingList as $notification) {
            $result = $smsProvider->send($notification->phone, $notification->message);
            $attempts = (int)$notification->attempts + 1;
            if ($result['success']) {
                $logEntity->update($notification->id, [
                    'status' => NotificationEventService::STATUS_SENT,
                    'provider_message_id' => $result['provider_message_id'] ?? '',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'attempts' => $attempts,
                ]);
                $stats['sent']++;
            } else {
                $logEntity->update($notification->id, [
                    'status' => NotificationEventService::STATUS_ERROR,
                    'error_text' => $result['error'] ?? 'Unknown',
                    'attempts' => $attempts,
                ]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    public function retryFailed(): array
    {
        $stats = ['retried' => 0, 'sent' => 0, 'errors' => 0];
        $smsProvider = $this->getSmsProvider();
        $list = $this->eventService->getErrorRecordsForRetry(NotificationEventService::MAX_ATTEMPTS, 30);
        $logEntity = $this->entityFactory->get(NotificationLogEntity::class);

        foreach ($list as $notification) {
            if ($this->eventService->hasRecentSmsToPhone($notification->phone, NotificationEventService::ANTISPAM_HOURS)) {
                continue;
            }
            $result = $smsProvider->send($notification->phone, $notification->message);
            $attempts = (int)$notification->attempts + 1;
            $stats['retried']++;
            if ($result['success']) {
                $logEntity->update($notification->id, [
                    'status' => NotificationEventService::STATUS_SENT,
                    'provider_message_id' => $result['provider_message_id'] ?? '',
                    'sent_at' => date('Y-m-d H:i:s'),
                    'attempts' => $attempts,
                    'error_text' => null,
                ]);
                $stats['sent']++;
            } else {
                $logEntity->update($notification->id, [
                    'error_text' => $result['error'] ?? 'Unknown',
                    'attempts' => $attempts,
                ]);
                $stats['errors']++;
            }
        }
        return $stats;
    }
}
