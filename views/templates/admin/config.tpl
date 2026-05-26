{*
 * josra_giftproduct - Panel de administración
 * Configuración global + Gestión de reglas + Estadísticas
 *}

<div id="josra-gift-admin" class="josra-gift-admin">

    {* ===== CABECERA ===== *}
    <div class="josra-gift-header panel">
        <div class="panel-heading">
            <i class="icon-gift"></i>
            {l s='Gift Product - josra' mod='josra_giftproduct'}
            <span class="josra-gift-ps-badge badge badge-info">
                PrestaShop {$ps_version} ({$ps_version_branch})
            </span>
        </div>
        <div class="panel-body josra-gift-header__body">
            <p class="help-block">
                {l s='Añade automáticamente un producto de regalo al carrito según reglas de importe o cantidad.' mod='josra_giftproduct'}
            </p>
        </div>
    </div>

    {* ===== TABS ===== *}
    <ul class="nav nav-tabs josra-gift-tabs" id="josra-gift-nav-tabs">
        <li class="active">
            <a href="#tab-rules" data-toggle="tab">
                <i class="icon-list-ul"></i>
                {l s='Reglas de regalo' mod='josra_giftproduct'}
                <span class="badge">{$rules|count}</span>
            </a>
        </li>
        <li>
            <a href="#tab-global" data-toggle="tab">
                <i class="icon-cog"></i>
                {l s='Configuración global' mod='josra_giftproduct'}
            </a>
        </li>
        <li>
            <a href="#tab-stats" data-toggle="tab">
                <i class="icon-bar-chart"></i>
                {l s='Estadísticas' mod='josra_giftproduct'}
            </a>
        </li>
    </ul>

    <div class="tab-content josra-gift-tab-content">

        {* ================================================================
           TAB 1: REGLAS DE REGALO
           ================================================================ *}
        <div class="tab-pane active" id="tab-rules">
            <div class="panel">
                <div class="panel-heading">
                    {l s='Reglas activas' mod='josra_giftproduct'}
                    <button type="button" class="btn btn-success btn-sm pull-right" id="josra-btn-new-rule">
                        <i class="icon-plus"></i>
                        {l s='Nueva regla' mod='josra_giftproduct'}
                    </button>
                </div>
                <div class="panel-body">

                    {if $rules}
                    <table class="table josra-gift-rules-table">
                        <thead>
                            <tr>
                                <th>{l s='Nombre' mod='josra_giftproduct'}</th>
                                <th>{l s='Tipo' mod='josra_giftproduct'}</th>
                                <th>{l s='Tramos' mod='josra_giftproduct'}</th>
                                <th>{l s='Fechas' mod='josra_giftproduct'}</th>
                                <th>{l s='Usos' mod='josra_giftproduct'}</th>
                                <th>{l s='Pedidos' mod='josra_giftproduct'}</th>
                                <th>{l s='Activo' mod='josra_giftproduct'}</th>
                                <th>{l s='Acciones' mod='josra_giftproduct'}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach from=$rules item=rule}
                            <tr data-rule-id="{$rule.id_josra_gift_rule}">
                                <td><strong>{$rule.name|escape:'html':'UTF-8'}</strong></td>
                                <td>
                                    {if $rule.trigger_type == 'amount'}
                                        <span class="badge badge-primary">{l s='Importe' mod='josra_giftproduct'}</span>
                                    {elseif $rule.trigger_type == 'quantity'}
                                        <span class="badge badge-default">{l s='Cantidad' mod='josra_giftproduct'}</span>
                                    {else}
                                        <span class="badge badge-success">{l s='Producto' mod='josra_giftproduct'}</span>
                                    {/if}
                                </td>
                                <td>
                                    {* Aquí podríamos mostrar resumen de tramos - simplificado *}
                                    <span class="label label-info">
                                        {l s='Ver detalle' mod='josra_giftproduct'}
                                    </span>
                                </td>
                                <td>
                                    {if $rule.date_start || $rule.date_end}
                                        <small>
                                            {$rule.date_start|default:'-'} → {$rule.date_end|default:'-'}
                                        </small>
                                    {else}
                                        <small class="text-muted">{l s='Sin límite' mod='josra_giftproduct'}</small>
                                    {/if}
                                </td>
                                <td>
                                    {$rule.uses_count|intval}
                                    {if $rule.max_uses}
                                        / {$rule.max_uses|intval}
                                    {else}
                                        / ∞
                                    {/if}
                                </td>
                                <td>
                                    <strong>{$rule.total_orders|intval}</strong>
                                </td>
                                <td>
                                    <span class="josra-gift-toggle-active" data-id="{$rule.id_josra_gift_rule}">
                                        {if $rule.active}
                                            <span class="label label-success josra-active-label">{l s='Sí' mod='josra_giftproduct'}</span>
                                        {else}
                                            <span class="label label-danger josra-active-label">{l s='No' mod='josra_giftproduct'}</span>
                                        {/if}
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-default btn-xs josra-btn-edit-rule"
                                            data-id="{$rule.id_josra_gift_rule}">
                                        <i class="icon-pencil"></i> {l s='Editar' mod='josra_giftproduct'}
                                    </button>
                                    <button type="button" class="btn btn-danger btn-xs josra-btn-delete-rule"
                                            data-id="{$rule.id_josra_gift_rule}"
                                            data-name="{$rule.name|escape:'html':'UTF-8'}">
                                        <i class="icon-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            {/foreach}
                        </tbody>
                    </table>
                    {else}
                    <div class="josra-gift-empty-rules">
                        <i class="icon-gift josra-gift-empty-icon"></i>
                        <p>{l s='No hay reglas configuradas. Crea la primera.' mod='josra_giftproduct'}</p>
                        <button type="button" class="btn btn-success" id="josra-btn-new-rule-empty">
                            <i class="icon-plus"></i>
                            {l s='Crear primera regla' mod='josra_giftproduct'}
                        </button>
                    </div>
                    {/if}
                </div>
            </div>
        </div>

        {* ================================================================
           TAB 2: CONFIGURACIÓN GLOBAL
           ================================================================ *}
        <div class="tab-pane" id="tab-global">
            <form action="{$form_action|escape:'html':'UTF-8'}" method="post" id="josra-gift-global-form">

                {* BADGE *}
                <div class="panel">
                    <div class="panel-heading">
                        <i class="icon-tag"></i>
                        {l s='Badge de regalo' mod='josra_giftproduct'}
                    </div>
                    <div class="panel-body">

                        {* Texto badge por idioma *}
                        <div class="form-group">
                            <label class="control-label col-lg-3">
                                {l s='Texto del badge' mod='josra_giftproduct'}
                            </label>
                            <div class="col-lg-9">
                                {foreach from=$languages item=lang}
                                <div class="input-group josra-lang-field" {if $lang.id_lang != $id_lang_default}style="display:none"{/if}>
                                    <span class="input-group-addon josra-lang-flag">
                                        <img src="{$smarty.const._PS_BASE_URL_}{$smarty.const.__PS_BASE_URI__}img/l/{$lang.id_lang}.jpg"
                                             alt="{$lang.iso_code|escape:'html':'UTF-8'}" width="16" height="11">
                                        {$lang.iso_code|upper|escape:'html':'UTF-8'}
                                    </span>
                                    <input type="text"
                                           name="JOSRA_GIFT_BADGE_TEXT_{$lang.id_lang}"
                                           value="{$badge_text[$lang.id_lang]|default:'REGALO'|escape:'html':'UTF-8'}"
                                           class="form-control"
                                           maxlength="30"
                                           id="badge-text-{$lang.id_lang}">
                                </div>
                                {/foreach}
                                {if $languages|count > 1}
                                <div class="josra-lang-switcher">
                                    {foreach from=$languages item=lang}
                                    <button type="button" class="btn btn-xs josra-lang-btn
                                        {if $lang.id_lang == $id_lang_default}btn-primary{else}btn-default{/if}"
                                            data-lang="{$lang.id_lang}">
                                        {$lang.iso_code|upper|escape:'html':'UTF-8'}
                                    </button>
                                    {/foreach}
                                </div>
                                {/if}
                            </div>
                        </div>

                        {* Colores *}
                        <div class="form-group">
                            <label class="control-label col-lg-3">
                                {l s='Color de fondo' mod='josra_giftproduct'}
                            </label>
                            <div class="col-lg-3">
                                <div class="josra-color-picker-wrap">
                                    <input type="color"
                                           name="JOSRA_GIFT_BADGE_BG_COLOR"
                                           id="josra-badge-bg"
                                           value="{$badge_bg_color|escape:'html':'UTF-8'}"
                                           class="josra-color-input">
                                    <input type="text"
                                           id="josra-badge-bg-text"
                                           value="{$badge_bg_color|escape:'html':'UTF-8'}"
                                           class="form-control josra-color-text"
                                           maxlength="7">
                                </div>
                            </div>
                            <label class="control-label col-lg-2">
                                {l s='Color del texto' mod='josra_giftproduct'}
                            </label>
                            <div class="col-lg-3">
                                <div class="josra-color-picker-wrap">
                                    <input type="color"
                                           name="JOSRA_GIFT_BADGE_TEXT_COLOR"
                                           id="josra-badge-text-color"
                                           value="{$badge_text_color|escape:'html':'UTF-8'}"
                                           class="josra-color-input">
                                    <input type="text"
                                           id="josra-badge-text-color-text"
                                           value="{$badge_text_color|escape:'html':'UTF-8'}"
                                           class="form-control josra-color-text"
                                           maxlength="7">
                                </div>
                            </div>
                        </div>

                        {* Preview badge *}
                        <div class="form-group">
                            <label class="control-label col-lg-3">
                                {l s='Vista previa' mod='josra_giftproduct'}
                            </label>
                            <div class="col-lg-9">
                                <span id="josra-badge-preview"
                                      class="josra-gift-badge-preview"
                                      style="background-color:{$badge_bg_color|escape:'html':'UTF-8'};color:{$badge_text_color|escape:'html':'UTF-8'};">
                                    {$badge_text[$id_lang_default]|default:'REGALO'|escape:'html':'UTF-8'}
                                </span>
                                <span style="margin-left:10px;font-size:14px;">
                                    {l s='Nombre del producto' mod='josra_giftproduct'}
                                </span>
                            </div>
                        </div>

                    </div>
                </div>

                {* CÁLCULO *}
                <div class="panel">
                    <div class="panel-heading">
                        <i class="icon-calculator"></i>
                        {l s='Modo de cálculo del carrito' mod='josra_giftproduct'}
                    </div>
                    <div class="panel-body">

                        <div class="form-group">
                            <label class="control-label col-lg-3">
                                {l s='Cálculo del importe' mod='josra_giftproduct'}
                            </label>
                            <div class="col-lg-9">
                                <select name="JOSRA_GIFT_CALC_MODE" class="form-control" style="width:auto">
                                    <option value="without_tax" {if $calc_mode == 'without_tax'}selected{/if}>
                                        {l s='Sin IVA (precio base)' mod='josra_giftproduct'}
                                    </option>
                                    <option value="with_tax" {if $calc_mode == 'with_tax'}selected{/if}>
                                        {l s='Con IVA (precio final)' mod='josra_giftproduct'}
                                    </option>
                                </select>
                                <p class="help-block">
                                    {l s='Define cómo se calcula el importe del carrito para activar los tramos de regalo.' mod='josra_giftproduct'}
                                </p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="control-label col-lg-3">
                                {l s='Incluir gastos de envío' mod='josra_giftproduct'}
                            </label>
                            <div class="col-lg-9">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox"
                                               name="JOSRA_GIFT_INCLUDE_SHIPPING"
                                               value="1"
                                               {if $include_shipping}checked{/if}>
                                        {l s='Sumar el coste de envío al importe del carrito para activar reglas' mod='josra_giftproduct'}
                                    </label>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                {* MENSAJE MOTIVACIONAL *}
                <div class="panel">
                    <div class="panel-heading">
                        <i class="icon-comment"></i>
                        {l s='Mensaje motivacional' mod='josra_giftproduct'}
                    </div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label class="control-label col-lg-3">
                                {l s='Texto' mod='josra_giftproduct'}
                            </label>
                            <div class="col-lg-9">
                                {foreach from=$languages item=lang}
                                <div class="input-group josra-lang-field-motiv" {if $lang.id_lang != $id_lang_default}style="display:none"{/if}>
                                    <span class="input-group-addon josra-lang-flag">
                                        <img src="{$smarty.const._PS_BASE_URL_}{$smarty.const.__PS_BASE_URI__}img/l/{$lang.id_lang}.jpg"
                                             alt="{$lang.iso_code|escape:'html':'UTF-8'}" width="16" height="11">
                                        {$lang.iso_code|upper|escape:'html':'UTF-8'}
                                    </span>
                                    <input type="text"
                                           name="JOSRA_GIFT_MOTIVATIONAL_TEXT_{$lang.id_lang}"
                                           value="{$motivational_text[$lang.id_lang]|default:''|escape:'html':'UTF-8'}"
                                           class="form-control"
                                           placeholder="{l s='Te faltan {amount} para conseguir tu regalo' mod='josra_giftproduct'}">
                                </div>
                                {/foreach}
                                <p class="help-block">
                                    {l s='Usa {amount} para mostrar el importe que falta. Deja vacío para no mostrar mensaje.' mod='josra_giftproduct'}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel-footer">
                    <button type="submit" name="submitJosraGiftConfig" class="btn btn-default pull-right">
                        <i class="process-icon-save"></i>
                        {l s='Guardar configuración' mod='josra_giftproduct'}
                    </button>
                </div>

            </form>
        </div>

        {* ================================================================
           TAB 3: ESTADÍSTICAS
           ================================================================ *}
        <div class="tab-pane" id="tab-stats">
            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-bar-chart"></i>
                    {l s='Estadísticas de regalos' mod='josra_giftproduct'}
                </div>
                <div class="panel-body">

                    {* Filtro de fechas *}
                    <div class="row josra-stats-filter">
                        <div class="col-lg-3">
                            <label>{l s='Desde' mod='josra_giftproduct'}</label>
                            <input type="date" id="josra-stats-from" class="form-control">
                        </div>
                        <div class="col-lg-3">
                            <label>{l s='Hasta' mod='josra_giftproduct'}</label>
                            <input type="date" id="josra-stats-to" class="form-control">
                        </div>
                        <div class="col-lg-3" style="padding-top:24px;">
                            <button type="button" class="btn btn-primary" id="josra-btn-load-stats">
                                <i class="icon-search"></i>
                                {l s='Cargar' mod='josra_giftproduct'}
                            </button>
                            <button type="button" class="btn btn-default" id="josra-btn-reset-stats">
                                {l s='Todo el tiempo' mod='josra_giftproduct'}
                            </button>
                        </div>
                    </div>

                    <hr>

                    {* KPIs *}
                    <div class="row josra-stats-kpis" id="josra-stats-kpis">
                        <div class="col-lg-3 col-md-6">
                            <div class="josra-kpi">
                                <div class="josra-kpi__value" id="stat-total-orders">{$stats.total_orders_with_gift}</div>
                                <div class="josra-kpi__label">{l s='Pedidos con regalo' mod='josra_giftproduct'}</div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="josra-kpi">
                                <div class="josra-kpi__value" id="stat-total-value">
                                    {displayPrice price=$stats.total_gifted_value}
                                </div>
                                <div class="josra-kpi__label">{l s='Valor total regalado' mod='josra_giftproduct'}</div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="josra-kpi">
                                <div class="josra-kpi__value josra-kpi__value--sm" id="stat-top-product">
                                    {$stats.top_product.product_name|default:'-'|escape:'html':'UTF-8'}
                                </div>
                                <div class="josra-kpi__label">{l s='Producto más regalado' mod='josra_giftproduct'}</div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="josra-kpi">
                                <div class="josra-kpi__value josra-kpi__value--sm" id="stat-top-rule">
                                    {$stats.top_rule.rule_name|default:'-'|escape:'html':'UTF-8'}
                                </div>
                                <div class="josra-kpi__label">{l s='Regla más activa' mod='josra_giftproduct'}</div>
                            </div>
                        </div>
                    </div>

                    {* Alertas de stock bajo *}
                    {if $stats.low_stock_alerts}
                    <div class="alert alert-warning josra-stock-alert">
                        <i class="icon-warning-sign"></i>
                        <strong>{l s='Stock bajo en productos regalo activos:' mod='josra_giftproduct'}</strong>
                        <ul>
                            {foreach from=$stats.low_stock_alerts item=alert}
                            <li>
                                {l s='Producto ID' mod='josra_giftproduct'} {$alert.id_product}
                                {if $alert.id_product_attribute}
                                    ({l s='combinación' mod='josra_giftproduct'} {$alert.id_product_attribute})
                                {/if}
                                — <strong>{$alert.stock_qty}</strong> {l s='uds.' mod='josra_giftproduct'}
                            </li>
                            {/foreach}
                        </ul>
                    </div>
                    {/if}

                    {* Tabla de últimos regalos *}
                    <h4>{l s='Últimos 10 regalos aplicados' mod='josra_giftproduct'}</h4>
                    <div id="josra-recent-gifts-wrap">
                        {* Se carga por Ajax o en el template con datos iniciales *}
                    </div>

                </div>
            </div>
        </div>

    </div>{* /tab-content *}

</div>{* /josra-gift-admin *}

{* ================================================================
   MODAL: Crear / Editar regla
   ================================================================ *}
<div class="modal fade" id="josra-rule-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="josra-rule-modal-title">
                    {l s='Nueva regla de regalo' mod='josra_giftproduct'}
                </h4>
            </div>
            <div class="modal-body" id="josra-rule-modal-body">
                {* Cargado por JS *}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">
                    {l s='Cancelar' mod='josra_giftproduct'}
                </button>
                <button type="button" class="btn btn-success" id="josra-rule-save-btn">
                    <i class="icon-save"></i>
                    {l s='Guardar regla' mod='josra_giftproduct'}
                </button>
            </div>
        </div>
    </div>
</div>

{* ================================================================
   JavaScript Admin
   ================================================================ *}
<script>
var josraGiftAdmin = {
    ajaxUrl: '{$admin_ajax_url|escape:'javascript':'UTF-8'}',
    editingRuleId: 0,
    levelCount: 0,
};
</script>
<script src="{$module_dir|escape:'javascript':'UTF-8'}views/js/josra_gift_admin.js"></script>
<link rel="stylesheet" href="{$module_dir|escape:'javascript':'UTF-8'}views/css/josra_gift_admin.css">
