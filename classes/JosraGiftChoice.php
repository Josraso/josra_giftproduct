<?php
/**
 * Reglas «el cliente elige el regalo» (por importe del carrito).
 *
 * - Los tramos son las filas de la regla con el mismo «Desde»: cada fila es un regalo que se puede elegir en ese tramo.
 * - Uno por pedido o uno por cada tramo alcanzado (según la regla); con o sin los regalos de los tramos de abajo.
 * - Si el regalo tiene combinaciones, el cliente elige cuál (todas o solo las marcadas en la regla, con stock).
 * - El regalo elegido se mete en el carrito gratis, como los automáticos: a precio cero o a su precio con un vale,
 *   según el ajuste «Cómo sale el regalo».
 * - Si el carrito baja de tramo, el regalo se agota o deja de valer, se quita solo y se avisa.
 *
 * Compatible PHP 5.6+ / PrestaShop 1.6 - 9.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraGiftChoice
{
    const SCHEMA = 220;

    /** @var JosraGiftManager */
    protected $manager;

    /** @var Context */
    protected $context;

    public function __construct($manager, $context = null)
    {
        $this->manager = $manager;
        $this->context = $context ? $context : Context::getContext();
    }

    // =========================================================================
    // TABLAS (también si se actualiza subiendo los archivos sin pasar por el upgrade)
    // =========================================================================

    public static function ensureSchema()
    {
        if ((int)Configuration::getGlobalValue('JOSRA_GIFT_SCHEMA') >= self::SCHEMA) {
            return true;
        }
        $db = Db::getInstance();
        $p = _DB_PREFIX_;
        $ok = $db->execute('CREATE TABLE IF NOT EXISTS `' . $p . 'josra_gift_choice` (
            `id_cart` INT(10) UNSIGNED NOT NULL,
            `slot` VARCHAR(32) NOT NULL,
            `id_josra_gift_rule` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `id_josra_gift_rule_level` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `id_product` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `id_product_attribute` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `gift_qty` INT(10) UNSIGNED NOT NULL DEFAULT 1,
            `id_specific_price` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `id_cart_rule` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_cart`, `slot`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
        $cols = array(
            array('josra_gift_rule', 'choice_mode', 'VARCHAR(16) NOT NULL DEFAULT \'one\''),
            array('josra_gift_rule', 'choice_lower', 'TINYINT(1) NOT NULL DEFAULT 1'),
            array('josra_gift_rule', 'after_discounts', 'TINYINT(1) NOT NULL DEFAULT 0'),
            array('josra_gift_rule_level', 'allowed_attributes', 'VARCHAR(2000) NOT NULL DEFAULT \'\''),
            // 2.2.0: vale del regalo (modo «a su precio con un vale»)
            array('josra_gift_cart', 'id_cart_rule', 'INT(10) UNSIGNED NOT NULL DEFAULT 0'),
            array('josra_gift_choice', 'id_cart_rule', 'INT(10) UNSIGNED NOT NULL DEFAULT 0'),
        );
        foreach ($cols as $c) {
            $exists = $db->executeS('SHOW COLUMNS FROM `' . $p . $c[0] . '` LIKE \'' . pSQL($c[1]) . '\'');
            if (!$exists) {
                $ok = $db->execute('ALTER TABLE `' . $p . $c[0] . '` ADD `' . $c[1] . '` ' . $c[2]) && $ok;
            }
        }
        if ($ok) {
            Configuration::updateGlobalValue('JOSRA_GIFT_SCHEMA', self::SCHEMA);
            JosraGiftRule::resetActiveCache();
        }
        return $ok;
    }

    // =========================================================================
    // FILAS (regalo elegido por hueco)
    // =========================================================================

    public static function rows($idCart)
    {
        $out = array();
        $rows = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'josra_gift_choice` WHERE `id_cart` = ' . (int)$idCart);
        foreach ((array)$rows as $r) {
            foreach (array('id_cart', 'id_josra_gift_rule', 'id_josra_gift_rule_level', 'id_product', 'id_product_attribute', 'gift_qty', 'id_specific_price', 'id_cart_rule') as $k) {
                $r[$k] = isset($r[$k]) ? (int)$r[$k] : 0;
            }
            $out[$r['slot']] = $r;
        }
        return $out;
    }

    protected static function saveRow(array $row)
    {
        self::ensureSchema();
        return Db::getInstance()->insert('josra_gift_choice', array(
            'id_cart' => (int)$row['id_cart'],
            'slot' => pSQL($row['slot']),
            'id_josra_gift_rule' => (int)$row['id_josra_gift_rule'],
            'id_josra_gift_rule_level' => (int)$row['id_josra_gift_rule_level'],
            'id_product' => (int)$row['id_product'],
            'id_product_attribute' => (int)$row['id_product_attribute'],
            'gift_qty' => max(1, (int)$row['gift_qty']),
            'id_specific_price' => (int)$row['id_specific_price'],
            'id_cart_rule' => isset($row['id_cart_rule']) ? (int)$row['id_cart_rule'] : 0,
            'date_upd' => date('Y-m-d H:i:s'),
        ), false, true, Db::REPLACE);
    }

    protected static function deleteRow($idCart, $slot)
    {
        return Db::getInstance()->delete('josra_gift_choice', '`id_cart` = ' . (int)$idCart . ' AND `slot` = \'' . pSQL($slot) . '\'');
    }

    public static function deleteAllSpecificPrices()
    {
        if (!Db::getInstance()->executeS('SHOW TABLES LIKE \'' . pSQL(_DB_PREFIX_ . 'josra_gift_choice') . '\'')) {
            return;
        }
        self::ensureSchema();
        foreach ((array)Db::getInstance()->executeS('SELECT `id_cart`, `id_specific_price`, `id_cart_rule` FROM `' . _DB_PREFIX_ . 'josra_gift_choice`
            WHERE `id_specific_price` > 0 OR `id_cart_rule` > 0') as $r) {
            JosraGiftCart::deleteSpecificPrice((int)$r['id_specific_price'], (int)$r['id_cart']);
            JosraGiftCart::deleteCoupon((int)$r['id_cart_rule'], (int)$r['id_cart']);
        }
    }

    /** Carritos ya convertidos en pedido o abandonados: fuera sus precios a cero y sus vales sin usar */
    public static function cleanup($days = 30)
    {
        self::ensureSchema();
        $limit = pSQL(date('Y-m-d H:i:s', time() - (int)$days * 86400));
        $rows = Db::getInstance()->executeS(
            'SELECT gc.`id_cart`, gc.`slot`, gc.`id_specific_price`, gc.`id_cart_rule` FROM `' . _DB_PREFIX_ . 'josra_gift_choice` gc
             LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_cart` = gc.`id_cart`
             WHERE o.`id_order` IS NOT NULL OR gc.`date_upd` < \'' . $limit . '\' LIMIT 500'
        );
        foreach ((array)$rows as $r) {
            JosraGiftCart::deleteSpecificPrice((int)$r['id_specific_price'], (int)$r['id_cart']);
            JosraGiftCart::deleteCoupon((int)$r['id_cart_rule'], (int)$r['id_cart']);
            self::deleteRow((int)$r['id_cart'], $r['slot']);
        }
    }

    // =========================================================================
    // REGLA, TRAMOS Y HUECOS
    // =========================================================================

    /** La regla «el cliente elige» que manda para este carrito (la de más prioridad que le vale) */
    public function rule($cart)
    {
        foreach ($this->manager->getApplicableRules($cart) as $rule) {
            if ($rule['trigger_type'] === JosraGiftRule::TRIGGER_CHOICE) {
                return $rule;
            }
        }
        return null;
    }

    /** Tramos: filas con el mismo «Desde», de menor a mayor */
    public function tiers(array $rule)
    {
        $tiers = array();
        foreach ($rule['levels'] as $lv) {
            $key = number_format((float)$lv['trigger_value'], 2, '.', '');
            if (!isset($tiers[$key])) {
                $tiers[$key] = array('value' => (float)$lv['trigger_value'], 'key' => $key, 'options' => array());
            }
            $tiers[$key]['options'][] = $lv;
        }
        ksort($tiers, SORT_NUMERIC);
        return array_values($tiers);
    }

    /**
     * Huecos para elegir: uno por pedido (con los regalos del tramo más alto alcanzado, y si la regla lo deja, los
     * de abajo) o uno por cada tramo alcanzado.
     *
     * @return array lista de ['slot', 'tier' (índice), 'tiers' (índices de los que se puede elegir), 'value']
     */
    public function slots(array $rule, array $tiers, $value)
    {
        $reached = array();
        foreach ($tiers as $i => $t) {
            if ($value + 0.000001 >= $t['value']) {
                $reached[] = $i;
            }
        }
        if (!$reached) {
            return array();
        }
        $lower = (int)$rule['choice_lower'];
        if ($rule['choice_mode'] === 'per_level') {
            $out = array();
            foreach ($reached as $i) {
                $out[] = array('slot' => 't' . $tiers[$i]['key'], 'tier' => $i, 'tiers' => $lower ? range(0, $i) : array($i), 'value' => $tiers[$i]['value']);
            }
            return $out;
        }
        $top = end($reached);
        return array(array('slot' => 'one', 'tier' => $top, 'tiers' => $lower ? $reached : array($top), 'value' => $tiers[$top]['value']));
    }

    /** Importe que cuenta (en la moneda por defecto, como los umbrales) */
    public function value($cart, array $rule)
    {
        return $this->manager->cartValue($cart, $rule);
    }

    // =========================================================================
    // REGALOS DISPONIBLES
    // =========================================================================

    protected function hasCombinations($idProduct)
    {
        return (bool)Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product` = ' . (int)$idProduct);
    }

    /** Unidades disponibles de una combinación (sin límite si no se controla stock o se vende sin stock) */
    protected function stockOk($idProduct, $idAttr, $needed)
    {
        if (!Configuration::get('PS_STOCK_MANAGEMENT')) {
            return true;
        }
        if (Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock((int)$idProduct))) {
            return true;
        }
        return (int)StockAvailable::getQuantityAvailableByProduct((int)$idProduct, (int)$idAttr) >= (int)$needed;
    }

    /**
     * Combinaciones que se pueden elegir de un regalo, con stock: [id => nombre]. Producto sin combinaciones: [0 => ''].
     * Vacío = no se puede dar (desactivado, sin stock...).
     *
     * @param int $extra unidades que ya ocupa el propio regalo en el carrito (al comprobar el elegido)
     */
    public function options(array $level, $extra = 0)
    {
        $idProduct = (int)$level['id_product'];
        $qty = max(1, (int)$level['gift_qty']);
        $product = new Product($idProduct, false, (int)$this->context->language->id);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return array();
        }
        $idLang = (int)$this->context->language->id;
        $cart = $this->context->cart;
        $products = is_object($cart) && Validate::isLoadedObject($cart) ? $cart->getProducts() : array();
        if (!$this->hasCombinations($idProduct)) {
            $paid = max(0, $this->manager->lineQty($products, $idProduct, 0) - (int)$extra);
            return $this->stockOk($idProduct, 0, $paid + $qty) ? array(0 => '') : array();
        }
        $allowed = isset($level['allowed']) ? $level['allowed'] : JosraGiftRule::parseIds(isset($level['allowed_attributes']) ? $level['allowed_attributes'] : '');
        $rows = Db::getInstance()->executeS('SELECT `id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute`
            WHERE `id_product` = ' . $idProduct . ($allowed ? ' AND `id_product_attribute` IN (' . implode(',', array_map('intval', $allowed)) . ')' : '') . '
            ORDER BY `default_on` DESC, `id_product_attribute` ASC');
        $out = array();
        foreach ((array)$rows as $r) {
            $idAttr = (int)$r['id_product_attribute'];
            $chosenAttr = isset($level['_chosen_attr']) ? (int)$level['_chosen_attr'] : 0;
            $paid = max(0, $this->manager->lineQty($products, $idProduct, $idAttr) - ($extra && $chosenAttr === $idAttr ? (int)$extra : 0));
            if ($this->stockOk($idProduct, $idAttr, $paid + $qty)) {
                $name = JosraGiftRule::getCombinationName($idAttr, $idLang);
                $out[$idAttr] = $name !== '' ? $name : '#' . $idAttr;
            }
        }
        return $out;
    }

    // =========================================================================
    // ELEGIR Y QUITAR
    // =========================================================================

    /**
     * El cliente elige un regalo para un hueco.
     *
     * @return true|string motivo si no se puede
     */
    public function choose($cart, $slot, $idLevel, $idAttr)
    {
        $m = Module::getInstanceByName('josra_giftproduct');
        $rule = $this->rule($cart);
        if (!$rule) {
            return $m->l('Ahora mismo no hay regalos para elegir.', 'josragiftchoice');
        }
        $tiers = $this->tiers($rule);
        $slots = $this->slots($rule, $tiers, $this->value($cart, $rule));
        $theSlot = null;
        foreach ($slots as $s) {
            if ($s['slot'] === (string)$slot) {
                $theSlot = $s;
            }
        }
        if (!$theSlot) {
            return $m->l('Tu pedido ya no llega a ese regalo.', 'josragiftchoice');
        }
        $level = null;
        foreach ($theSlot['tiers'] as $ti) {
            foreach ($tiers[$ti]['options'] as $lv) {
                if ((int)$lv['id_josra_gift_rule_level'] === (int)$idLevel) {
                    $level = $lv;
                }
            }
        }
        if (!$level) {
            return $m->l('Ese regalo no está disponible para tu pedido.', 'josragiftchoice');
        }
        $rows = self::rows($cart->id);
        $current = isset($rows[$theSlot['slot']]) ? $rows[$theSlot['slot']] : null;
        $level['_chosen_attr'] = $current && (int)$current['id_josra_gift_rule_level'] === (int)$idLevel ? $current['id_product_attribute'] : 0;
        $opts = $this->options($level, $current && (int)$current['id_product'] === (int)$level['id_product'] ? $current['gift_qty'] : 0);
        if (!$opts) {
            return $m->l('Ese regalo se ha agotado. Elige otro.', 'josragiftchoice');
        }
        $idAttr = (int)$idAttr;
        if (!isset($opts[$idAttr])) {
            $keys = array_keys($opts);
            $idAttr = (int)$keys[0];
        }
        // El mismo regalo no puede ocupar dos huecos (se juntarían en una línea)
        foreach ($rows as $k => $r) {
            if ($k !== $theSlot['slot'] && $r['id_product'] === (int)$level['id_product'] && $r['id_product_attribute'] === $idAttr) {
                return $m->l('Ya tienes ese regalo elegido. Elige otro distinto.', 'josragiftchoice');
            }
        }
        if ($current && $current['id_product'] === (int)$level['id_product'] && $current['id_product_attribute'] === $idAttr
            && (int)$current['id_josra_gift_rule_level'] === (int)$level['id_josra_gift_rule_level']) {
            return true;
        }
        if ($current) {
            $this->take($cart, $current);
        }
        $qty = max(1, (int)$level['gift_qty']);
        if (!$this->manager->updateQty($cart, (int)$level['id_product'], $idAttr, $qty, 'up')) {
            return $m->l('No se ha podido añadir el regalo al carrito. Inténtalo otra vez.', 'josragiftchoice');
        }
        $row = array(
            'id_cart' => (int)$cart->id,
            'slot' => $theSlot['slot'],
            'id_josra_gift_rule' => (int)$rule['id_josra_gift_rule'],
            'id_josra_gift_rule_level' => (int)$level['id_josra_gift_rule_level'],
            'id_product' => (int)$level['id_product'],
            'id_product_attribute' => $idAttr,
            'gift_qty' => $qty,
            'id_specific_price' => 0,
            'id_cart_rule' => 0,
        );
        $this->manager->applyPrice($cart, $row);
        self::saveRow($row);
        JosraGiftManager::log('cart=' . $cart->id . ' elegido ' . $row['id_product'] . '/' . $idAttr . ' en ' . $theSlot['slot']);
        return true;
    }

    public function remove($cart, $slot)
    {
        $rows = self::rows($cart->id);
        if (isset($rows[(string)$slot])) {
            $this->take($cart, $rows[(string)$slot]);
            self::deleteRow($cart->id, (string)$slot);
        }
        return true;
    }

    /** Saca del carrito las unidades regaladas de una fila y su precio a cero o su vale */
    protected function take($cart, array $row)
    {
        $line = $this->manager->lineQty($cart->getProducts(true), $row['id_product'], $row['id_product_attribute']);
        $remove = min((int)$row['gift_qty'], (int)$line);
        if ($remove > 0) {
            $this->manager->updateQty($cart, $row['id_product'], $row['id_product_attribute'], $remove, 'down');
        }
        JosraGiftCart::releaseDiscount($row, $cart->id);
    }

    // =========================================================================
    // SINCRONIZACIÓN (cada vez que cambia el carrito)
    // =========================================================================

    public function sync($cart)
    {
        $rows = self::rows($cart->id);
        if (!$rows) {
            return;
        }
        $rule = $this->rule($cart);
        $valid = array();
        $tiers = array();
        if ($rule) {
            $tiers = $this->tiers($rule);
            // el importe no cuenta los regalos (a precio cero o con su vale)
            foreach ($this->slots($rule, $tiers, $this->value($cart, $rule)) as $s) {
                $valid[$s['slot']] = $s;
            }
        }
        $products = $cart->getProducts(true);
        foreach ($rows as $slot => $row) {
            $why = '';
            if (!$rule || (int)$row['id_josra_gift_rule'] !== (int)$rule['id_josra_gift_rule'] || !isset($valid[$slot])) {
                $why = 'tier';
            } else {
                $level = null;
                foreach ($valid[$slot]['tiers'] as $ti) {
                    foreach ($tiers[$ti]['options'] as $lv) {
                        if ((int)$lv['id_josra_gift_rule_level'] === $row['id_josra_gift_rule_level']) {
                            $level = $lv;
                        }
                    }
                }
                if (!$level) {
                    $why = 'tier';
                } else {
                    $line = $this->manager->lineQty($products, $row['id_product'], $row['id_product_attribute']);
                    if ($line <= 0) {
                        // lo ha quitado el cliente desde el carrito: puede volver a elegir
                        JosraGiftCart::releaseDiscount($row, $cart->id);
                        self::deleteRow($cart->id, $slot);
                        continue;
                    }
                    $level['_chosen_attr'] = $row['id_product_attribute'];
                    $opts = $this->options($level, $row['gift_qty']);
                    if (!isset($opts[$row['id_product_attribute']])) {
                        $why = 'stock';
                    }
                }
            }
            if ($why !== '') {
                $name = $this->label($row);
                $this->take($cart, $row);
                self::deleteRow($cart->id, $slot);
                $this->setNotice($why === 'stock'
                    ? sprintf(Module::getInstanceByName('josra_giftproduct')->l('Se ha agotado tu regalo (%s). Elige otro.', 'josragiftchoice'), $name)
                    : sprintf(Module::getInstanceByName('josra_giftproduct')->l('Tu pedido ya no llega al regalo %s: lo hemos quitado.', 'josragiftchoice'), $name));
                continue;
            }
            // mismo regalo: el precio a cero o el vale al día (por si cambian las unidades pagadas o el precio)
            $before = $row['id_specific_price'] . '-' . $row['id_cart_rule'];
            $this->manager->applyPrice($cart, $row);
            if ($row['id_specific_price'] . '-' . $row['id_cart_rule'] !== $before) {
                self::saveRow($row);
            }
        }
    }

    public function label(array $row)
    {
        $idLang = (int)$this->context->language->id;
        $name = JosraGiftRule::getProductName($row['id_product'], $idLang);
        if ($row['id_product_attribute']) {
            $c = JosraGiftRule::getCombinationName($row['id_product_attribute'], $idLang);
            if ($c !== '') {
                $name .= ' (' . $c . ')';
            }
        }
        return ((int)$row['gift_qty'] > 1 ? (int)$row['gift_qty'] . ' x ' : '') . $name;
    }

    protected function setNotice($text)
    {
        $c = $this->context->cookie;
        if (is_object($c)) {
            $c->josra_gift_choice_notice = Tools::substr($text, 0, 250);
        }
    }

    public function popNotice()
    {
        $c = $this->context->cookie;
        if (!is_object($c) || empty($c->josra_gift_choice_notice)) {
            return '';
        }
        $text = (string)$c->josra_gift_choice_notice;
        $c->josra_gift_choice_notice = '';
        return $text;
    }

    // =========================================================================
    // LO QUE SE PINTA (carrito y checkout)
    // =========================================================================

    protected function image($idProduct, $idAttr)
    {
        $idLang = (int)$this->context->language->id;
        $img = $idAttr && method_exists('Product', 'getCombinationImageById') ? Product::getCombinationImageById((int)$idAttr, $idLang) : null;
        $cover = $img ? $img : Product::getCover((int)$idProduct);
        if (!$cover) {
            return '';
        }
        $rewrite = Db::getInstance()->getValue('SELECT `link_rewrite` FROM `' . _DB_PREFIX_ . 'product_lang`
            WHERE `id_product` = ' . (int)$idProduct . ' AND `id_lang` = ' . $idLang . ' AND `id_shop` = ' . (int)$this->context->shop->id);
        $type = method_exists('ImageType', 'getFormattedName') ? ImageType::getFormattedName('small') : 'small_default';
        return $this->context->link->getImageLink($rewrite, (int)$idProduct . '-' . (int)$cover['id_image'], $type);
    }

    protected function card(array $lv, $chosen = null)
    {
        $idProduct = (int)$lv['id_product'];
        $lv['_chosen_attr'] = $chosen ? $chosen['id_product_attribute'] : 0;
        $opts = $this->options($lv, $chosen ? $chosen['gift_qty'] : 0);
        $attr = $chosen ? $chosen['id_product_attribute'] : ($opts ? (int)key($opts) : 0);
        $m = Module::getInstanceByName('josra_giftproduct');
        $price = Product::getPriceStatic($idProduct, true, $attr ?: null, 2, null, false, false);
        return array(
            'id_level' => (int)$lv['id_josra_gift_rule_level'],
            'name' => isset($lv['product_name']) && $lv['product_name'] !== '' ? $lv['product_name'] : JosraGiftRule::getProductName($idProduct),
            'qty' => max(1, (int)$lv['gift_qty']),
            'image' => $this->image($idProduct, $attr),
            'value' => $price > 0 ? $m->formatPrice($price) : '',
            'options' => $opts,
            'n_options' => count($opts),
            'option_name' => isset($opts[$attr]) ? $opts[$attr] : '',
            'attr' => $attr,
            'available' => (bool)$opts,
            'chosen' => (bool)$chosen,
        );
    }

    /**
     * Todo lo que necesita el bloque: huecos con sus regalos, el siguiente tramo y el aviso pendiente.
     * Null si no hay ninguna regla «el cliente elige» para este carrito.
     */
    public function view($cart)
    {
        if (!is_object($cart) || !Validate::isLoadedObject($cart)) {
            return null;
        }
        $rule = $this->rule($cart);
        if (!$rule) {
            return null;
        }
        $tiers = $this->tiers($rule);
        if (!$tiers) {
            return null;
        }
        $value = $this->value($cart, $rule);
        $slots = $this->slots($rule, $tiers, $value);
        $rows = self::rows($cart->id);
        $m = Module::getInstanceByName('josra_giftproduct');
        $currency = (int)$cart->id_currency;

        $outSlots = array();
        foreach ($slots as $s) {
            $chosen = isset($rows[$s['slot']]) ? $rows[$s['slot']] : null;
            $cards = array();
            // primero los del tramo más alto
            foreach (array_reverse($s['tiers']) as $ti) {
                foreach ($tiers[$ti]['options'] as $lv) {
                    $isChosen = $chosen && (int)$lv['id_josra_gift_rule_level'] === $chosen['id_josra_gift_rule_level'];
                    $card = $this->card($lv, $isChosen ? $chosen : null);
                    if ($card['available'] || $isChosen) {
                        $cards[] = $card;
                    }
                }
            }
            if (!$cards) {
                continue;
            }
            $outSlots[] = array(
                'slot' => $s['slot'],
                'from' => $m->formatPrice($this->manager->fromDefaultCurrency($s['value'], $currency), $currency),
                'chosen' => (bool)$chosen,
                'cards' => $cards,
            );
        }

        // siguiente tramo: lo que falta y sus regalos (bloqueados)
        $next = null;
        $prev = 0.0;
        foreach ($tiers as $t) {
            if ($value + 0.000001 < $t['value']) {
                $cards = array();
                foreach ($t['options'] as $lv) {
                    $card = $this->card($lv);
                    if ($card['available']) {
                        $cards[] = $card;
                    }
                }
                if ($cards) {
                    $span = max(0.000001, $t['value'] - $prev);
                    $next = array(
                        'missing' => $m->formatPrice($this->manager->fromDefaultCurrency($t['value'] - $value, $currency), $currency),
                        'from' => $m->formatPrice($this->manager->fromDefaultCurrency($t['value'], $currency), $currency),
                        'percent' => (int)max(0, min(100, floor(($value - $prev) / $span * 100))),
                        'cards' => array_slice($cards, 0, 8),
                        'more' => max(0, count($cards) - 8),
                    );
                }
                break;
            }
            $prev = $t['value'];
        }
        if (!$outSlots && !$next) {
            return null;
        }
        return array(
            'slots' => $outSlots,
            'multi' => count($outSlots) > 1,
            'next' => $next,
            'notice' => $this->popNotice(),
        );
    }

    // =========================================================================
    // PEDIDO
    // =========================================================================

    public function onOrderValidated($order, $cart)
    {
        $idCart = (int)(is_object($cart) ? $cart->id : $cart);
        $rows = self::rows($idCart);
        if (!$rows) {
            return;
        }
        $rulesUsed = array();
        foreach ($rows as $row) {
            $detail = Db::getInstance()->getRow('SELECT `id_order_detail`, `product_name`, `product_quantity`, `original_product_price`
                FROM `' . _DB_PREFIX_ . 'order_detail`
                WHERE `id_order` = ' . (int)$order->id . ' AND `product_id` = ' . (int)$row['id_product'] . '
                  AND `product_attribute_id` = ' . (int)$row['id_product_attribute']);
            if (!$detail) {
                continue;
            }
            $exists = Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'josra_gift_order_log`
                WHERE `id_order` = ' . (int)$order->id . ' AND `id_product` = ' . (int)$row['id_product'] . '
                  AND `id_product_attribute` = ' . (int)$row['id_product_attribute']);
            if ($exists) {
                continue;
            }
            $free = min((int)$row['gift_qty'], (int)$detail['product_quantity']);
            $rule = new JosraGiftRule((int)$row['id_josra_gift_rule']);
            $log = new JosraGiftOrderLog();
            $log->id_order = (int)$order->id;
            $log->id_cart = $idCart;
            $log->id_josra_gift_rule = (int)$row['id_josra_gift_rule'];
            $log->id_josra_gift_rule_level = (int)$row['id_josra_gift_rule_level'];
            $log->id_product = (int)$row['id_product'];
            $log->id_product_attribute = (int)$row['id_product_attribute'];
            $log->gift_qty = $free;
            $log->product_name = Tools::substr($detail['product_name'], 0, 255);
            $log->original_price = (float)$detail['original_product_price'];
            $log->rule_name = Validate::isLoadedObject($rule) ? $rule->name : '';
            $log->status = 'valid';
            $log->date_add = date('Y-m-d H:i:s');
            $log->add();
            $rulesUsed[(int)$row['id_josra_gift_rule']] = true;

            if (Configuration::get('JOSRA_GIFT_MARK_ORDER_LINE')) {
                $label = Configuration::get('JOSRA_GIFT_BADGE_TEXT', (int)$order->id_lang);
                $label = $label ? $label : 'REGALO';
                if ($free < (int)$detail['product_quantity']) {
                    $label = $free . ' x ' . $label;
                }
                Db::getInstance()->update('order_detail',
                    array('product_name' => pSQL(Tools::substr($detail['product_name'] . ' - ' . $label, 0, 255))),
                    'id_order_detail = ' . (int)$detail['id_order_detail']);
            }
            // el vale (modo vale) se queda: ya es parte del pedido
            JosraGiftCart::deleteSpecificPrice($row['id_specific_price'], $idCart);
        }
        // «Regalos disponibles» se cuenta por pedidos
        foreach (array_keys($rulesUsed) as $idRule) {
            JosraGiftRule::addUses($idRule, 1);
        }
    }
}
