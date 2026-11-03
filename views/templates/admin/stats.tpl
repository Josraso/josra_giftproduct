{* Estadísticas de regalos *}
<div class="panel josra-stats">
  <div class="panel-heading"><i class="icon-bar-chart"></i> {l s='Estadísticas de regalos' mod='josra_giftproduct'}</div>

  <form method="post" action="{$josra_stats_url|escape:'html':'UTF-8'}" class="form-inline josra-stats-filter">
    <label>{l s='Desde' mod='josra_giftproduct'}</label>
    <input type="date" name="josra_from" class="form-control" value="{$josra_from|escape:'html':'UTF-8'}">
    <label>{l s='Hasta' mod='josra_giftproduct'}</label>
    <input type="date" name="josra_to" class="form-control" value="{$josra_to|escape:'html':'UTF-8'}">
    <button type="submit" class="btn btn-default"><i class="icon-filter"></i> {l s='Filtrar' mod='josra_giftproduct'}</button>
    {if $josra_from || $josra_to}<a class="btn btn-link" href="{$josra_stats_url|escape:'html':'UTF-8'}">{l s='Quitar filtro' mod='josra_giftproduct'}</a>{/if}
  </form>

  <div class="row josra-kpis">
    <div class="col-sm-3 text-center"><p class="josra-kpi-value">{$josra_stats.orders|intval}</p><p class="text-muted">{l s='Pedidos con regalo' mod='josra_giftproduct'}</p></div>
    <div class="col-sm-3 text-center"><p class="josra-kpi-value">{$josra_stats.units|intval}</p><p class="text-muted">{l s='Unidades regaladas' mod='josra_giftproduct'}</p></div>
    <div class="col-sm-3 text-center"><p class="josra-kpi-value">{$josra_stats_value|escape:'html':'UTF-8'}</p><p class="text-muted">{l s='Valor regalado (sin IVA)' mod='josra_giftproduct'}</p></div>
    <div class="col-sm-3 text-center"><p class="josra-kpi-value josra-kpi-text">{if $josra_stats.top_product}{$josra_stats.top_product|escape:'html':'UTF-8'}{else}—{/if}</p><p class="text-muted">{l s='Regalo más entregado' mod='josra_giftproduct'}</p></div>
  </div>

  {if $josra_low_stock}
    <div class="alert alert-warning">
      <strong>{l s='Regalos con poco stock:' mod='josra_giftproduct'}</strong>
      <ul>
        {foreach from=$josra_low_stock item=ls}
          <li>{$ls.name|escape:'html':'UTF-8'} — {$ls.quantity|intval} {l s='uds.' mod='josra_giftproduct'} <span class="text-muted">({$ls.rule_name|escape:'html':'UTF-8'})</span></li>
        {/foreach}
      </ul>
    </div>
  {/if}

  <h4>{l s='Últimos regalos entregados' mod='josra_giftproduct'}</h4>
  {if $josra_recent}
    <table class="table">
      <thead>
        <tr>
          <th>{l s='Pedido' mod='josra_giftproduct'}</th>
          <th>{l s='Fecha' mod='josra_giftproduct'}</th>
          <th>{l s='Regalo' mod='josra_giftproduct'}</th>
          <th class="text-center">{l s='Uds.' mod='josra_giftproduct'}</th>
          <th class="text-right">{l s='Valor' mod='josra_giftproduct'}</th>
          <th>{l s='Regla' mod='josra_giftproduct'}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$josra_recent item=r}
          <tr>
            <td><a href="{$r.order_url|escape:'html':'UTF-8'}">{if $r.reference}{$r.reference|escape:'html':'UTF-8'}{else}#{$r.id_order|intval}{/if}</a></td>
            <td>{$r.date|escape:'html':'UTF-8'}</td>
            <td>{$r.product_name|escape:'html':'UTF-8'}</td>
            <td class="text-center">{$r.gift_qty|intval}</td>
            <td class="text-right">{$r.value|escape:'html':'UTF-8'}</td>
            <td>{$r.rule_name|escape:'html':'UTF-8'}</td>
            <td>{if $r.status != 'valid'}<span class="label label-danger badge badge-danger">{l s='Anulado' mod='josra_giftproduct'}</span>{/if}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  {else}
    <p class="text-muted">{l s='Todavía no se ha entregado ningún regalo en este periodo.' mod='josra_giftproduct'}</p>
  {/if}
</div>
