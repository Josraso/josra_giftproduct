{* motivational.tpl - Mensaje "te faltan X€ para tu regalo" *}
{if $josra_motiv_text}
<div class="josra-gift-motivational">
    <span class="josra-gift-motivational__icon">&#x1F381;</span>
    {$josra_motiv_text|escape:'html':'UTF-8'}
</div>
{/if}
