<?php
/**
 * josra_giftproduct - Script de upgrade a v1.0.0
 * Se ejecuta automáticamente al actualizar el módulo desde el backoffice.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_0(Module $module): bool
{
    // v1.0.0 es la instalación inicial, no requiere upgrade.
    return true;
}
