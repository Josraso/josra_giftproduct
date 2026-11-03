<?php
/**
 * Actualización a 2.0.0 desde 1.0.x / 1.1.x.
 * - Nuevas tablas y columnas (sin sintaxis exclusiva de MariaDB).
 * - Los productos de cada tramo pasan a la tabla de tramos.
 * - Los regalos que ya hay en carritos abiertos se adoptan (no se cobran).
 * - Hooks nuevos, fuera los que no existen y ficheros antiguos (incluido el log público).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function josra_gift_table_exists($table)
{
    return (bool)Db::getInstance()->executeS(
        'SHOW TABLES LIKE \'' . pSQL(_DB_PREFIX_ . $table) . '\''
    );
}

function josra_gift_column_exists($table, $column)
{
    return (bool)Db::getInstance()->executeS(
        'SHOW COLUMNS FROM `' . _DB_PREFIX_ . $table . '` LIKE \'' . pSQL($column) . '\''
    );
}

function josra_gift_add_column($table, $column, $definition)
{
    if (josra_gift_table_exists($table) && !josra_gift_column_exists($table, $column)) {
        Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . $table . '` ADD `' . $column . '` ' . $definition);
    }
}

function upgrade_module_2_0_0($module)
{
    $db = Db::getInstance();
    $p = _DB_PREFIX_;

    // 1. Tablas nuevas (CREATE TABLE IF NOT EXISTS).
    if (!$module->installDb()) {
        return false;
    }

    // 2. Reglas.
    josra_gift_add_column('josra_gift_rule', 'id_trigger_product', 'INT(10) UNSIGNED NOT NULL DEFAULT 0');
    josra_gift_add_column('josra_gift_rule', 'trigger_min_qty', 'INT(10) UNSIGNED NOT NULL DEFAULT 1');
    $db->execute('UPDATE `' . $p . 'josra_gift_rule` SET `max_uses` = 0 WHERE `max_uses` IS NULL');
    $db->execute('ALTER TABLE `' . $p . 'josra_gift_rule`
        MODIFY `trigger_type` VARCHAR(16) NOT NULL DEFAULT \'amount\',
        MODIFY `calc_mode` VARCHAR(16) NOT NULL DEFAULT \'with_tax\',
        MODIFY `max_uses` INT(10) UNSIGNED NOT NULL DEFAULT 0');
    $db->execute('UPDATE `' . $p . 'josra_gift_rule` SET `date_start` = NULL WHERE `date_start` = \'0000-00-00\'');
    $db->execute('UPDATE `' . $p . 'josra_gift_rule` SET `date_end` = NULL WHERE `date_end` = \'0000-00-00\'');

    // 3. Tramos: el producto pasa a la propia tabla de tramos.
    josra_gift_add_column('josra_gift_rule_level', 'id_product', 'INT(10) UNSIGNED NOT NULL DEFAULT 0');
    josra_gift_add_column('josra_gift_rule_level', 'id_product_attribute', 'INT(10) UNSIGNED NOT NULL DEFAULT 0');
    josra_gift_add_column('josra_gift_rule_level', 'gift_qty', 'INT(10) UNSIGNED NOT NULL DEFAULT 1');
    if (josra_gift_table_exists('josra_gift_rule_product')) {
        $hasQty = josra_gift_column_exists('josra_gift_rule_product', 'gift_qty');
        $db->execute('UPDATE `' . $p . 'josra_gift_rule_level` l
            INNER JOIN `' . $p . 'josra_gift_rule_product` rp
                    ON rp.`id_josra_gift_rule_level` = l.`id_josra_gift_rule_level`
            SET l.`id_product` = rp.`id_product`,
                l.`id_product_attribute` = rp.`id_product_attribute`'
            . ($hasQty ? ', l.`gift_qty` = IF(rp.`gift_qty` > 0, rp.`gift_qty`, 1)' : ''));
        $db->execute('DROP TABLE `' . $p . 'josra_gift_rule_product`');
    }

    // 4. Restricciones y registro de pedidos.
    $db->execute('ALTER TABLE `' . $p . 'josra_gift_rule_restriction` MODIFY `restriction_type` VARCHAR(16) NOT NULL');
    josra_gift_add_column('josra_gift_order_log', 'gift_qty', 'INT(10) UNSIGNED NOT NULL DEFAULT 1');
    josra_gift_add_column('josra_gift_order_log', 'status', 'VARCHAR(16) NOT NULL DEFAULT \'valid\'');

    // 5. Regalos de versiones anteriores en carritos abiertos: se adoptan para
    //    que no pasen a cobrarse (el precio se convierte al nuevo formato al tocar el carrito).
    $legacy = $db->executeS('SELECT sp.`id_specific_price`, sp.`id_cart`, sp.`id_product`, sp.`id_product_attribute`
        FROM `' . $p . 'specific_price` sp
        LEFT JOIN `' . $p . 'orders` o ON o.`id_cart` = sp.`id_cart`
        WHERE sp.`id_cart` > 0 AND sp.`id_specific_price_rule` = 0 AND o.`id_order` IS NULL
          AND sp.`id_product` IN (SELECT `id_product` FROM `' . $p . 'josra_gift_rule_level`)');
    foreach ((array)$legacy as $sp) {
        if (JosraGiftCart::get((int)$sp['id_cart'])) {
            continue;
        }
        $level = $db->getRow('SELECT `id_josra_gift_rule_level`, `id_josra_gift_rule`, `gift_qty`
            FROM `' . $p . 'josra_gift_rule_level`
            WHERE `id_product` = ' . (int)$sp['id_product'] . '
              AND `id_product_attribute` = ' . (int)$sp['id_product_attribute']);
        if (!$level) {
            continue;
        }
        $state = JosraGiftCart::blank((int)$sp['id_cart']);
        $state['id_josra_gift_rule'] = (int)$level['id_josra_gift_rule'];
        $state['id_josra_gift_rule_level'] = (int)$level['id_josra_gift_rule_level'];
        $state['id_product'] = (int)$sp['id_product'];
        $state['id_product_attribute'] = (int)$sp['id_product_attribute'];
        $state['gift_qty'] = max(1, (int)$level['gift_qty']);
        $state['in_cart'] = 1;
        $state['id_specific_price'] = (int)$sp['id_specific_price'];
        JosraGiftCart::save($state);
    }

    // 6. Hooks: fuera los que no existen o ya no se usan, dentro los nuevos.
    $old = array(
        'actionCartUpdateQuantity', 'displayCartExtraProductActions', 'actionOrderReturn',
        'displayOrderDetail', 'displayOrderConfirmation', 'displayPDFInvoice', 'displayAdminOrdersExtra',
        'displayAdminOrdersView', 'actionOrderStatusUpdate', 'displayHeader', 'actionAfterDeleteProductInCart',
    );
    $new = $module->getHookList();
    foreach ($old as $hook) {
        if (!in_array($hook, $new)) {
            $module->unregisterHook($hook);
        }
    }
    foreach ($new as $hook) {
        $module->registerHook($hook);
    }

    // 7. Configuración.
    Configuration::deleteByName('JOSRA_GIFT_CALC_MODE');
    Configuration::deleteByName('JOSRA_GIFT_INCLUDE_SHIPPING');
    $module->installConfig();

    // 8. Ficheros de versiones anteriores (el log era accesible por URL).
    $dir = _PS_MODULE_DIR_ . $module->name . '/';
    $files = array(
        'josra_gift.log',
        'controllers/admin/AdminJosraGiftAjaxController.php',
        'sql/upgrade.sql',
        'upgrade/upgrade-1.0.0.php',
        'views/js/josra_gift_admin.js',
        'views/js/josra_gift_cart.js',
        'views/css/josra_gift_admin.css',
        'views/css/josra_gift_front.css',
        'views/templates/admin/config.tpl',
        'views/templates/admin/order_gift_info.tpl',
        'views/templates/hook/header_js.tpl',
        'views/templates/hook/motivational.tpl',
        'views/templates/hook/order_detail.tpl',
        'views/templates/hook/pdf_invoice.tpl',
    );
    foreach ($files as $file) {
        if (file_exists($dir . $file)) {
            @unlink($dir . $file);
        }
    }
    foreach (array('controllers/admin', 'override/classes/order', 'override/classes', 'override', 'views/templates/front') as $sub) {
        $path = $dir . $sub;
        if (is_dir($path)) {
            $left = array_diff(scandir($path), array('.', '..', 'index.php'));
            if (!$left) {
                @unlink($path . '/index.php');
                @rmdir($path);
            }
        }
    }

    return true;
}
