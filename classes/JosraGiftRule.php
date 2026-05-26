<?php
/**
 * JosraGiftRule - Modelo de regla de regalo
 * Compatible PHP 5.6+
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraGiftRule extends ObjectModel
{
    public $id_josra_gift_rule;
    public $name;
    public $active;
    public $trigger_type;
    public $calc_mode;
    public $include_shipping;
    public $date_start;
    public $date_end;
    public $max_uses;
    public $uses_count;
    public $priority;
    public $id_trigger_product;
    public $trigger_min_qty;
    public $date_add;
    public $date_upd;

    public static $definition = array(
        'table'   => 'josra_gift_rule',
        'primary' => 'id_josra_gift_rule',
        'fields'  => array(
            'name'               => array('type' => self::TYPE_STRING, 'required' => true, 'size' => 128),
            'active'             => array('type' => self::TYPE_BOOL,   'validate' => 'isBool'),
            'trigger_type'       => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'id_trigger_product' => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedInt'),
            'trigger_min_qty'    => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedInt'),
            'calc_mode'          => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'include_shipping'   => array('type' => self::TYPE_BOOL,   'validate' => 'isBool'),
            'date_start'         => array('type' => self::TYPE_DATE,   'validate' => 'isDate', 'copy_post' => false),
            'date_end'           => array('type' => self::TYPE_DATE,   'validate' => 'isDate', 'copy_post' => false),
            'max_uses'           => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedInt', 'copy_post' => false),
            'uses_count'         => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedInt'),
            'priority'           => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedInt'),
            'date_add'           => array('type' => self::TYPE_DATE,   'validate' => 'isDate'),
            'date_upd'           => array('type' => self::TYPE_DATE,   'validate' => 'isDate'),
        ),
    );

    public static function getActiveRules()
    {
        $today = pSQL(date('Y-m-d'));
        $sql = 'SELECT r.*
                FROM `' . _DB_PREFIX_ . 'josra_gift_rule` r
                WHERE r.`active` = 1
                  AND (r.`date_start` IS NULL OR r.`date_start` = \'0000-00-00\' OR r.`date_start` <= \'' . $today . '\')
                  AND (r.`date_end`   IS NULL OR r.`date_end`   = \'0000-00-00\' OR r.`date_end`   >= \'' . $today . '\')
                  AND (r.`max_uses`   IS NULL OR r.`uses_count` < r.`max_uses`)
                ORDER BY r.`priority` DESC, r.`id_josra_gift_rule` ASC';
        return (array)Db::getInstance()->executeS($sql);
    }

    public static function getAllRulesWithStats()
    {
        $sql = 'SELECT r.*,
                       COUNT(DISTINCT l.`id_order`) AS total_orders,
                       COALESCE(SUM(l.`original_price`), 0) AS total_gifted_value
                FROM `' . _DB_PREFIX_ . 'josra_gift_rule` r
                LEFT JOIN `' . _DB_PREFIX_ . 'josra_gift_order_log` l
                       ON l.`id_josra_gift_rule` = r.`id_josra_gift_rule`
                GROUP BY r.`id_josra_gift_rule`
                ORDER BY r.`priority` DESC, r.`id_josra_gift_rule` ASC';
        return (array)Db::getInstance()->executeS($sql);
    }

    public static function getLevelsByRuleId($ruleId, $idLang = 0)
    {
        if (!$idLang) {
            $idLang = (int)Configuration::get('PS_LANG_DEFAULT');
        }
        $sql = 'SELECT l.*, rp.`id_product`, rp.`id_product_attribute`,
                       COALESCE(rp.`gift_qty`, 1) AS gift_qty,
                       (SELECT `name` FROM `' . _DB_PREFIX_ . 'product_lang`
                        WHERE `id_product` = rp.`id_product`
                          AND `id_lang` = ' . (int)$idLang . ' LIMIT 1) AS product_name
                FROM `' . _DB_PREFIX_ . 'josra_gift_rule_level` l
                LEFT JOIN `' . _DB_PREFIX_ . 'josra_gift_rule_product` rp
                       ON rp.`id_josra_gift_rule_level` = l.`id_josra_gift_rule_level`
                WHERE l.`id_josra_gift_rule` = ' . (int)$ruleId . '
                ORDER BY l.`trigger_value` ASC';
        return (array)Db::getInstance()->executeS($sql);
    }

    public static function getRestrictionsByRuleId($ruleId)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'josra_gift_rule_restriction`
                WHERE `id_josra_gift_rule` = ' . (int)$ruleId;
        return (array)Db::getInstance()->executeS($sql);
    }

    public static function incrementUses($ruleId)
    {
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'josra_gift_rule`
             SET `uses_count` = `uses_count` + 1
             WHERE `id_josra_gift_rule` = ' . (int)$ruleId
        );
    }

    public static function saveLevels($ruleId, $levels)
    {
        $oldRows = Db::getInstance()->executeS(
            'SELECT `id_josra_gift_rule_level` FROM `' . _DB_PREFIX_ . 'josra_gift_rule_level`
             WHERE `id_josra_gift_rule` = ' . (int)$ruleId
        );
        if ($oldRows) {
            $ids = implode(',', array_column($oldRows, 'id_josra_gift_rule_level'));
            Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'josra_gift_rule_product`
                 WHERE `id_josra_gift_rule_level` IN (' . $ids . ')'
            );
        }
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'josra_gift_rule_level`
             WHERE `id_josra_gift_rule` = ' . (int)$ruleId
        );

        foreach ($levels as $sortOrder => $level) {
            Db::getInstance()->insert('josra_gift_rule_level', array(
                'id_josra_gift_rule' => (int)$ruleId,
                'trigger_value'      => (float)$level['trigger_value'],
                'sort_order'         => (int)$sortOrder,
            ));
            $levelId = (int)Db::getInstance()->Insert_ID();

            if (!empty($level['id_product'])) {
                Db::getInstance()->insert('josra_gift_rule_product', array(
                    'id_josra_gift_rule_level' => $levelId,
                    'id_product'               => (int)$level['id_product'],
                    'id_product_attribute'     => (int)(isset($level['id_product_attribute']) ? $level['id_product_attribute'] : 0),
                    'gift_qty'                 => max(1, (int)(isset($level['gift_qty']) ? $level['gift_qty'] : 1)),
                ));
            }
        }
    }

    public static function saveRestrictions($ruleId, $restrictions)
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'josra_gift_rule_restriction`
             WHERE `id_josra_gift_rule` = ' . (int)$ruleId
        );
        foreach ($restrictions as $r) {
            if (empty($r['type']) || empty($r['id_value'])) {
                continue;
            }
            Db::getInstance()->insert('josra_gift_rule_restriction', array(
                'id_josra_gift_rule' => (int)$ruleId,
                'restriction_type'   => pSQL($r['type']),
                'id_value'           => (int)$r['id_value'],
            ));
        }
    }
}
