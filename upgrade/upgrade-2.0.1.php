<?php
/**
 * josra_giftproduct 2.0.1: conexión con el servidor de actualizaciones de josra.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_1($module)
{
    return $module->getUpdater()->registerHooks();
}
