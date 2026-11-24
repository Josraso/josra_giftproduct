<?php
/**
 * josra_giftproduct 2.1.0: reglas «el cliente elige el regalo» (tabla de regalos elegidos y columnas nuevas).
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_1_0($module)
{
    require_once _PS_MODULE_DIR_ . 'josra_giftproduct/classes/JosraGiftChoice.php';
    Configuration::updateGlobalValue('JOSRA_GIFT_SCHEMA', 0);
    if (!JosraGiftChoice::ensureSchema()) {
        return false;
    }

    return $module->registerHooks() && $module->getUpdater()->registerHooks();
}
