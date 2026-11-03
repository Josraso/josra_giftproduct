{* Tramos de la regla: mínimo, producto de regalo, combinación y unidades *}
<div class="josra-levels" data-currency="{$josra_currency_sign|escape:'html':'UTF-8'}">
  <table class="table josra-levels-table">
    <thead>
      <tr>
        <th class="josra-col-threshold"><span class="josra-threshold-label"></span></th>
        <th>{l s='Producto de regalo' mod='josra_giftproduct'}</th>
        <th>{l s='Combinación' mod='josra_giftproduct'}</th>
        <th class="josra-col-qty">{l s='Uds.' mod='josra_giftproduct'}</th>
        <th class="josra-col-remove"></th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$josra_levels item=lv key=i}
        <tr class="josra-level">
          <td class="josra-col-threshold">
            <input type="text" inputmode="decimal" class="form-control josra-threshold" name="josra_levels[{$i|intval}][trigger_value]" value="{$lv.trigger_display|escape:'html':'UTF-8'}">
          </td>
          <td>
            <div class="josra-picker">
              <input type="hidden" class="josra-picker-id" name="josra_levels[{$i|intval}][id_product]" value="{$lv.id_product|intval}">
              <input type="text" class="form-control josra-picker-input" name="josra_levels[{$i|intval}][product_name]" autocomplete="off" value="{$lv.product_name|escape:'html':'UTF-8'}" placeholder="{l s='Escribe nombre, referencia o ID' mod='josra_giftproduct'}">
              <ul class="dropdown-menu josra-picker-results"></ul>
            </div>
          </td>
          <td>
            <select class="form-control josra-combination" name="josra_levels[{$i|intval}][id_product_attribute]"{if !$lv.combinations} style="display:none"{/if}>
              <option value="0">{l s='Elige combinación' mod='josra_giftproduct'}</option>
              {foreach from=$lv.combinations item=c}
                <option value="{$c.id|intval}"{if $c.id == $lv.id_product_attribute} selected{/if}>{$c.name|escape:'html':'UTF-8'}</option>
              {/foreach}
            </select>
            <span class="text-muted josra-no-combination"{if $lv.combinations} style="display:none"{/if}>—</span>
          </td>
          <td><input type="number" min="1" step="1" class="form-control josra-qty" name="josra_levels[{$i|intval}][gift_qty]" value="{$lv.gift_qty|intval}"></td>
          <td class="text-right"><button type="button" class="btn btn-default josra-level-remove" title="{l s='Quitar tramo' mod='josra_giftproduct'}"><i class="icon-trash"></i></button></td>
        </tr>
      {/foreach}
    </tbody>
  </table>
  <button type="button" class="btn btn-default josra-level-add"><i class="icon-plus-sign"></i> {l s='Añadir tramo' mod='josra_giftproduct'}</button>
  <p class="help-block josra-help-amount">{l s='Se da el regalo del tramo más alto alcanzado (solo uno). Si un regalo se agota, se da el del tramo anterior.' mod='josra_giftproduct'}</p>
  <p class="help-block josra-help-product">{l s='Regalo que se añade cuando el carrito tiene el producto indicado arriba.' mod='josra_giftproduct'}</p>

  <script type="text/template" id="josra-level-template">
    <tr class="josra-level">
      <td class="josra-col-threshold"><input type="text" inputmode="decimal" class="form-control josra-threshold" name="josra_levels[__I__][trigger_value]" value=""></td>
      <td>
        <div class="josra-picker">
          <input type="hidden" class="josra-picker-id" name="josra_levels[__I__][id_product]" value="0">
          <input type="text" class="form-control josra-picker-input" name="josra_levels[__I__][product_name]" autocomplete="off" value="" placeholder="{l s='Escribe nombre, referencia o ID' mod='josra_giftproduct'}">
          <ul class="dropdown-menu josra-picker-results"></ul>
        </div>
      </td>
      <td>
        <select class="form-control josra-combination" name="josra_levels[__I__][id_product_attribute]" style="display:none"><option value="0">{l s='Elige combinación' mod='josra_giftproduct'}</option></select>
        <span class="text-muted josra-no-combination">—</span>
      </td>
      <td><input type="number" min="1" step="1" class="form-control josra-qty" name="josra_levels[__I__][gift_qty]" value="1"></td>
      <td class="text-right"><button type="button" class="btn btn-default josra-level-remove" title="{l s='Quitar tramo' mod='josra_giftproduct'}"><i class="icon-trash"></i></button></td>
    </tr>
  </script>
</div>
