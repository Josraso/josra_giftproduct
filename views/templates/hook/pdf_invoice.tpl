{* pdf_invoice.tpl - Info regalo en PDF de factura *}
{if $josra_gift_log}
<table style="width:100%;border-collapse:collapse;font-size:11px;margin-top:4px;">
    <tr>
        <td style="padding:3px 6px;background:#f5f5f5;font-weight:bold;">
            [{$josra_badge_text|escape:'html':'UTF-8'}]
            {$josra_gift_log.product_name|escape:'html':'UTF-8'}
        </td>
        <td style="padding:3px 6px;text-align:right;background:#f5f5f5;">
            0,00 €
        </td>
    </tr>
</table>
{/if}
