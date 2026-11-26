<?php
/**
 * josra_giftproduct 2.2.0: opción «Cómo sale el regalo» (a precio 0 o a su precio con un vale).
 * Columna del vale en los estados de carrito, hook que vuelve a meter el vale y ajuste por defecto
 * (a precio 0, como hasta ahora).
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_2_0($module)
{
    require_once _PS_MODULE_DIR_ . 'josra_giftproduct/classes/JosraGiftChoice.php';
    if ((int)Configuration::getGlobalValue('JOSRA_GIFT_SCHEMA') >= 220) {
        Configuration::updateGlobalValue('JOSRA_GIFT_SCHEMA', 210);
    }
    if (!JosraGiftChoice::ensureSchema()) {
        return false;
    }
    if (Configuration::get('JOSRA_GIFT_PRICE_MODE') === false) {
        Configuration::updateValue('JOSRA_GIFT_PRICE_MODE', 'zero');
    }

    return $module->registerHooks() && $module->getUpdater()->registerHooks();
}
