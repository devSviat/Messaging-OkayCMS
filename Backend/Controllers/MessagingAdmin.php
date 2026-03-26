<?php

namespace Okay\Modules\Sviat\Messaging\Backend\Controllers;

use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\EntityFactory;
use Okay\Entities\ModulesEntity;
use Okay\Modules\Sviat\Messaging\Helpers\MessageTemplates;
use Okay\Modules\Sviat\Messaging\Providers\SmsClubProvider;
use Okay\Modules\Sviat\Messaging\Providers\TurboSmsProvider;
use Okay\Modules\Sviat\Messaging\Services\NotificationEventService;

/** Налаштування провайдера SMS та типів повідомлень; баланс і приблизна к-сть SMS. */
class MessagingAdmin extends IndexAdmin
{
    private const TURBOSMS_PRICE_PER_SMS = 1.29;
    private const SMSCLUB_PRICE_PER_SMS = 1.29;

    public function fetch(SmsClubProvider $smsClubProvider, TurboSmsProvider $turbosmsProvider, EntityFactory $entityFactory)
    {
        if ($this->request->method('post')) {
            $this->settings->set('sviat__messaging__provider', $this->request->post('provider') === 'smsclub' ? 'smsclub' : 'turbosms');
            $this->settings->set('sviat__messaging__turbosms_token', $this->request->post('turbosms_token'));
            $this->settings->set('sviat__messaging__turbosms_sender', $this->request->post('turbosms_sender'));
            $this->settings->set('sviat__messaging__smsclub_token', $this->request->post('smsclub_token'));
            $this->settings->set('sviat__messaging__smsclub_src_addr', $this->request->post('smsclub_src_addr'));

            $this->settings->set('sviat__messaging__shipped_enabled', $this->request->post('shipped_enabled', 'boolean') ? 1 : 0);
            $this->settings->set('sviat__messaging__arrived_enabled', $this->request->post('arrived_enabled', 'boolean') ? 1 : 0);
            $this->settings->set('sviat__messaging__reminder_2day_enabled', $this->request->post('reminder_2day_enabled', 'boolean') ? 1 : 0);
            $this->settings->set('sviat__messaging__storage_warning_enabled', $this->request->post('storage_warning_enabled', 'boolean') ? 1 : 0);

            $this->postRedirectGet->storeMessageSuccess('updated');
            $this->postRedirectGet->redirect();
        }

        $provider = $this->settings->get('sviat__messaging__provider') ?: 'turbosms';
        $this->design->assign('provider', $provider);
        $this->design->assign('turbosms_token', $this->settings->get('sviat__messaging__turbosms_token'));
        $this->design->assign('turbosms_sender', $this->settings->get('sviat__messaging__turbosms_sender'));
        $this->design->assign('smsclub_token', $this->settings->get('sviat__messaging__smsclub_token'));
        $this->design->assign('smsclub_src_addr', $this->settings->get('sviat__messaging__smsclub_src_addr'));

        $this->design->assign('shipped_enabled', (bool)$this->settings->get('sviat__messaging__shipped_enabled'));
        $this->design->assign('arrived_enabled', (bool)$this->settings->get('sviat__messaging__arrived_enabled'));
        $this->design->assign('reminder_2day_enabled', (bool)$this->settings->get('sviat__messaging__reminder_2day_enabled'));
        $this->design->assign('storage_warning_enabled', (bool)$this->settings->get('sviat__messaging__storage_warning_enabled'));

        $modulesEntity = $entityFactory->get(ModulesEntity::class);
        $novaPoshtaTrackingModule = $modulesEntity->getByVendorModuleName('Sviat', 'NovaPoshtaTracking');
        $isNovaPoshtaTrackingInstalled = !empty($novaPoshtaTrackingModule) && $novaPoshtaTrackingModule->enabled;
        $this->design->assign('is_nova_poshta_tracking_installed', $isNovaPoshtaTrackingInstalled);

        $sampleDocNumber = '20451329330540';
        $this->design->assign('example_shipped_message', MessageTemplates::getMessage(NotificationEventService::TYPE_SHIPPED, ['doc_number' => $sampleDocNumber, 'order_id' => 12345]));
        $this->design->assign('example_arrived_message', MessageTemplates::getMessage(NotificationEventService::TYPE_ARRIVED, ['doc_number' => $sampleDocNumber, 'order_id' => 12345]));
        $this->design->assign('example_reminder_2day_message', MessageTemplates::getMessage(NotificationEventService::TYPE_REMINDER_2DAY, ['doc_number' => $sampleDocNumber, 'order_id' => 12345]));
        $this->design->assign('example_storage_warning_message', MessageTemplates::getMessage(NotificationEventService::TYPE_STORAGE_WARNING, ['doc_number' => $sampleDocNumber, 'order_id' => 12345]));

        if ($provider === 'turbosms') {
            $balance = $turbosmsProvider->getBalance();
            $this->design->assign('turbosms_balance_success', $balance['success']);
            $this->design->assign('turbosms_balance', $balance['balance']);
            $this->design->assign('turbosms_balance_currency', $balance['currency'] ?? 'UAH');
            $this->design->assign('turbosms_balance_error', $balance['error'] ?? null);
            $bal = is_numeric($balance['balance'] ?? null) ? (float)$balance['balance'] : 0.0;
            $this->design->assign('turbosms_approx_sms', $bal > 0 ? (int)floor($bal / self::TURBOSMS_PRICE_PER_SMS) : null);
        } else {
            $this->design->assign('turbosms_balance_success', false);
            $this->design->assign('turbosms_balance', null);
            $this->design->assign('turbosms_balance_currency', null);
            $this->design->assign('turbosms_balance_error', null);
            $this->design->assign('turbosms_approx_sms', null);
        }

        if ($provider === 'smsclub') {
            $balance = $smsClubProvider->getBalance();
            $this->design->assign('smsclub_balance_success', $balance['success']);
            $this->design->assign('smsclub_balance', $balance['balance']);
            $this->design->assign('smsclub_balance_currency', $balance['currency']);
            $this->design->assign('smsclub_balance_error', $balance['error'] ?? null);
            $bal = is_numeric($balance['balance'] ?? null) ? (float)$balance['balance'] : 0.0;
            $this->design->assign('smsclub_approx_sms', $bal > 0 ? (int)floor($bal / self::SMSCLUB_PRICE_PER_SMS) : null);
        } else {
            $this->design->assign('smsclub_balance_success', false);
            $this->design->assign('smsclub_balance', null);
            $this->design->assign('smsclub_balance_currency', null);
            $this->design->assign('smsclub_balance_error', null);
            $this->design->assign('smsclub_approx_sms', null);
        }

        $this->response->setContent($this->design->fetch('messaging_admin.tpl'));
    }
}
