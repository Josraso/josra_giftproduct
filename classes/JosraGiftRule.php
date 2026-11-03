<?php
/**
 * Regla de regalo, sus tramos y su segmentación.
 * Compatible PHP 5.6+ / PrestaShop 1.6 - 9.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraGiftRule extends ObjectModel
{
    const TRIGGER_AMOUNT = 'amount';
    const TRIGGER_QUANTITY = 'quantity';
    const TRIGGER_PRODUCT = 'product';

    public $id_josra_gift_rule;
    public $name;
    public $active = 1;
    public $trigger_type = 'amount';
    public $id_trigger_product = 0;
    public $trigger_min_qty = 1;
    public $calc_mode = 'with_tax';
    public $include_shipping = 0;
    public $date_start;
    public $date_end;
    public $max_uses = 0;
    public $uses_count = 0;
    public $priority = 0;
    public $date_add;
    public $date_upd;

    public static $definition = array(
        'table' => 'josra_gift_rule',
        'primary' => 'id_josra_gift_rule',
        'fields' => array(
            'name' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 128),
            'active' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'trigger_type' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16),
            'id_trigger_product' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'trigger_min_qty' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'calc_mode' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16),
            'include_shipping' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'date_start' => array('type' => self::TYPE_DATE, 'validate' => 'isDateFormat', 'allow_null' => true),
            'date_end' => array('type' => self::TYPE_DATE, 'validate' => 'isDateFormat', 'allow_null' => true),
            'max_uses' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'uses_count' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'priority' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /** @var array|null caché por petición */
    protected static $activeCache = null;

    public static function resetActiveCache()
    {
        self::$activeCache = null;
    }

    public function add($autoDate = true, $nullValues = true)
    {
        self::resetActiveCache();
        return parent::add($autoDate, $nullValues);
    }

    public function update($nullValues = true)
    {
        self::resetActiveCache();
        return parent::update($nullValues);
    }

    public function delete()
    {
        self::resetActiveCache();
        $id = (int)$this->id;
        $ok = parent::delete();
        if ($ok) {
            Db::getInstance()->delete('josra_gift_rule_level', 'id_josra_gift_rule = ' . $id);
            Db::getInstance()->delete('josra_gift_rule_restriction', 'id_josra_gift_rule = ' . $id);
        }
        return $ok;
    }

    /**
     * Reglas activas hoy y con unidades disponibles, por prioridad.
     * Incluye tramos y restricciones ya cargados.
     */
    public static function getActiveRules()
    {
        if (self::$activeCache !== null) {
            return self::$activeCache;
        }
        $today = pSQL(date('Y-m-d'));
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josra_gift_rule`
             WHERE `active` = 1
               AND (`date_start` IS NULL OR `date_start` = \'0000-00-00\' OR `date_start` <= \'' . $today . '\')
               AND (`date_end` IS NULL OR `date_end` = \'0000-00-00\' OR `date_end` >= \'' . $today . '\')
               AND (`max_uses` = 0 OR `uses_count` < `max_uses`)
             ORDER BY `priority` DESC, `id_josra_gift_rule` ASC'
        );
        $rules = array();
        foreach ((array)$rows as $row) {
            $row['levels'] = self::getLevels((int)$row['id_josra_gift_rule']);
            $row['restrictions'] = self::getRestrictions((int)$row['id_josra_gift_rule']);
            if (!empty($row['levels'])) {
                $rules[] = $row;
            }
        }
        self::$activeCache = $rules;
        return $rules;
    }

    /**
     * Tramos ordenados de menor a mayor umbral, con nombre de producto.
     */
    public static function getLevels($idRule, $idLang = null)
    {
        if (!$idLang) {
            $idLang = (int)Context::getContext()->language->id;
        }
        $rows = Db::getInstance()->executeS(
            'SELECT l.*, pl.`name` AS product_name
             FROM `' . _DB_PREFIX_ . 'josra_gift_rule_level` l
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                    ON pl.`id_product` = l.`id_product`
                   AND pl.`id_lang` = ' . (int)$idLang . '
                   AND pl.`id_shop` = ' . (int)Context::getContext()->shop->id . '
             WHERE l.`id_josra_gift_rule` = ' . (int)$idRule . '
             ORDER BY l.`trigger_value` ASC, l.`sort_order` ASC'
        );
        $levels = array();
        foreach ((array)$rows as $row) {
            $row['gift_qty'] = max(1, (int)$row['gift_qty']);
            $row['trigger_value'] = (float)$row['trigger_value'];
            $row['combination_name'] = $row['id_product_attribute']
                ? self::getCombinationName((int)$row['id_product_attribute'], $idLang)
                : '';
            $levels[] = $row;
        }
        return $levels;
    }

    public static function getRestrictions($idRule)
    {
        $out = array('group' => array(), 'zone' => array());
        $rows = Db::getInstance()->executeS(
            'SELECT `restriction_type`, `id_value` FROM `' . _DB_PREFIX_ . 'josra_gift_rule_restriction`
             WHERE `id_josra_gift_rule` = ' . (int)$idRule
        );
        foreach ((array)$rows as $row) {
            if (isset($out[$row['restriction_type']])) {
                $out[$row['restriction_type']][] = (int)$row['id_value'];
            }
        }
        return $out;
    }

    public static function saveLevels($idRule, array $levels)
    {
        Db::getInstance()->delete('josra_gift_rule_level', 'id_josra_gift_rule = ' . (int)$idRule);
        $pos = 0;
        foreach ($levels as $level) {
            Db::getInstance()->insert('josra_gift_rule_level', array(
                'id_josra_gift_rule' => (int)$idRule,
                'trigger_value' => (float)$level['trigger_value'],
                'id_product' => (int)$level['id_product'],
                'id_product_attribute' => (int)$level['id_product_attribute'],
                'gift_qty' => max(1, (int)$level['gift_qty']),
                'sort_order' => $pos++,
            ));
        }
        self::resetActiveCache();
    }

    public static function saveRestrictions($idRule, array $groups, array $zones)
    {
        Db::getInstance()->delete('josra_gift_rule_restriction', 'id_josra_gift_rule = ' . (int)$idRule);
        foreach (array('group' => $groups, 'zone' => $zones) as $type => $ids) {
            foreach (array_unique(array_map('intval', $ids)) as $id) {
                if ($id > 0) {
                    Db::getInstance()->insert('josra_gift_rule_restriction', array(
                        'id_josra_gift_rule' => (int)$idRule,
                        'restriction_type' => $type,
                        'id_value' => $id,
                    ));
                }
            }
        }
        self::resetActiveCache();
    }

    /**
     * Suma o resta unidades entregadas (nunca por debajo de 0).
     */
    public static function addUses($idRule, $units)
    {
        $units = (int)$units;
        if (!$units) {
            return;
        }
        $expr = $units > 0
            ? '`uses_count` + ' . $units
            : 'IF(`uses_count` > ' . abs($units) . ', `uses_count` - ' . abs($units) . ', 0)';
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'josra_gift_rule` SET `uses_count` = ' . $expr . '
             WHERE `id_josra_gift_rule` = ' . (int)$idRule
        );
        self::resetActiveCache();
    }

    public static function duplicate($idRule)
    {
        $rule = new JosraGiftRule((int)$idRule);
        if (!Validate::isLoadedObject($rule)) {
            return false;
        }
        $copy = clone $rule;
        $copy->id = null;
        $copy->id_josra_gift_rule = null;
        $copy->name = Tools::substr($rule->name . ' (copia)', 0, 128);
        $copy->active = 0;
        $copy->uses_count = 0;
        if (!$copy->add()) {
            return false;
        }
        $levels = self::getLevels((int)$rule->id);
        self::saveLevels((int)$copy->id, $levels);
        $r = self::getRestrictions((int)$rule->id);
        self::saveRestrictions((int)$copy->id, $r['group'], $r['zone']);
        return (int)$copy->id;
    }

    public static function getCombinationName($idProductAttribute, $idLang)
    {
        $names = Db::getInstance()->executeS(
            'SELECT al.`name`
             FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
             INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
             INNER JOIN `' . _DB_PREFIX_ . 'attribute_group` ag ON ag.`id_attribute_group` = a.`id_attribute_group`
             LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                    ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . (int)$idLang . '
             WHERE pac.`id_product_attribute` = ' . (int)$idProductAttribute . '
             ORDER BY ag.`position` ASC'
        );
        $out = array();
        foreach ((array)$names as $n) {
            $out[] = $n['name'];
        }
        return implode(' - ', $out);
    }

    public static function getProductName($idProduct, $idLang = null)
    {
        if (!$idLang) {
            $idLang = (int)Context::getContext()->language->id;
        }
        return (string)Db::getInstance()->getValue(
            'SELECT `name` FROM `' . _DB_PREFIX_ . 'product_lang`
             WHERE `id_product` = ' . (int)$idProduct . ' AND `id_lang` = ' . (int)$idLang . '
               AND `id_shop` = ' . (int)Context::getContext()->shop->id
        );
    }

    /**
     * Listado para el back-office con resumen de tramos y pedidos.
     */
    public static function getListForAdmin($idLang)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT r.*,
                    (SELECT COUNT(DISTINCT gl.`id_order`) FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` gl
                      WHERE gl.`id_josra_gift_rule` = r.`id_josra_gift_rule` AND gl.`status` = \'valid\') AS orders_count
             FROM `' . _DB_PREFIX_ . 'josra_gift_rule` r
             ORDER BY r.`priority` DESC, r.`id_josra_gift_rule` ASC'
        );
        return (array)$rows;
    }
}
