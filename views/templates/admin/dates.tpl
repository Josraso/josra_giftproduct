<div class="form-inline josra-dates">
  <label for="josra_date_start">{l s='Desde' mod='josra_giftproduct'}</label>
  <input type="date" id="josra_date_start" name="date_start" class="form-control" value="{$josra_date_start|escape:'html':'UTF-8'}">
  &nbsp;
  <label for="josra_date_end">{l s='Hasta' mod='josra_giftproduct'}</label>
  <input type="date" id="josra_date_end" name="date_end" class="form-control" value="{$josra_date_end|escape:'html':'UTF-8'}">
</div>
<p class="help-block">{l s='Vacío = sin límite. El último día está incluido.' mod='josra_giftproduct'}</p>
