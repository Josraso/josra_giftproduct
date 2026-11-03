{* Regalo del pedido en la ficha del back-office *}
{if $josra_card}
<div class="card mt-2">
  <div class="card-header">
    <h3 class="card-header-title">&#127873; {l s='Regalo' mod='josra_giftproduct'}</h3>
  </div>
  <div class="card-body">
{else}
<div class="panel">
  <div class="panel-heading"><i class="icon-gift"></i> {l s='Regalo' mod='josra_giftproduct'}</div>
  <div>
{/if}
    <p>
      <span class="josra-gift-badge" style="display:inline-block;padding:1px 6px;border-radius:3px;font-weight:700;font-size:11px;background-color:{$josra_badge_bg|escape:'html':'UTF-8'};color:{$josra_badge_fg|escape:'html':'UTF-8'};">{$josra_badge|escape:'html':'UTF-8'}</span>
      <strong>{$josra_log.gift_qty|intval} x {$josra_log.product_name|escape:'html':'UTF-8'}</strong>
      {if $josra_log.status != 'valid'}<span class="label label-danger badge badge-danger">{l s='Anulado' mod='josra_giftproduct'}</span>{/if}
    </p>
    <dl class="dl-horizontal mb-0">
      <dt>{l s='Regla' mod='josra_giftproduct'}</dt><dd>{$josra_log.rule_name|escape:'html':'UTF-8'}</dd>
      <dt>{l s='Valor regalado' mod='josra_giftproduct'}</dt><dd>{$josra_total_price|escape:'html':'UTF-8'} <small class="text-muted">({$josra_unit_price|escape:'html':'UTF-8'} / {l s='ud. sin IVA' mod='josra_giftproduct'})</small></dd>
    </dl>
  </div>
</div>
