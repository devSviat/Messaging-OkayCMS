<?php

namespace Okay\Modules\Sviat\Messaging\Services;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\OkayCMS\NovaposhtaCost\Entities\NPCostDeliveryDataEntity;
use Okay\Modules\Sviat\Messaging\Entities\NotificationLogEntity;
use Okay\Modules\Sviat\Messaging\Helpers\DeliveryStatusMapping;
use Okay\Modules\Sviat\Messaging\Helpers\PhoneFormatter;

/** Події для SMS: event_key, перевірка дубля та антиспаму. */
class NotificationEventService
{
    public const CHANNEL_SMS = 'sms';
    public const TYPE_SHIPPED = 'shipped';
    public const TYPE_ARRIVED = 'arrived';
    public const TYPE_REMINDER_2DAY = 'reminder_2day';
    public const TYPE_STORAGE_WARNING = 'storage_warning';
    public const TYPE_MANUAL = 'manual';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';

    public const ANTISPAM_HOURS = 1;
    public const MAX_ATTEMPTS = 3;

    private $entityFactory;
    private $settings;

    public function __construct(EntityFactory $entityFactory, Settings $settings)
    {
        $this->entityFactory = $entityFactory;
        $this->settings = $settings;
    }

    public function buildEventKey(int $orderId, string $type): string
    {
        return 'order_' . $orderId . '_' . $type;
    }

    public function hasEventAlreadyBeenSent(string $eventKey): bool
    {
        $log = $this->entityFactory->get(NotificationLogEntity::class);
        $existing = $log->findOne(['event_key' => $eventKey]);
        if (!$existing) {
            return false;
        }
        if (in_array($existing->status, [self::STATUS_SENT, self::STATUS_PENDING, self::STATUS_ERROR], true)) {
            return true;
        }
        if ($existing->status === self::STATUS_SKIPPED) {
            $createdAt = $existing->created_at ?? null;
            $ageHours = $createdAt ? (time() - strtotime($createdAt)) / 3600 : 0;
            return $ageHours < self::ANTISPAM_HOURS;
        }
        return false;
    }

    public function hasRecentSmsToPhone(string $phone, int $hours = self::ANTISPAM_HOURS): bool
    {
        $log = $this->entityFactory->get(NotificationLogEntity::class);
        $list = $log->find(['phone' => $phone, 'channel' => self::CHANNEL_SMS]);
        $since = strtotime("-{$hours} hours");
        foreach ($list as $item) {
            $sentAt = $item->sent_at ?? $item->created_at ?? null;
            if ($sentAt && strtotime($sentAt) >= $since) {
                return true;
            }
        }
        return false;
    }

    public function isOrderEligibleForSms(object $order, ?object $tracking, ?object $delivery): bool
    {
        if (!$order || empty($order->phone)) {
            return false;
        }
        if ($tracking && DeliveryStatusMapping::isNovaPoshtaReceived($tracking->status_code ?? '')) {
            return false;
        }
        if (!$tracking || empty($tracking->int_doc_number)) {
            return false;
        }
        if ($delivery) {
            $name = is_object($delivery->name) ? ($delivery->name->name ?? '') : (string)($delivery->name ?? '');
            if (stripos($name, 'самовивіз') !== false || stripos($name, 'самовывоз') !== false) {
                return false;
            }
        }
        return true;
    }

    /** Причина, чому замовлення не підходить для SMS (для логу). Повертає 'ok' якщо підходить. */
    public function getOrderEligibilityReason(object $order, ?object $tracking, ?object $delivery): string
    {
        if (!$order || empty($order->phone)) {
            return 'no_phone';
        }
        if ($tracking && DeliveryStatusMapping::isNovaPoshtaReceived((string)(int)($tracking->status_code ?? ''))) {
            return 'tracking_received';
        }
        if (!$tracking || empty($tracking->int_doc_number)) {
            return 'no_tracking_or_doc';
        }
        if ($delivery) {
            $name = is_object($delivery->name) ? ($delivery->name->name ?? '') : (string)($delivery->name ?? '');
            if (stripos($name, 'самовивіз') !== false || stripos($name, 'самовывоз') !== false) {
                return 'delivery_pickup';
            }
        }
        return 'ok';
    }

    /** Потребує модуль OkayCMS NovaposhtaCost. */
    public function isCashOnDelivery(object $order): bool
    {
        if (!class_exists(NPCostDeliveryDataEntity::class)) {
            return false;
        }
        try {
            $npData = $this->entityFactory->get(NPCostDeliveryDataEntity::class)->findOne(['order_id' => $order->id]);
            return $npData && !empty($npData->control_payment);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isHighValueOrder(object $order, float $threshold = 1000.0): bool
    {
        $price = (float)($order->total_price ?? 0);
        return $price >= $threshold;
    }

    /** @return object|null Запис або null при дублі/антиспамі */
    public function createPendingNotification(
        int $orderId,
        string $phone,
        string $channel,
        string $type,
        string $message,
        string $eventKey,
        string $provider = 'turbosms',
        int $priority = 5
    ): ?object {
        if ($this->hasEventAlreadyBeenSent($eventKey)) {
            return null;
        }
        if ($channel === self::CHANNEL_SMS && $this->hasRecentSmsToPhone($phone, self::ANTISPAM_HOURS)) {
            $log = $this->entityFactory->get(NotificationLogEntity::class);
            $log->add([
                'order_id' => $orderId,
                'phone' => $phone,
                'channel' => $channel,
                'type' => $type,
                'status' => self::STATUS_SKIPPED,
                'priority' => $priority,
                'message' => $message,
                'provider' => $provider,
                'event_key' => $eventKey,
                'attempts' => 0,
                'error_text' => 'Antispam: SMS already sent to this number in last ' . self::ANTISPAM_HOURS . 'h',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return null;
        }

        $log = $this->entityFactory->get(NotificationLogEntity::class);
        $existing = $log->findOne(['event_key' => $eventKey]);
        if ($existing && $existing->status === self::STATUS_SKIPPED) {
            $log->update($existing->id, [
                'status' => self::STATUS_PENDING,
                'message' => $message,
                'priority' => $priority,
                'provider' => $provider,
                'scheduled_at' => date('Y-m-d H:i:s'),
                'error_text' => null,
                'attempts' => 0,
            ]);
            return $log->get($existing->id);
        }

        $id = $log->add([
            'order_id' => $orderId,
            'phone' => $phone,
            'channel' => $channel,
            'type' => $type,
            'status' => self::STATUS_PENDING,
            'priority' => $priority,
            'message' => $message,
            'provider' => $provider,
            'event_key' => $eventKey,
            'attempts' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'scheduled_at' => date('Y-m-d H:i:s'),
        ]);
        return $id ? $log->get((int) $id) : null;
    }

    public function getErrorRecordsForRetry(int $maxAttempts = self::MAX_ATTEMPTS, int $limit = 50): array
    {
        $log = $this->entityFactory->get(NotificationLogEntity::class);
        $list = $log->find(['status' => self::STATUS_ERROR, 'limit' => $limit * 2]);
        $result = [];
        foreach ($list as $item) {
            if ((int)$item->attempts < $maxAttempts && count($result) < $limit) {
                $result[] = $item;
            }
        }
        return $result;
    }
}
