{* Bloque del carrito: regalo actual, regalo quitado y lo que falta para el siguiente *}
<div id="josra-gift-block" class="josra-gift-block">
  {if $josra_declined}
    <div class="alert alert-warning josra-gift-declined">
      <span class="josra-gift-declined__text">{l s='Has quitado tu regalo:' mod='josra_giftproduct'} <strong>{$josra_declined|escape:'html':'UTF-8'}</strong></span>
      <button type="button" class="btn btn-primary btn-sm josra-gift-restore">{l s='Recuperar regalo' mod='josra_giftproduct'}</button>
    </div>
  {/if}
  {if $josra_gift}
    <p class="josra-gift-current">&#127873; {l s='Te llevas de regalo:' mod='josra_giftproduct'} <strong>{$josra_gift|escape:'html':'UTF-8'}</strong></p>
  {/if}
  {if $josra_goal}
    <div class="josra-gift-goal">
      <p class="josra-gift-goal__text">&#127873; {$josra_goal.text|escape:'html':'UTF-8'}</p>
      {if $josra_show_progress}
        <div class="josra-gift-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$josra_goal.percent|intval}">
          <span class="josra-gift-progress__bar" style="width:{$josra_goal.percent|intval}%"></span>
        </div>
      {/if}
    </div>
  {/if}
</div>
