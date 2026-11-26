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
            'id_product_attribute', 'gift_qty', 'in_cart', 'declined', 'id_specific_price', 'id_cart_rule') as $k) {
            $row[$k] = isset($row[$k]) ? (int)$row[$k] : 0;
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
            'id_cart_rule' => 0,
            'notice' => '',
            'notice_data' => '',
        );
    }

    public static function save(array $state)
    {
        // columnas nuevas aunque se haya actualizado subiendo los archivos
        JosraGiftChoice::ensureSchema();
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
            'id_cart_rule' => isset($state['id_cart_rule']) ? (int)$state['id_cart_rule'] : 0,
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
     * Borra precios específicos, vales sin usar y estados de carritos ya
     * convertidos en pedido o abandonados hace más de $days días.
     * Los vales que ya están en un pedido se quedan (son parte del pedido).
     */
    public static function cleanup($days = 30)
    {
        $limit = pSQL(date('Y-m-d H:i:s', time() - (int)$days * 86400));
        $rows = Db::getInstance()->executeS(
            'SELECT gc.`id_cart`, gc.`id_specific_price`, gc.`id_cart_rule`
             FROM `' . _DB_PREFIX_ . 'josra_gift_cart` gc
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_cart` = gc.`id_cart`
             WHERE o.`id_order` IS NOT NULL OR gc.`date_upd` < \'' . $limit . '\'
             LIMIT 500'
        );
        foreach ((array)$rows as $row) {
            self::deleteSpecificPrice((int)$row['id_specific_price'], (int)$row['id_cart']);
            self::deleteCoupon((int)$row['id_cart_rule'], (int)$row['id_cart']);
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

    /**
     * Al desinstalar: fuera los precios a cero y los vales sin usar de los carritos abiertos.
     */
    public static function deleteAllSpecificPrices()
    {
        JosraGiftChoice::ensureSchema();
        $rows = Db::getInstance()->executeS(
            'SELECT `id_cart`, `id_specific_price`, `id_cart_rule` FROM `' . _DB_PREFIX_ . 'josra_gift_cart`
             WHERE `id_specific_price` > 0 OR `id_cart_rule` > 0'
        );
        foreach ((array)$rows as $row) {
            self::deleteSpecificPrice((int)$row['id_specific_price'], (int)$row['id_cart']);
            self::deleteCoupon((int)$row['id_cart_rule'], (int)$row['id_cart']);
        }
    }

    // =========================================================================
    // VALE DEL REGALO (modo «a su precio con un vale de descuento»)
    // =========================================================================

    /** Ajuste general: «zero» = regalo a precio cero (como siempre), «coupon» = a su precio con un vale */
    public static function priceMode()
    {
        return Configuration::get('JOSRA_GIFT_PRICE_MODE') === 'coupon' ? 'coupon' : 'zero';
    }

    /** Marca que llevan en la descripción los vales que crea el módulo para un carrito */
    public static function couponTag($idCart)
    {
        return 'josra_giftproduct:cart:' . (int)$idCart;
    }

    /** El vale es del módulo y de ese carrito (por si el id se hubiera reutilizado) */
    public static function isOwnCoupon($cartRule, $idCart)
    {
        return is_object($cartRule) && Validate::isLoadedObject($cartRule)
            && (string)$cartRule->description === self::couponTag($idCart);
    }

    /** El vale ya está en algún pedido */
    public static function couponUsed($idCartRule)
    {
        return (bool)Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'order_cart_rule` WHERE `id_cart_rule` = ' . (int)$idCartRule
        );
    }

    /**
     * Quita el vale del regalo del carrito y lo borra. Si ya está en un pedido,
     * no se toca: forma parte de ese pedido y de su factura.
     */
    public static function deleteCoupon($idCartRule, $idCart)
    {
        if (!$idCartRule) {
            return;
        }
        $cr = new CartRule((int)$idCartRule);
        if (!self::isOwnCoupon($cr, $idCart) || self::couponUsed($cr->id)) {
            return;
        }
        // CartRule::delete() también lo saca de cart_cart_rule
        $cr->delete();
        self::flushCartRuleCache($idCart);
    }

    public static function flushCartRuleCache($idCart)
    {
        if (class_exists('Cache')) {
            Cache::clean('Cart::getCartRules_' . (int)$idCart . '-*');
            Cache::clean('Cart::getOrderedCartRulesIds_' . (int)$idCart . '-*');
            Cache::clean('getContextualValue_*');
        }
    }

    /**
     * Quita el descuento del regalo, sea cual sea el modo (precio a cero o vale),
     * y deja los ids a cero en el estado.
     */
    public static function releaseDiscount(array &$state, $idCart)
    {
        self::deleteSpecificPrice(isset($state['id_specific_price']) ? $state['id_specific_price'] : 0, $idCart);
        self::deleteCoupon(isset($state['id_cart_rule']) ? $state['id_cart_rule'] : 0, $idCart);
        $state['id_specific_price'] = 0;
        $state['id_cart_rule'] = 0;
    }
}
