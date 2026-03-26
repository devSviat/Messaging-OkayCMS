<?php

namespace Okay\Modules\Sviat\Messaging\Helpers;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Entities\DeliveriesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Modules\OkayCMS\NovaposhtaCost\Entities\NPCostDeliveryDataEntity;
use Okay\Modules\Sviat\Messaging\Entities\NotificationLogEntity;
use Okay\Modules\Sviat\Messaging\Providers\SmsProviderFactory;
use Okay\Modules\Sviat\Messaging\Providers\SmsProviderInterface;
use Okay\Modules\Sviat\Messaging\Services\NotificationEventService;

/** Крон: події доставки НП → створення/відправка SMS; повтор при помилках. */
class MessagingCronHelper
{
    private const NOVA_POSHTA_TRACKING_ENTITY_CLASS = 'Okay\Modules\Sviat\NovaPoshtaTracking\Entities\NovaPoshtaTrackingEntity';

    private const STORAGE_FREE_DAYS = 7;
    private const STORAGE_WARNING_DAY = 6;
    private const SEC_PER_HOUR = 3600;
    private const SEC_PER_DAY = 86400;

    private const SETTING_SHIPPED = 'sviat__messaging__shipped_enabled';
    private const SETTING_ARRIVED = 'sviat__messaging__arrived_enabled';
    private const SETTING_REMINDER_2DAY = 'sviat__messaging__reminder_2day_enabled';
    private const SETTING_STORAGE_WARNING = 'sviat__messaging__storage_warning_enabled';

    private $entityFactory;
    private $eventService;
    private $providerFactory;
    private $settings;

