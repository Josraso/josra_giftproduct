{*
 * josra_giftproduct - Inline config para frontend JS
 *}
<script>
window.josraGift = {
    ajax_url:        '{$josra_ajax_url|escape:'javascript':'UTF-8'}',
    badge_text:      '{$josra_badge_text|escape:'javascript':'UTF-8'}',
    restore_label:   '{$josra_restore_label|escape:'javascript':'UTF-8'}',
    deleted_warning: '{$josra_deleted_warning|escape:'javascript':'UTF-8'}',
    unlocked_msg:    '{$josra_unlocked_msg|escape:'javascript':'UTF-8'}',
    upgraded_msg:    '{$josra_upgraded_msg|escape:'javascript':'UTF-8'}',
    gift_deleted:    {$josra_gift_deleted|intval}
};
</script>
