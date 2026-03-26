{$meta_title = $btr->sviat_messaging_title scope=global}

<div class="row">
    <div class="col-lg-12 col-md-12">
        <div class="heading_page">{$btr->sviat_messaging_title|escape}</div>
    </div>
</div>

{if $message_success}
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="alert alert--center alert--icon alert--success">
                <div class="alert__content">
                    <div class="alert__title">
                        {if $message_success == 'updated'}
                            {$btr->general_settings_saved|escape}
                        {/if}
                    </div>
                </div>
            </div>
        </div>
    </div>
{/if}

{if !$is_nova_poshta_tracking_installed}
<div class="row d_flex">
    <div class="col-lg-12 col-md-12">
        <div class="alert alert--icon">
            <div class="alert__content">
                <div class="alert__title">{$btr->sviat_messaging_np_tracking_required_title|escape}</div>
                <p>{$btr->sviat_messaging_np_tracking_required_description|escape}</p>
            </div>
        </div>
    </div>
</div>
{/if}

<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="session_id" value="{$smarty.session.id}">

    <div class="row">
        <div class="col-lg-6 col-md-12">
            <div class="boxed fn_toggle_wrap">
                <div class="heading_box">
                    <span>{$btr->sviat_messaging_provider_title|escape}</span>
                </div>
                <div class="toggle_body_wrap on">
                    <div class="heading_label">
                        <span>{$btr->sviat_messaging_provider_select|escape}</span>
                    </div>
                    <select name="provider" id="messaging_provider" class="selectpicker form-control">
                        <option value="turbosms" {if $provider == 'turbosms'}selected{/if}>TurboSMS</option>
                        <option value="smsclub" {if $provider == 'smsclub'}selected{/if}>SMS Club</option>
                    </select>

                    <div id="messaging_turbosms_block" class="mt-2" {if $provider != 'turbosms'}style="display:none"{/if}>
                        <div class="heading_label">
                            <span>{$btr->sviat_messaging_turbosms_token|escape}</span>
                            <i class="fn_tooltips" title="{$btr->sviat_messaging_turbosms_token_hint|escape}">
                                {include file='svg_icon.tpl' svgId='icon_tooltips'}
                            </i>
                        </div>
                        <input type="text" name="turbosms_token" class="form-control mb-1" value="{$turbosms_token|escape}" placeholder="токен з особистого кабінету TurboSMS">
                        <div class="heading_label">
                            <span>{$btr->sviat_messaging_turbosms_sender|escape}</span>
                        </div>
                        <input type="text" name="turbosms_sender" class="form-control mb-1" value="{$turbosms_sender|escape}" placeholder="TurboSMS">
                        {if $provider == 'turbosms'}
                        <div class="mt-2">
                            {if $turbosms_balance_success}
                                <p class="mb-0"><b>{$btr->sviat_messaging_turbosms_balance|escape}:</b> {$turbosms_balance|escape} {$turbosms_balance_currency|escape}</p>
                                {if $turbosms_approx_sms !== null}
                                    <p class="mb-0 text-muted small">{$btr->sviat_messaging_approx_sms|escape}: ~{$turbosms_approx_sms|escape}</p>
                                {/if}
                            {elseif $turbosms_balance_error}
                                <p class="mb-0 text-danger">{$btr->sviat_messaging_turbosms_balance|escape}: {$turbosms_balance_error|escape}</p>
                            {/if}
                        </div>
                        {/if}
                    </div>

                    <div id="messaging_smsclub_block" class="mt-2" {if $provider != 'smsclub'}style="display:none"{/if}>
                        <div class="heading_label">
                            <span>{$btr->sviat_messaging_smsclub_token|escape}</span>
                            <i class="fn_tooltips" title="{$btr->sviat_messaging_smsclub_token_hint|escape}">
                                {include file='svg_icon.tpl' svgId='icon_tooltips'}
                            </i>
                        </div>
                        <input type="text" name="smsclub_token" class="form-control mb-1" value="{$smsclub_token|escape}" placeholder="Bearer токен з профілю SMS Club">
                        <div class="heading_label">
                            <span>{$btr->sviat_messaging_smsclub_src_addr|escape}</span>
                        </div>
                        <input type="text" name="smsclub_src_addr" class="form-control mb-1" value="{$smsclub_src_addr|escape}" placeholder="VashZakaz">
                        {if $provider == 'smsclub'}
                        <div class="mt-2">
                            {if $smsclub_balance_success}
                                <p class="mb-0"><b>{$btr->sviat_messaging_smsclub_balance|escape}:</b> {$smsclub_balance|escape} {$smsclub_balance_currency|escape}</p>
                                {if $smsclub_approx_sms !== null}
                                    <p class="mb-0 text-muted small">{$btr->sviat_messaging_approx_sms|escape}: ~{$smsclub_approx_sms|escape}</p>
                                {/if}
                            {elseif $smsclub_balance_error}
                                <p class="mb-0 text-danger">{$btr->sviat_messaging_smsclub_balance|escape}: {$smsclub_balance_error|escape}</p>
                            {/if}
                        </div>
                        {/if}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6 col-md-12">
            <div class="boxed">
                <div class="heading_box">
                    <span>{$btr->sviat_messaging_docs_title|escape}</span>
                </div>
                <div class="toggle_body_wrap on">
                    <p><a href="https://turbosms.ua/ua/api.html" target="_blank" rel="noopener">TurboSMS API</a> — токен у налаштуваннях API, HTTP API.</p>
                    <p class="mb-0"><a href="https://smsclub.mobi/api/" target="_blank" rel="noopener">SMS Club API</a> — токен у розділі Профіль, JSON API, Bearer.</p>
                </div>
            </div>
        </div>
    </div>

    {* Блоки типів SMS — приклади як у Telegram *}
    <div class="row mt-2">
        <div class="col-lg-3 col-md-12">
            <div class="boxed fn_toggle_wrap">
                <div class="heading_box heading_box--switch-right">
                    <span>{$btr->sviat_messaging_type_shipped|escape}</span>
                    <label class="switch switch-default">
                        <input class="switch-input" name="shipped_enabled" value="1" type="checkbox" {if $shipped_enabled}checked{/if}>
                        <span class="switch-label"></span>
                        <span class="switch-handle"></span>
                    </label>
                </div>
                <div class="toggle_body_wrap on">
                    <p class="text-muted small mb-1">{$btr->sviat_messaging_type_shipped_desc|escape}</p>
                    <div class="heading_label"><span>{$btr->sviat_messaging_example_title|escape}</span></div>
                    <div class="messaging_sms_preview">{$example_shipped_message|escape}</div>
                    <p class="text-muted small mt-h">{$btr->sviat_messaging_example_chars|escape}: {$example_shipped_message|count_characters:true}</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-12">
            <div class="boxed fn_toggle_wrap">
                <div class="heading_box heading_box--switch-right">
                    <span>{$btr->sviat_messaging_type_arrived|escape}</span>
                    <label class="switch switch-default">
                        <input class="switch-input" name="arrived_enabled" value="1" type="checkbox" {if $arrived_enabled}checked{/if}>
                        <span class="switch-label"></span>
                        <span class="switch-handle"></span>
                    </label>
                </div>
                <div class="toggle_body_wrap on">
                    <p class="text-muted small mb-1">{$btr->sviat_messaging_type_arrived_desc|escape}</p>
                    <div class="heading_label"><span>{$btr->sviat_messaging_example_title|escape}</span></div>
                    <div class="messaging_sms_preview">{$example_arrived_message|escape}</div>
                    <p class="text-muted small mt-h">{$btr->sviat_messaging_example_chars|escape}: {$example_arrived_message|count_characters:true}</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-12">
            <div class="boxed fn_toggle_wrap">
                <div class="heading_box heading_box--switch-right">
                    <span>{$btr->sviat_messaging_type_reminder_2day|escape}</span>
                    <label class="switch switch-default">
                        <input class="switch-input" name="reminder_2day_enabled" value="1" type="checkbox" {if $reminder_2day_enabled}checked{/if}>
                        <span class="switch-label"></span>
                        <span class="switch-handle"></span>
                    </label>
                </div>
                <div class="toggle_body_wrap on">
                    <p class="text-muted small mb-1">{$btr->sviat_messaging_type_reminder_2day_desc|escape}</p>
                    <div class="heading_label"><span>{$btr->sviat_messaging_example_title|escape}</span></div>
                    <div class="messaging_sms_preview">{$example_reminder_2day_message|escape}</div>
                    <p class="text-muted small mt-h">{$btr->sviat_messaging_example_chars|escape}: {$example_reminder_2day_message|count_characters:true}</p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-12">
            <div class="boxed fn_toggle_wrap">
                <div class="heading_box heading_box--switch-right">
                    <span>{$btr->sviat_messaging_type_storage_warning|escape}</span>
                    <label class="switch switch-default">
                        <input class="switch-input" name="storage_warning_enabled" value="1" type="checkbox" {if $storage_warning_enabled}checked{/if}>
                        <span class="switch-label"></span>
                        <span class="switch-handle"></span>
                    </label>
                </div>
                <div class="toggle_body_wrap on">
                    <p class="text-muted small mb-1">{$btr->sviat_messaging_type_storage_warning_desc|escape}</p>
                    <div class="heading_label"><span>{$btr->sviat_messaging_example_title|escape}</span></div>
                    <div class="messaging_sms_preview">{$example_storage_warning_message|escape}</div>
                    <p class="text-muted small mt-h">{$btr->sviat_messaging_example_chars|escape}: {$example_storage_warning_message|count_characters:true}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-lg-12 col-md-12">
            <button type="submit" class="btn btn_small btn_blue float-md-right">
                {include file='svg_icon.tpl' svgId='checked'}
                <span>{$btr->general_apply|escape}</span>
            </button>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var provider = document.getElementById('messaging_provider');
    var turbosmsBlock = document.getElementById('messaging_turbosms_block');
    var smsclubBlock = document.getElementById('messaging_smsclub_block');
    if (provider && turbosmsBlock && smsclubBlock) {
        function toggle() {
            var v = provider.value;
            turbosmsBlock.style.display = v === 'turbosms' ? '' : 'none';
            smsclubBlock.style.display = v === 'smsclub' ? '' : 'none';
        }
        provider.addEventListener('change', toggle);
        toggle();
    }
});
</script>