    public function __construct(
        EntityFactory $entityFactory,
        NotificationEventService $eventService,
        SmsProviderFactory $providerFactory,
        Settings $settings
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
        $stats = ['events_created' => 0, 'sent' => 0, 'errors' => 0, 'skipped' => 0];

        $trackingByOrderId = [];
        if (class_exists(self::NOVA_POSHTA_TRACKING_ENTITY_CLASS)) {
            $trackingEntity = $this->entityFactory->get(self::NOVA_POSHTA_TRACKING_ENTITY_CLASS);
            $allTracking = $trackingEntity->find();
            foreach ($allTracking as $t) {
                if (empty($t->int_doc_number)) {
                    continue;
                }
                $rawSc = $t->status_code ?? '';
                $statusCodeForFilter = ($rawSc !== '' && $rawSc !== null) ? (string)(int)$rawSc : '';
                if ($statusCodeForFilter !== '' && DeliveryStatusMapping::isNovaPoshtaFinalStatus($statusCodeForFilter)) {
                    continue;
                }
                $trackingByOrderId[$t->order_id] = $t;
            }
        }

        $ordersEntity = $this->entityFactory->get(OrdersEntity::class);
        $deliveriesEntity = $this->entityFactory->get(DeliveriesEntity::class);
        $npDataEntity = class_exists(NPCostDeliveryDataEntity::class)
            ? $this->entityFactory->get(NPCostDeliveryDataEntity::class)
            : null;

        if ($trackingByOrderId !== []) {
            $orderIds = array_keys($trackingByOrderId);
            $orders = $ordersEntity->find(['id' => $orderIds]);
            $ordersById = [];
            foreach ($orders as $o) {
                $ordersById[$o->id] = $o;
            }

            $deliveryIds = array_unique(array_filter(array_column($orders, 'delivery_id')));
            $deliveries = $deliveryIds ? $deliveriesEntity->find(['id' => $deliveryIds]) : [];
            $deliveriesById = [];
            foreach ($deliveries as $d) {
                $deliveriesById[$d->id] = $d;
            }

            $npDataByOrderId = [];
            if ($npDataEntity) {
                $npDataList = $npDataEntity->find(['order_id' => $orderIds]);
                foreach ($npDataList as $np) {
                    $npDataByOrderId[$np->order_id] = $np;
                }
            }

            $smsProvider = $this->getSmsProvider();
            $providerName = $smsProvider->getName();

            foreach ($trackingByOrderId as $orderId => $tracking) {
                $order = $ordersById[$orderId] ?? null;
                if (!$order) {
                    continue;
                }
                $delivery = isset($order->delivery_id) ? ($deliveriesById[$order->delivery_id] ?? null) : null;
                $phone = PhoneFormatter::format($order->phone ?? '');
                if (!$phone) {
                    continue;
                }
                if (!$this->eventService->isOrderEligibleForSms($order, $tracking, $delivery)) {
                    continue;
                }

                $npData = $npDataByOrderId[$orderId] ?? null;
                $isCashOnDelivery = $npData && !empty($npData->control_payment);
                $isHighValue = $this->eventService->isHighValueOrder($order, 1000);

                $docNumber = $tracking->int_doc_number ?? '';
                $rawStatus = $tracking->status_code ?? '';
                $statusCode = ($rawStatus !== '' && $rawStatus !== null) ? (string)(int)$rawStatus : '';
                $actualDeliveryAt = $tracking->actual_delivery_at ? strtotime($tracking->actual_delivery_at) : null;
                $isInTransit = DeliveryStatusMapping::isNovaPoshtaInTransit($statusCode);
                $isArrived = DeliveryStatusMapping::isNovaPoshtaArrived($statusCode);

                if ($this->settings->get(self::SETTING_SHIPPED) && $isInTransit) {
                    $eventKey = $this->eventService->buildEventKey($orderId, NotificationEventService::TYPE_SHIPPED);
                    $msg = MessageTemplates::getMessage(NotificationEventService::TYPE_SHIPPED, ['doc_number' => $docNumber, 'order_id' => $orderId]);
                    $rec = $this->eventService->createPendingNotification(
                        $orderId,
                        $phone,
                        NotificationEventService::CHANNEL_SMS,
                        NotificationEventService::TYPE_SHIPPED,
                        $msg,
                        $eventKey,
                        $providerName,
                        8
                    );
                    if ($rec) {
                        $stats['events_created']++;
                    }
                }

                if ($this->settings->get(self::SETTING_ARRIVED) && $isArrived) {
                    $eventKey = $this->eventService->buildEventKey($orderId, NotificationEventService::TYPE_ARRIVED);
                    $msg = MessageTemplates::getMessage(NotificationEventService::TYPE_ARRIVED, ['doc_number' => $docNumber, 'order_id' => $orderId]);
                    $rec = $this->eventService->createPendingNotification(
                        $orderId,
                        $phone,
                        NotificationEventService::CHANNEL_SMS,
                        NotificationEventService::TYPE_ARRIVED,
                        $msg,
                        $eventKey,
                        $providerName,
                        10
                    );
                    if ($rec) {
                        $stats['events_created']++;
                    }
                }

                if ($this->settings->get(self::SETTING_REMINDER_2DAY) && $actualDeliveryAt && $isCashOnDelivery && $isArrived) {
                    $hoursSinceArrival = (time() - $actualDeliveryAt) / self::SEC_PER_HOUR;
                    if ($hoursSinceArrival >= 48) {
                        $eventKey = $this->eventService->buildEventKey($orderId, NotificationEventService::TYPE_REMINDER_2DAY);
                        $msg = MessageTemplates::getMessage(NotificationEventService::TYPE_REMINDER_2DAY, ['doc_number' => $docNumber, 'order_id' => $orderId]);
                        $rec = $this->eventService->createPendingNotification(
                            $orderId,
                            $phone,
                            NotificationEventService::CHANNEL_SMS,
                            NotificationEventService::TYPE_REMINDER_2DAY,
                            $msg,
                            $eventKey,
                            $providerName,
                            6
                        );
                        if ($rec) {
                            $stats['events_created']++;
                        }
                    }
                }

                if ($this->settings->get(self::SETTING_STORAGE_WARNING) && $actualDeliveryAt && ($isCashOnDelivery || $isHighValue) && $isArrived) {
                    $daysSinceArrival = (time() - $actualDeliveryAt) / self::SEC_PER_DAY;
                    if ($daysSinceArrival >= self::STORAGE_WARNING_DAY && $daysSinceArrival < self::STORAGE_FREE_DAYS) {
                        $eventKey = $this->eventService->buildEventKey($orderId, NotificationEventService::TYPE_STORAGE_WARNING);
                        $msg = MessageTemplates::getMessage(NotificationEventService::TYPE_STORAGE_WARNING, ['doc_number' => $docNumber, 'order_id' => $orderId]);
                        $rec = $this->eventService->createPendingNotification(
                            $orderId,
                            $phone,
                            NotificationEventService::CHANNEL_SMS,
                            NotificationEventService::TYPE_STORAGE_WARNING,
                            $msg,
                            $eventKey,
                            $providerName,
                            7
                        );
                        if ($rec) {
                            $stats['events_created']++;
                        }
                    }
                }
            }
        }

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
