{* order_gift_info.tpl - Panel regalo en detalle de pedido del backoffice *}
{if $josra_gift_log}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-gift"></i>
        {l s='Producto de regalo aplicado' mod='josra_giftproduct'}
    </div>
    <div class="panel-body">
        <div class="row">
            <div class="col-md-6">
                <table class="table">
                    <tr>
                        <th>{l s='Producto' mod='josra_giftproduct'}</th>
                        <td>
                            <span class="josra-gift-badge"
                                  style="background-color:#e74c3c;color:#fff;
                                         padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;">
                                {$josra_badge_text|escape:'html':'UTF-8'}
                            </span>
                            <strong>{$josra_gift_log.product_name|escape:'html':'UTF-8'}</strong>
                        </td>
                    </tr>
                    <tr>
                        <th>{l s='Regla aplicada' mod='josra_giftproduct'}</th>
                        <td>{$josra_gift_log.rule_name|escape:'html':'UTF-8'}</td>
                    </tr>
                    <tr>
                        <th>{l s='Precio facturado' mod='josra_giftproduct'}</th>
                        <td class="josra-gift-price-zero" style="color:#27ae60;font-weight:700;">0,00 €</td>
                    </tr>
                    <tr>
                        <th>{l s='Valor real del producto' mod='josra_giftproduct'}</th>
                        <td>{$josra_gift_original_price|escape:'html':'UTF-8'}</td>
                    </tr>
                    <tr>
                        <th>{l s='Fecha aplicación' mod='josra_giftproduct'}</th>
                        <td>{$josra_gift_log.date_add|escape:'html':'UTF-8'}</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
{/if}
