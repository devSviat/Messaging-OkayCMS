<?php

namespace Okay\Modules\Sviat\Messaging\Init;

use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\EntityField;
use Okay\Core\Scheduler\Schedule;
use Okay\Modules\Sviat\Messaging\Entities\NotificationLogEntity;
use Okay\Modules\Sviat\Messaging\Backend\Controllers\MessagingAdmin;
use Okay\Modules\Sviat\Messaging\Helpers\MessagingCronHelper;

class Init extends AbstractInit
{
    public function install()
    {
        $this->setBackendMainController('MessagingAdmin');
        $this->migrateEntityTable(NotificationLogEntity::class, [
            (new EntityField('id'))->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
            (new EntityField('order_id'))->setTypeInt(11)->setNullable()->setIndex(),
            (new EntityField('phone'))->setTypeVarchar(32)->setIndex(),
            (new EntityField('channel'))->setTypeEnum(['sms', 'viber'], false)->setIndex(),
            (new EntityField('type'))->setTypeEnum(['shipped', 'arrived', 'reminder_2day', 'storage_warning', 'manual'], false)->setIndex(),
            (new EntityField('status'))->setTypeEnum(['pending', 'sent', 'error', 'skipped'], false)->setIndex(),
            (new EntityField('priority'))->setTypeTinyInt(2)->setNullable()->setDefault(5),
            (new EntityField('message'))->setTypeText()->setNullable(),
            (new EntityField('provider'))->setTypeVarchar(64)->setNullable(),
            (new EntityField('provider_message_id'))->setTypeVarchar(255)->setNullable(),
            (new EntityField('event_key'))->setTypeVarchar(255)->setNullable()->setIndexUnique(),
            (new EntityField('attempts'))->setTypeInt(11)->setNullable()->setDefault(0),
            (new EntityField('error_text'))->setTypeText()->setNullable(),
            (new EntityField('meta'))->setTypeLongText()->setNullable(),
            (new EntityField('created_at'))->setTypeDatetime()->setNullable()->setIndex(),
            (new EntityField('scheduled_at'))->setTypeDatetime()->setNullable(),
            (new EntityField('sent_at'))->setTypeDatetime()->setNullable(),
        ]);
    }

    public function init()
    {
        $this->registerBackendController('MessagingAdmin');
        $this->addBackendControllerPermission('MessagingAdmin', 'sviat__messaging');

        $this->registerSchedule(
            (new Schedule([MessagingCronHelper::class, 'processEventsAndSend']))
                ->name('Messaging: process events and send SMS')
                ->time('*/20 * * * *')
                ->overlap(false)
                ->timeout(600)
        );
        $this->registerSchedule(
            (new Schedule([MessagingCronHelper::class, 'retryFailed']))
                ->name('Messaging: retry failed SMS')
                ->time('*/15 * * * *')
                ->overlap(false)
                ->timeout(300)
        );
    }
}
