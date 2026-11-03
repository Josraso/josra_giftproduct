<?php
/**
 * Estado del regalo de cada carrito (tabla josra_gift_cart).
 * Se guarda en BD y no en la cookie para que funcione cuando el pedido
 * lo valida un TPV por notificación, en carritos recuperados, etc.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraGiftCart
{
    public static function get($idCart)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'josra_gift_cart` WHERE `id_cart` = ' . (int)$idCart
        );
        if (!$row) {
            return null;
        }
        foreach (array('id_cart', 'id_josra_gift_rule', 'id_josra_gift_rule_level', 'id_product',
            'id_product_attribute', 'gift_qty', 'in_cart', 'declined', 'id_specific_price') as $k) {
            $row[$k] = (int)$row[$k];
        }
        return $row;
    }

    public static function blank($idCart)
    {
        return array(
            'id_cart' => (int)$idCart,
            'id_josra_gift_rule' => 0,
            'id_josra_gift_rule_level' => 0,
            'id_product' => 0,
            'id_product_attribute' => 0,
            'gift_qty' => 0,
            'in_cart' => 0,
            'declined' => 0,
            'id_specific_price' => 0,
            'notice' => '',
            'notice_data' => '',
        );
    }

    public static function save(array $state)
    {
        $data = array(
            'id_cart' => (int)$state['id_cart'],
            'id_josra_gift_rule' => (int)$state['id_josra_gift_rule'],
            'id_josra_gift_rule_level' => (int)$state['id_josra_gift_rule_level'],
            'id_product' => (int)$state['id_product'],
            'id_product_attribute' => (int)$state['id_product_attribute'],
            'gift_qty' => (int)$state['gift_qty'],
            'in_cart' => (int)$state['in_cart'],
            'declined' => (int)$state['declined'],
            'id_specific_price' => (int)$state['id_specific_price'],
            'notice' => pSQL((string)$state['notice']),
            'notice_data' => pSQL(Tools::substr((string)$state['notice_data'], 0, 255)),
            'date_upd' => date('Y-m-d H:i:s'),
        );
        return Db::getInstance()->insert('josra_gift_cart', $data, false, true, Db::REPLACE);
    }

    public static function delete($idCart)
    {
        return Db::getInstance()->delete('josra_gift_cart', 'id_cart = ' . (int)$idCart);
    }

    /**
     * Lee y borra el aviso pendiente (desbloqueado, mejorado, agotado).
     */
    public static function popNotice($idCart)
    {
        $state = self::get($idCart);
        if (!$state || $state['notice'] === '') {
            return null;
        }
        $notice = array('type' => $state['notice'], 'data' => json_decode($state['notice_data'], true));
        Db::getInstance()->update('josra_gift_cart', array('notice' => '', 'notice_data' => ''), 'id_cart = ' . (int)$idCart);
        return $notice;
    }

    /**
     * Borra precios específicos y estados de carritos ya convertidos en pedido
     * o abandonados hace más de $days días.
     */
    public static function cleanup($days = 30)
    {
        $limit = pSQL(date('Y-m-d H:i:s', time() - (int)$days * 86400));
        $rows = Db::getInstance()->executeS(
            'SELECT gc.`id_cart`, gc.`id_specific_price`
             FROM `' . _DB_PREFIX_ . 'josra_gift_cart` gc
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_cart` = gc.`id_cart`
             WHERE o.`id_order` IS NOT NULL OR gc.`date_upd` < \'' . $limit . '\'
             LIMIT 500'
        );
        foreach ((array)$rows as $row) {
            self::deleteSpecificPrice((int)$row['id_specific_price'], (int)$row['id_cart']);
            self::delete((int)$row['id_cart']);
        }
        return count((array)$rows);
    }

    /**
     * Borra solo el precio específico creado por el módulo (comprobando que
     * sigue siendo de ese carrito, por si el id se hubiera reutilizado).
     */
    public static function deleteSpecificPrice($idSpecificPrice, $idCart)
    {
        if (!$idSpecificPrice) {
            return;
        }
        $sp = new SpecificPrice((int)$idSpecificPrice);
        if (Validate::isLoadedObject($sp) && (int)$sp->id_cart === (int)$idCart) {
            $sp->delete();
        }
    }

    public static function deleteAllSpecificPrices()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_cart`, `id_specific_price` FROM `' . _DB_PREFIX_ . 'josra_gift_cart` WHERE `id_specific_price` > 0'
        );
        foreach ((array)$rows as $row) {
            self::deleteSpecificPrice((int)$row['id_specific_price'], (int)$row['id_cart']);
        }
    }
}
