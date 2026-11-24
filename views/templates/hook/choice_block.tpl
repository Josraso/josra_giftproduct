{* «Elige tu regalo»: regalos del tramo alcanzado (a elegir) y los del siguiente tramo (bloqueados) *}
<div id="josra-gift-choice" class="jgc" data-where="{$jgc_where|escape:'html':'UTF-8'}">
{if $jgc}
  {if $jgc.notice}<p class="jgc__notice" role="status">{$jgc.notice|escape:'html':'UTF-8'}</p>{/if}

  {foreach from=$jgc.slots item=slot}
    <div class="jgc__slot">
      <p class="jgc__h">
        <span class="jgc__ico" aria-hidden="true">&#127873;</span>
        {if $slot.chosen}{l s='Tu regalo' mod='josra_giftproduct'}{else}{l s='Elige tu regalo' mod='josra_giftproduct'}{/if}
        {if $jgc.multi}<span class="jgc__from">{l s='por pasar de' mod='josra_giftproduct'} {$slot.from|escape:'html':'UTF-8'}</span>{/if}
        <span class="jgc__free" style="background-color:{$jgc_bg|escape:'html':'UTF-8'};color:{$jgc_fg|escape:'html':'UTF-8'};">{l s='Gratis' mod='josra_giftproduct'}</span>
      </p>
      <div class="jgc__row" role="list">
        {foreach from=$slot.cards item=c}
          <div class="jgc__card{if $c.chosen} is-on{/if}" role="listitem">
            <button type="button" class="jgc__pick" data-jgc-choose data-slot="{$slot.slot|escape:'html':'UTF-8'}" data-level="{$c.id_level|intval}" data-attr="{$c.attr|intval}"
                    aria-pressed="{if $c.chosen}true{else}false{/if}" title="{if $c.chosen}{l s='Quitar este regalo' mod='josra_giftproduct'}{else}{l s='Elegir este regalo' mod='josra_giftproduct'}{/if}">
              <span class="jgc__img">{if $c.image}<img src="{$c.image|escape:'html':'UTF-8'}" alt="" loading="lazy">{/if}{if $c.chosen}<span class="jgc__check" aria-hidden="true">&#10003;</span>{/if}</span>
              <span class="jgc__name">{if $c.qty > 1}{$c.qty|intval} x {/if}{$c.name|escape:'html':'UTF-8'}</span>
              {if $c.value}<span class="jgc__value">{$c.value|escape:'html':'UTF-8'}</span>{/if}
              <span class="jgc__cta">{if $c.chosen}{l s='Elegido' mod='josra_giftproduct'}{else}{l s='Elegir' mod='josra_giftproduct'}{/if}</span>
            </button>
            {if $c.chosen && $c.n_options > 1}
              <select class="jgc__combo" data-jgc-combo data-slot="{$slot.slot|escape:'html':'UTF-8'}" data-level="{$c.id_level|intval}" aria-label="{l s='Elige la combinación' mod='josra_giftproduct'}">
                {foreach from=$c.options key=ida item=oname}
                  <option value="{$ida|intval}"{if $ida == $c.attr} selected{/if}>{$oname|escape:'html':'UTF-8'}</option>
                {/foreach}
              </select>
            {elseif $c.chosen && $c.attr}
              <span class="jgc__combo-one">{$c.option_name|escape:'html':'UTF-8'}</span>
            {/if}
          </div>
        {/foreach}
      </div>
    </div>
  {/foreach}

  {if $jgc.next}
    <div class="jgc__next">
      <p class="jgc__h jgc__h--next">
        <span class="jgc__ico" aria-hidden="true">&#128274;</span>
        {l s='Añade' mod='josra_giftproduct'} <strong>{$jgc.next.missing|escape:'html':'UTF-8'}</strong>
        {if $jgc.slots}{l s='más y podrás elegir uno de estos:' mod='josra_giftproduct'}{else}{l s='más y elige uno de estos regalos:' mod='josra_giftproduct'}{/if}
      </p>
      <div class="jgc__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$jgc.next.percent|intval}"><span style="width:{$jgc.next.percent|intval}%"></span></div>
      <div class="jgc__row jgc__row--locked" role="list">
        {foreach from=$jgc.next.cards item=c}
          <div class="jgc__card is-locked" role="listitem">
            <span class="jgc__pick">
              <span class="jgc__img">{if $c.image}<img src="{$c.image|escape:'html':'UTF-8'}" alt="" loading="lazy">{/if}</span>
              <span class="jgc__name">{if $c.qty > 1}{$c.qty|intval} x {/if}{$c.name|escape:'html':'UTF-8'}</span>
              {if $c.value}<span class="jgc__value">{$c.value|escape:'html':'UTF-8'}</span>{/if}
              <span class="jgc__cta">{l s='Desde' mod='josra_giftproduct'} {$jgc.next.from|escape:'html':'UTF-8'}</span>
            </span>
          </div>
        {/foreach}
        {if $jgc.next.more}<div class="jgc__card jgc__more" role="listitem"><span>+{$jgc.next.more|intval}</span></div>{/if}
      </div>
    </div>
  {/if}
{/if}
</div>
