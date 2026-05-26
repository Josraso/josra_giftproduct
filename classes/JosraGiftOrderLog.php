<?php
/**
 * JosraGiftOrderLog - Registro de regalos en pedidos
 * Compatible PHP 5.6+
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
    public $id_josra_gift_rule_level;
    public $id_product;
    public $id_product_attribute;
    public $product_name;
    public $original_price;
    public $rule_name;
    public $date_add;

    public static $definition = array(
        'table'   => 'josra_gift_order_log',
        'primary' => 'id_josra_gift_order_log',
        'fields'  => array(
            'id_order'                  => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedInt', 'required' => true),
            'id_cart'                   => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedInt', 'required' => true),
            'id_josra_gift_rule'        => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedInt', 'required' => true),
            'id_josra_gift_rule_level'  => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedInt', 'required' => true),
            'id_product'                => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedInt', 'required' => true),
            'id_product_attribute'      => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedInt'),
            'product_name'              => array('type' => self::TYPE_STRING, 'size' => 255),
            'original_price'            => array('type' => self::TYPE_FLOAT,  'validate' => 'isPrice'),
            'rule_name'                 => array('type' => self::TYPE_STRING, 'size' => 128),
            'date_add'                  => array('type' => self::TYPE_DATE,   'validate' => 'isDate'),
        ),
    );

    public static function getByOrderId($orderId)
    {
        $result = Db::getInstance()->getRow(
            'SELECT l.*, p.`reference` AS product_reference
             FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` l
             LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = l.`id_product`
             WHERE l.`id_order` = ' . (int)$orderId
        );
        return $result ? $result : null;
    }

    public static function getGlobalStats($dateFrom = null, $dateTo = null)
    {
        $dateFilter = '';
        if ($dateFrom) {
            $dateFilter .= ' AND l.`date_add` >= \'' . pSQL($dateFrom) . ' 00:00:00\'';
        }
        if ($dateTo) {
            $dateFilter .= ' AND l.`date_add` <= \'' . pSQL($dateTo) . ' 23:59:59\'';
        }

        $totals = Db::getInstance()->getRow(
            'SELECT COUNT(DISTINCT l.`id_order`) AS total_orders_with_gift,
                    COALESCE(SUM(l.`original_price`), 0) AS total_gifted_value
             FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` l
             WHERE l.`id_order` > 0' . $dateFilter
        );

        $topProduct = Db::getInstance()->getRow(
            'SELECT l.`product_name`, COUNT(*) AS cnt
             FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` l
             WHERE l.`id_order` > 0' . $dateFilter . '
             GROUP BY l.`id_product`, l.`id_product_attribute`
             ORDER BY cnt DESC LIMIT 1'
        );

        $topRule = Db::getInstance()->getRow(
            'SELECT l.`rule_name`, COUNT(*) AS cnt
             FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` l
             WHERE l.`id_order` > 0' . $dateFilter . '
             GROUP BY l.`id_josra_gift_rule`
             ORDER BY cnt DESC LIMIT 1'
        );

        $lowStock = (array)Db::getInstance()->executeS(
            'SELECT DISTINCT rp.`id_product`, rp.`id_product_attribute`,
                    COALESCE(sa.`quantity`, 0) AS stock_qty
             FROM `' . _DB_PREFIX_ . 'josra_gift_rule_product` rp
             INNER JOIN `' . _DB_PREFIX_ . 'josra_gift_rule_level` rl
                     ON rl.`id_josra_gift_rule_level` = rp.`id_josra_gift_rule_level`
             INNER JOIN `' . _DB_PREFIX_ . 'josra_gift_rule` r
                     ON r.`id_josra_gift_rule` = rl.`id_josra_gift_rule` AND r.`active` = 1
             LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                    ON sa.`id_product` = rp.`id_product`
                   AND sa.`id_product_attribute` = rp.`id_product_attribute`
             WHERE COALESCE(sa.`quantity`, 0) <= 5'
        );

        return array(
            'total_orders_with_gift' => (int)(isset($totals['total_orders_with_gift']) ? $totals['total_orders_with_gift'] : 0),
            'total_gifted_value'     => (float)(isset($totals['total_gifted_value']) ? $totals['total_gifted_value'] : 0),
            'top_product'            => $topProduct ? $topProduct : null,
            'top_rule'               => $topRule ? $topRule : null,
            'low_stock_alerts'       => $lowStock,
        );
    }

    public static function getRecentGifts($limit = 10, $dateFrom = null, $dateTo = null)
    {
        $dateFilter = '';
        if ($dateFrom) {
            $dateFilter .= ' AND l.`date_add` >= \'' . pSQL($dateFrom) . ' 00:00:00\'';
        }
        if ($dateTo) {
            $dateFilter .= ' AND l.`date_add` <= \'' . pSQL($dateTo) . ' 23:59:59\'';
        }
        return (array)Db::getInstance()->executeS(
            'SELECT l.*, o.`reference` AS order_reference
             FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` l
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = l.`id_order`
             WHERE l.`id_order` > 0' . $dateFilter . '
             ORDER BY l.`date_add` DESC
             LIMIT ' . (int)$limit
        );
    }
}
