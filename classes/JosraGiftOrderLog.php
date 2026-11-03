<?php
/**
 * Regalos entregados en pedidos (estadísticas y back-office).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraGiftOrderLog extends ObjectModel
{
    public $id_josra_gift_order_log;
    public $id_order;
    public $id_cart;
    public $id_josra_gift_rule;
    public $id_josra_gift_rule_level = 0;
    public $id_product;
    public $id_product_attribute = 0;
    public $gift_qty = 1;
    public $product_name = '';
    public $original_price = 0;
    public $rule_name = '';
    public $status = 'valid';
    public $date_add;

    public static $definition = array(
        'table' => 'josra_gift_order_log',
        'primary' => 'id_josra_gift_order_log',
        'fields' => array(
            'id_order' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'id_cart' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'id_josra_gift_rule' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'required' => true),
            'id_josra_gift_rule_level' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'id_product' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'id_product_attribute' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'gift_qty' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'product_name' => array('type' => self::TYPE_STRING, 'size' => 255),
            'original_price' => array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
            'rule_name' => array('type' => self::TYPE_STRING, 'size' => 128),
            'status' => array('type' => self::TYPE_STRING, 'size' => 16),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    public static function getByOrderId($idOrder)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` WHERE `id_order` = ' . (int)$idOrder
        );
        return $row ? $row : null;
    }

    public static function setStatus($idLog, $status)
    {
        return Db::getInstance()->update(
            'josra_gift_order_log',
            array('status' => pSQL($status)),
            'id_josra_gift_order_log = ' . (int)$idLog
        );
    }

    protected static function dateFilter($from, $to, $alias = 'l')
    {
        $sql = '';
        if ($from && Validate::isDate($from)) {
            $sql .= ' AND ' . $alias . '.`date_add` >= \'' . pSQL($from) . ' 00:00:00\'';
        }
        if ($to && Validate::isDate($to)) {
            $sql .= ' AND ' . $alias . '.`date_add` <= \'' . pSQL($to) . ' 23:59:59\'';
        }
        return $sql;
    }

    /**
     * Totales del periodo. El valor regalado es sin IVA y en la moneda por defecto.
     */
    public static function getStats($from = null, $to = null)
    {
        $where = 'WHERE l.`status` = \'valid\'' . self::dateFilter($from, $to);
        $db = Db::getInstance();

        $totals = $db->getRow(
            'SELECT COUNT(DISTINCT l.`id_order`) AS orders, COALESCE(SUM(l.`gift_qty`), 0) AS units,
                    COALESCE(SUM(l.`gift_qty` * l.`original_price`), 0) AS value
             FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` l ' . $where
        );
        $topProduct = $db->getRow(
            'SELECT l.`product_name`, SUM(l.`gift_qty`) AS units
             FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` l ' . $where . '
             GROUP BY l.`id_product`, l.`id_product_attribute`
             ORDER BY units DESC'
        );
        $topRule = $db->getRow(
            'SELECT l.`rule_name`, COUNT(DISTINCT l.`id_order`) AS orders
             FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` l ' . $where . '
             GROUP BY l.`id_josra_gift_rule`
             ORDER BY orders DESC'
        );

        return array(
            'orders' => (int)$totals['orders'],
            'units' => (int)$totals['units'],
            'value' => (float)$totals['value'],
            'top_product' => $topProduct ? $topProduct['product_name'] : '',
            'top_rule' => $topRule ? $topRule['rule_name'] : '',
        );
    }

    public static function getRecent($limit = 20, $from = null, $to = null)
    {
        return (array)Db::getInstance()->executeS(
            'SELECT l.*, o.`reference`
             FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` l
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = l.`id_order`
             WHERE 1' . self::dateFilter($from, $to) . '
             ORDER BY l.`date_add` DESC
             LIMIT ' . (int)$limit
        );
    }

    /**
     * Productos de regalo de reglas activas con poco stock.
     */
    public static function getLowStock($threshold, $idLang)
    {
        if (!Configuration::get('PS_STOCK_MANAGEMENT')) {
            return array();
        }
        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT l.`id_product`, l.`id_product_attribute`, r.`name` AS rule_name
             FROM `' . _DB_PREFIX_ . 'josra_gift_rule_level` l
             INNER JOIN `' . _DB_PREFIX_ . 'josra_gift_rule` r
                     ON r.`id_josra_gift_rule` = l.`id_josra_gift_rule` AND r.`active` = 1'
        );
        $out = array();
        foreach ((array)$rows as $row) {
            $qty = (int)StockAvailable::getQuantityAvailableByProduct((int)$row['id_product'], (int)$row['id_product_attribute']);
            if ($qty <= (int)$threshold) {
                $name = JosraGiftRule::getProductName((int)$row['id_product'], $idLang);
                if ($row['id_product_attribute']) {
                    $name .= ' (' . JosraGiftRule::getCombinationName((int)$row['id_product_attribute'], $idLang) . ')';
                }
                $out[] = array(
                    'id_product' => (int)$row['id_product'],
                    'name' => $name,
                    'rule_name' => $row['rule_name'],
                    'quantity' => $qty,
                );
            }
        }
        return $out;
    }
}
