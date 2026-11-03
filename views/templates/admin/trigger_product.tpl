<div class="josra-picker josra-trigger-picker" style="max-width:520px">
  <input type="hidden" name="id_trigger_product" class="josra-picker-id" value="{$josra_trigger_product_id|intval}">
  <div class="input-group">
    <span class="input-group-addon input-group-text"><i class="icon-search"></i></span>
    <input type="text" class="form-control josra-picker-input" autocomplete="off"
           value="{$josra_trigger_product_name|escape:'html':'UTF-8'}"
           placeholder="{l s='Escribe nombre, referencia o ID' mod='josra_giftproduct'}">
  </div>
  <ul class="dropdown-menu josra-picker-results"></ul>
</div>
