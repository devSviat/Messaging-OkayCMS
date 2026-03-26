<?php

namespace Okay\Modules\Sviat\Messaging\Entities;

use Okay\Core\Entity\Entity;

/** Лог повідомлень (SMS тощо). event_key — унікальний ключ події (захист від дубля). */
class NotificationLogEntity extends Entity
{
    protected static $fields = [
        'id',
        'order_id',
        'phone',
        'channel',
        'type',
        'status',
        'priority',
        'message',
        'provider',
        'provider_message_id',
        'event_key',
        'attempts',
        'error_text',
        'meta',
        'created_at',
        'scheduled_at',
        'sent_at',
    ];

    protected static $defaultOrderFields = ['id DESC'];
    protected static $table = 'sviat__notification_log';
    protected static $tableAlias = 'nl';

    protected function filter__order($order, $filter)
    {
        $this->order($order, $filter);
    }
}
