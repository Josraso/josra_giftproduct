{* order_detail.tpl - Muestra info del regalo en el historial/detalle de pedido del cliente *}
{if $josra_gift_log}
<div class="josra-gift-order-info">
    <span class="josra-gift-badge">{$josra_badge_text|escape:'html':'UTF-8'}</span>
    <span class="josra-gift-order-info__name">
        {$josra_gift_log.product_name|escape:'html':'UTF-8'}
    </span>
    <span class="josra-gift-price-zero">0,00 €</span>
    <small class="text-muted">
        ({l s='Valor real' mod='josra_giftproduct'}: {displayPrice price=$josra_gift_log.original_price})
    </small>
</div>
{/if}
