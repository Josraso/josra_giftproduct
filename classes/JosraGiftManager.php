<?php
/**
 * Motor del regalo: decide qué regalo toca, lo mete o lo quita del carrito
 * y le aplica el precio a cero con un precio específico ligado al carrito.
 * Compatible PHP 5.6+ / PrestaShop 1.6 - 9.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraGiftManager
{
    /** @var Context */
    protected $context;

    /** @var array carritos que se están sincronizando ahora (evita bucles) */
    protected static $running = array();

    public function __construct($context = null)
    {
        $this->context = $context ? $context : Context::getContext();
    }

    // =========================================================================
    // LOG (solo con el modo depuración activado)
    // =========================================================================

    public static function log($msg)
    {
        if (!Configuration::get('JOSRA_GIFT_DEBUG')) {
            return;
        }
        $dir = is_dir(_PS_ROOT_DIR_ . '/var/logs') ? _PS_ROOT_DIR_ . '/var/logs' : _PS_ROOT_DIR_ . '/log';
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        @file_put_contents(
            $dir . '/josra_giftproduct.log',
            date('Y-m-d H:i:s') . ' ' . $msg . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    // =========================================================================
    // SINCRONIZACIÓN
    // =========================================================================

    /**
     * Pone el carrito en el estado correcto: regalo que toca, en su cantidad
     * y a precio cero. Seguro de llamar muchas veces (es idempotente).
     */
    public function sync($cart)
    {
        if (!is_object($cart) || !Validate::isLoadedObject($cart)) {
            return;
        }
        $idCart = (int)$cart->id;
        if (!empty(self::$running[$idCart])) {
            return;
        }
        if (defined('_PS_ADMIN_DIR_') || $cart->orderExists()) {
            return;
        }
        self::$running[$idCart] = true;
        try {
            $this->doSync($cart);
        } catch (Exception $e) {
            self::log('ERROR cart=' . $idCart . ' ' . $e->getMessage());
        }
        unset(self::$running[$idCart]);
    }

    protected function doSync($cart)
    {
        $state = JosraGiftCart::get($cart->id);
        $products = $cart->getProducts(true);
        if (!$state && empty($products)) {
            return;
        }
        if (!$state) {
            $state = JosraGiftCart::blank($cart->id);
        }

        $lineQty = $state['id_product']
            ? $this->lineQty($products, $state['id_product'], $state['id_product_attribute'])
            : 0;

        // El cliente ha quitado la línea del regalo: queda como rechazado
        // hasta que pulse "Recuperar regalo".
        if ($state['in_cart'] && $lineQty === 0) {
            self::log('cart=' . $cart->id . ' regalo quitado por el cliente');
            JosraGiftCart::deleteSpecificPrice($state['id_specific_price'], $cart->id);
            $state['id_specific_price'] = 0;
            $state['in_cart'] = 0;
            $state['declined'] = 1;
        }

        $freeInCart = $state['in_cart'] ? min($state['gift_qty'], $lineQty) : 0;
        $target = $this->computeTarget($cart, $products, $state, $freeInCart);

        if (!$target) {
            if ($state['in_cart']) {
                $this->removeGiftUnits($cart, $state, $lineQty);
            }
            if ($state['declined']) {
                // Se recuerda el rechazo mientras viva el carrito.
                $state['in_cart'] = 0;
                $state['id_specific_price'] = 0;
                JosraGiftCart::save($state);
            } elseif ($state['id_product'] || $state['id_specific_price']) {
                JosraGiftCart::delete($cart->id);
            }
            return;
        }

        $level = $target['level'];
        $sameGift = (int)$state['id_product'] === (int)$level['id_product']
            && (int)$state['id_product_attribute'] === (int)$level['id_product_attribute'];

        if ($state['declined']) {
            // Rechazado: no se mete, solo se actualiza qué regalo podría recuperar.
            $state['id_josra_gift_rule'] = (int)$target['rule']['id_josra_gift_rule'];
            $state['id_josra_gift_rule_level'] = (int)$level['id_josra_gift_rule_level'];
            $state['id_product'] = (int)$level['id_product'];
            $state['id_product_attribute'] = (int)$level['id_product_attribute'];
            $state['gift_qty'] = (int)$level['gift_qty'];
            JosraGiftCart::save($state);
            return;
        }

        if ($state['in_cart'] && $sameGift) {
            // Mismo regalo: ajustar unidades si se cambió la regla y recalcular precio.
            $diff = (int)$level['gift_qty'] - (int)$state['gift_qty'];
            if ($diff > 0) {
                $this->updateQty($cart, $level['id_product'], $level['id_product_attribute'], $diff, 'up');
            } elseif ($diff < 0) {
                $this->updateQty($cart, $level['id_product'], $level['id_product_attribute'], -$diff, 'down');
            }
            $state['id_josra_gift_rule'] = (int)$target['rule']['id_josra_gift_rule'];
            $state['id_josra_gift_rule_level'] = (int)$level['id_josra_gift_rule_level'];
            $state['gift_qty'] = (int)$level['gift_qty'];
            $this->applyPrice($cart, $state);
            JosraGiftCart::save($state);
            return;
        }

        // Regalo nuevo o distinto: quitar el anterior y meter el que toca.
        $previousTrigger = null;
        if ($state['in_cart']) {
            $previousTrigger = $this->levelTrigger($state['id_josra_gift_rule_level']);
            $this->removeGiftUnits($cart, $state, $lineQty);
        }

        $added = $this->updateQty($cart, $level['id_product'], $level['id_product_attribute'], (int)$level['gift_qty'], 'up');
        if (!$added) {
            self::log('cart=' . $cart->id . ' no se pudo añadir el producto ' . $level['id_product']);
            JosraGiftCart::delete($cart->id);
            return;
        }

        $state['id_josra_gift_rule'] = (int)$target['rule']['id_josra_gift_rule'];
        $state['id_josra_gift_rule_level'] = (int)$level['id_josra_gift_rule_level'];
        $state['id_product'] = (int)$level['id_product'];
        $state['id_product_attribute'] = (int)$level['id_product_attribute'];
        $state['gift_qty'] = (int)$level['gift_qty'];
        $state['in_cart'] = 1;
        $state['declined'] = 0;
        $state['id_specific_price'] = 0;

        if ($target['fallback']) {
            $state['notice'] = 'fallback';
            $state['notice_data'] = json_encode(array(
                'gift' => $this->giftLabel($level),
                'wanted' => $this->giftLabel($target['wanted']),
            ));
        } elseif ($previousTrigger !== null && (float)$level['trigger_value'] > $previousTrigger) {
            $state['notice'] = 'upgraded';
            $state['notice_data'] = json_encode(array('gift' => $this->giftLabel($level)));
        } elseif ($previousTrigger === null) {
            $state['notice'] = 'unlocked';
            $state['notice_data'] = json_encode(array('gift' => $this->giftLabel($level)));
        }

        $this->applyPrice($cart, $state);
        JosraGiftCart::save($state);
        self::log('cart=' . $cart->id . ' regalo ' . $level['id_product'] . '/' . $level['id_product_attribute'] . ' x' . $level['gift_qty']);
    }

    /**
     * Quita el rechazo y vuelve a sincronizar (botón "Recuperar regalo").
     */
    public function restore($cart)
    {
        $state = JosraGiftCart::get($cart->id);
        if ($state && $state['declined']) {
            $state['declined'] = 0;
            $state['in_cart'] = 0;
            $state['id_product'] = 0;
            $state['id_product_attribute'] = 0;
            JosraGiftCart::save($state);
        }
        $this->sync($cart);
    }

    // =========================================================================
    // QUÉ REGALO TOCA
    // =========================================================================

    /**
     * Recorre las reglas por prioridad; la primera que se cumple decide.
     * Dentro de la regla se coge el tramo más alto alcanzado y, si no hay
     * stock o unidades, se baja al tramo anterior.
     */
    protected function computeTarget($cart, array $products, array $state, $freeInCart)
    {
        foreach ($this->getApplicableRules($cart) as $rule) {
            $levels = array_map(array($this, 'normalizeLevel'), $rule['levels']);
            $reached = -1;

            if ($rule['trigger_type'] === JosraGiftRule::TRIGGER_PRODUCT) {
                $levels = array($levels[0]);
                $min = max(1, (int)$rule['trigger_min_qty']);
                $qty = $this->productQty($products, (int)$rule['id_trigger_product'], $state, $freeInCart);
                if ((int)$rule['id_trigger_product'] && $qty >= $min) {
                    $reached = 0;
                }
            } else {
                $value = $rule['trigger_type'] === JosraGiftRule::TRIGGER_QUANTITY
                    ? $this->paidUnits($products, $state, $freeInCart)
                    : $this->cartValue($cart, $rule);
                foreach ($levels as $i => $lv) {
                    if ($value + 0.000001 >= (float)$lv['trigger_value']) {
                        $reached = $i;
                    }
                }
            }

            if ($reached < 0) {
                self::log('regla ' . $rule['id_josra_gift_rule'] . ' (' . $rule['name'] . '): no se alcanza ningún tramo'
                    . (isset($value) ? ' (valor ' . $value . ')' : ''));
                unset($value);
                continue;
            }
            unset($value);
            for ($i = $reached; $i >= 0; --$i) {
                if ($this->isGiftAvailable($levels[$i], $rule, $products, $state, $freeInCart)) {
                    return array(
                        'rule' => $rule,
                        'level' => $levels[$i],
                        'wanted' => $levels[$reached],
                        'fallback' => $i < $reached,
                    );
                }
            }
        }
        return null;
    }

    /**
     * Siguiente objetivo para el mensaje "te faltan X": el primer tramo no
     * alcanzado de la regla que manda (o de la primera regla por importe o
     * cantidad si aún no se ha conseguido ninguno).
     */
    public function getNextGoal($cart)
    {
        if (!Validate::isLoadedObject($cart)) {
            return null;
        }
        $products = $cart->getProducts();
        $state = JosraGiftCart::get($cart->id);
        if (!$state) {
            $state = JosraGiftCart::blank($cart->id);
        }
        $lineQty = $state['id_product'] ? $this->lineQty($products, $state['id_product'], $state['id_product_attribute']) : 0;
        $freeInCart = $state['in_cart'] ? min($state['gift_qty'], $lineQty) : 0;

        $rules = $this->getApplicableRules($cart);
        if ($state['in_cart'] && $state['id_josra_gift_rule']) {
            // Ya hay regalo: solo se anima a subir de tramo en la misma regla
            // (otra regla no lo cambiaría si tiene menos prioridad).
            $current = array();
            foreach ($rules as $rule) {
                if ((int)$rule['id_josra_gift_rule'] === $state['id_josra_gift_rule']) {
                    $current[] = $rule;
                }
            }
            $rules = $current;
        }

        foreach ($rules as $rule) {
            if ($rule['trigger_type'] === JosraGiftRule::TRIGGER_PRODUCT) {
                continue;
            }
            $isQty = $rule['trigger_type'] === JosraGiftRule::TRIGGER_QUANTITY;
            $value = $isQty ? $this->paidUnits($products, $state, $freeInCart) : $this->cartValue($cart, $rule);
            $prev = 0.0;
            foreach ($rule['levels'] as $lv) {
                $threshold = (float)$lv['trigger_value'];
                if ($value + 0.000001 < $threshold) {
                    $span = max(0.000001, $threshold - $prev);
                    return array(
                        'rule' => $rule,
                        'level' => $lv,
                        'is_quantity' => $isQty,
                        'missing' => $isQty ? (int)ceil($threshold - $value) : $threshold - $value,
                        'percent' => (int)max(0, min(100, floor(($value - $prev) / $span * 100))),
                        'gift' => $this->giftLabel($lv),
                    );
                }
                $prev = $threshold;
            }
            // Si esta regla ya está en su tramo máximo no se busca en otras.
            if ($state['in_cart'] && (int)$rule['id_josra_gift_rule'] === $state['id_josra_gift_rule']) {
                return null;
            }
        }
        return null;
    }

    protected function getApplicableRules($cart)
    {
        $rules = JosraGiftRule::getActiveRules();
        if (empty($rules)) {
            return array();
        }
        $groups = $this->getCustomerGroups($cart);
        $zone = $this->getZone($cart);

        $out = array();
        foreach ($rules as $rule) {
            $r = $rule['restrictions'];
            if (!empty($r['group']) && !array_intersect($groups, $r['group'])) {
                self::log('regla ' . $rule['id_josra_gift_rule'] . ': grupo del cliente [' . implode(',', $groups) . '] no permitido');
                continue;
            }
            // Zona desconocida (visitante sin dirección ni país): no se bloquea.
            if (!empty($r['zone']) && $zone && !in_array($zone, $r['zone'])) {
                self::log('regla ' . $rule['id_josra_gift_rule'] . ': zona ' . $zone . ' no permitida');
                continue;
            }
            $out[] = $rule;
        }
        return $out;
    }

    protected function getCustomerGroups($cart)
    {
        $groups = array();
        if ((int)$cart->id_customer) {
            $groups = Customer::getGroupsStatic((int)$cart->id_customer);
        }
        if (empty($groups)) {
            $groups = array((int)Configuration::get('PS_UNIDENTIFIED_GROUP'));
        }
        return array_map('intval', $groups);
    }

    protected function getZone($cart)
    {
        $idAddress = (int)$cart->id_address_delivery;
        if ($idAddress) {
            $zone = (int)Address::getZoneById($idAddress);
            if ($zone) {
                return $zone;
            }
        }
        if (isset($this->context->country) && Validate::isLoadedObject($this->context->country)) {
            return (int)$this->context->country->id_zone;
        }
        return 0;
    }

    // =========================================================================
    // CÁLCULOS DEL CARRITO
    // =========================================================================

    /**
     * Importe del carrito en la moneda por defecto (los umbrales se guardan así).
     */
    protected function cartValue($cart, array $rule)
    {
        $withTax = $rule['calc_mode'] === 'with_tax';
        // Sin caché: el carrito puede haber cambiado en esta misma petición.
        $value = (float)$cart->getOrderTotal($withTax, Cart::ONLY_PRODUCTS, null, null, false);
        if ((int)$rule['include_shipping']) {
            $value += (float)$cart->getOrderTotal($withTax, Cart::ONLY_SHIPPING, null, null, false);
        }
        return $this->toDefaultCurrency($value, (int)$cart->id_currency);
    }

    protected function toDefaultCurrency($value, $idCurrency)
    {
        $default = (int)Configuration::get('PS_CURRENCY_DEFAULT');
        if (!$idCurrency || $idCurrency === $default) {
            return $value;
        }
        $currency = new Currency($idCurrency);
        if (!Validate::isLoadedObject($currency) || (float)$currency->conversion_rate <= 0) {
            return $value;
        }
        return $value / (float)$currency->conversion_rate;
    }

    public function fromDefaultCurrency($value, $idCurrency)
    {
        $default = (int)Configuration::get('PS_CURRENCY_DEFAULT');
        if (!$idCurrency || $idCurrency === $default) {
            return $value;
        }
        $currency = new Currency($idCurrency);
        if (!Validate::isLoadedObject($currency)) {
            return $value;
        }
        return $value * (float)$currency->conversion_rate;
    }

    protected function lineQty(array $products, $idProduct, $idAttr)
    {
        $qty = 0;
        foreach ($products as $p) {
            if ((int)$p['id_product'] === (int)$idProduct
                && (int)$p['id_product_attribute'] === (int)$idAttr
                && empty($p['id_customization'])) {
                $qty += (int)$p['cart_quantity'];
            }
        }
        return $qty;
    }

    /** Unidades pagadas del carrito (todas menos las regaladas). */
    protected function paidUnits(array $products, array $state, $freeInCart)
    {
        $total = 0;
        foreach ($products as $p) {
            $total += (int)$p['cart_quantity'];
        }
        return max(0, $total - (int)$freeInCart);
    }

    /** Unidades pagadas de un producto (todas sus combinaciones). */
    protected function productQty(array $products, $idProduct, array $state, $freeInCart)
    {
        $total = 0;
        foreach ($products as $p) {
            if ((int)$p['id_product'] === (int)$idProduct) {
                $total += (int)$p['cart_quantity'];
            }
        }
        if ($freeInCart && (int)$state['id_product'] === (int)$idProduct) {
            $total -= (int)$freeInCart;
        }
        return max(0, $total);
    }

    protected function isGiftAvailable(array $level, array $rule, array $products, array $state, $freeInCart)
    {
        $problem = $this->levelProblem($level, $rule, $products, $state, $freeInCart);
        if ($problem) {
            self::log('regla ' . $rule['id_josra_gift_rule'] . ' tramo ' . $level['id_josra_gift_rule_level']
                . ': no se puede dar (' . $problem['code'] . ' ' . json_encode($problem) . ')');
            return false;
        }
        return true;
    }

    /**
     * Por qué no se puede dar el regalo de un tramo (null si se puede).
     * También lo usa el back-office para avisar en la lista de reglas.
     * Códigos: no_product, uses, inactive, combination, stock.
     */
    public function levelProblem(array $level, array $rule, array $products = array(), array $state = array(), $freeInCart = 0)
    {
        $idProduct = (int)$level['id_product'];
        $idAttr = (int)$level['id_product_attribute'];
        $qty = max(1, (int)$level['gift_qty']);
        if (!$idProduct) {
            return array('code' => 'no_product');
        }

        // Unidades de regalo que quedan en la regla.
        if ((int)$rule['max_uses'] > 0 && ((int)$rule['max_uses'] - (int)$rule['uses_count']) < $qty) {
            return array('code' => 'uses', 'left' => max(0, (int)$rule['max_uses'] - (int)$rule['uses_count']), 'needed' => $qty);
        }

        $product = new Product($idProduct, false, (int)$this->context->language->id);
        if (!Validate::isLoadedObject($product)) {
            return array('code' => 'no_product');
        }
        if (!$product->active) {
            return array('code' => 'inactive');
        }
        if ($idAttr && !$this->combinationBelongs($idProduct, $idAttr)) {
            return array('code' => 'combination');
        }
        if (!Configuration::get('PS_STOCK_MANAGEMENT')) {
            return null;
        }
        if (Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock($idProduct))) {
            return null;
        }

        // El cliente puede tener ya unidades pagadas de ese mismo producto.
        $paidSameLine = $this->lineQty($products, $idProduct, $idAttr);
        if (!empty($state) && (int)$state['id_product'] === $idProduct && (int)$state['id_product_attribute'] === $idAttr) {
            $paidSameLine -= (int)$freeInCart;
        }
        $needed = max(0, $paidSameLine) + $qty;
        $stock = (int)StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttr);
        if ($stock < $needed) {
            return array('code' => 'stock', 'stock' => $stock, 'needed' => $needed);
        }
        return null;
    }

    /**
     * Tramo de un producto con combinaciones guardado sin combinación (reglas
     * antiguas): se usa la combinación por defecto del producto.
     */
    public function normalizeLevel(array $level)
    {
        if ((int)$level['id_product'] && !(int)$level['id_product_attribute']) {
            $hasCombinations = (bool)Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product` = ' . (int)$level['id_product']
            );
            if ($hasCombinations) {
                $level['id_product_attribute'] = (int)Product::getDefaultAttribute((int)$level['id_product']);
                $level['combination_name'] = $level['id_product_attribute']
                    ? JosraGiftRule::getCombinationName($level['id_product_attribute'], (int)$this->context->language->id)
                    : '';
            }
        }
        return $level;
    }

    protected function combinationBelongs($idProduct, $idAttr)
    {
        return (bool)Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute`
             WHERE `id_product_attribute` = ' . (int)$idAttr . ' AND `id_product` = ' . (int)$idProduct
        );
    }

    protected function levelTrigger($idLevel)
    {
        if (!$idLevel) {
            return null;
        }
        $v = Db::getInstance()->getValue(
            'SELECT `trigger_value` FROM `' . _DB_PREFIX_ . 'josra_gift_rule_level`
             WHERE `id_josra_gift_rule_level` = ' . (int)$idLevel
        );
        return $v === false ? 0.0 : (float)$v;
    }

    public function giftLabel(array $level)
    {
        $name = isset($level['product_name']) && $level['product_name'] !== ''
            ? $level['product_name']
            : JosraGiftRule::getProductName((int)$level['id_product']);
        if (!empty($level['combination_name'])) {
            $name .= ' (' . $level['combination_name'] . ')';
        }
        if ((int)$level['gift_qty'] > 1) {
            $name = (int)$level['gift_qty'] . ' x ' . $name;
        }
        return $name;
    }

    // =========================================================================
    // CARRITO Y PRECIO
    // =========================================================================

    protected function updateQty($cart, $idProduct, $idAttr, $qty, $op)
    {
        if ($qty <= 0) {
            return true;
        }
        $res = $cart->updateQty((int)$qty, (int)$idProduct, (int)$idAttr, false, $op, 0, null, false);
        $this->flushCaches($cart);
        return $res === true || (is_numeric($res) && (int)$res > 0);
    }

    /**
     * Quita del carrito solo las unidades regaladas (las pagadas se quedan).
     */
    protected function removeGiftUnits($cart, array &$state, $lineQty)
    {
        $remove = min((int)$state['gift_qty'], (int)$lineQty);
        if ($remove > 0) {
            $this->updateQty($cart, $state['id_product'], $state['id_product_attribute'], $remove, 'down');
        }
        JosraGiftCart::deleteSpecificPrice($state['id_specific_price'], $cart->id);
        $state['id_specific_price'] = 0;
        $state['in_cart'] = 0;
        $this->flushCaches($cart);
    }

    /**
     * Precio específico del carrito: porcentaje de descuento sobre la línea
     * para que las unidades de regalo salgan gratis y el resto se cobre.
     * En porcentaje no depende del IVA ni de la dirección del cliente.
     */
    protected function applyPrice($cart, array &$state)
    {
        $products = $cart->getProducts(true);
        $lineQty = $this->lineQty($products, $state['id_product'], $state['id_product_attribute']);
        $free = min((int)$state['gift_qty'], $lineQty);
        if ($free <= 0) {
            JosraGiftCart::deleteSpecificPrice($state['id_specific_price'], $cart->id);
            $state['id_specific_price'] = 0;
            return;
        }
        $reduction = $free >= $lineQty ? 1 : round($free / $lineQty, 6);

        $sp = null;
        if ($state['id_specific_price']) {
            $sp = new SpecificPrice((int)$state['id_specific_price']);
            if (!Validate::isLoadedObject($sp) || (int)$sp->id_cart !== (int)$cart->id) {
                $sp = null;
            }
        }
        if ($sp && $sp->reduction_type === 'percentage' && (float)$sp->price < 0
            && abs((float)$sp->reduction - $reduction) < 0.0000001) {
            return;
        }
        if (!$sp) {
            // Por si quedó uno huérfano del mismo carrito y producto.
            $this->deleteOwnLeftovers($cart, $state);
            $sp = new SpecificPrice();
        }
        // Se fijan siempre todos los campos: así también se corrigen los
        // precios de versiones anteriores del módulo que se adoptan al actualizar.
        $sp->id_product = (int)$state['id_product'];
        $sp->id_product_attribute = (int)$state['id_product_attribute'];
        $sp->id_cart = (int)$cart->id;
        $sp->id_shop = (int)$cart->id_shop;
        $sp->id_shop_group = 0;
        $sp->id_currency = 0;
        $sp->id_country = 0;
        $sp->id_group = 0;
        $sp->id_customer = 0;
        $sp->id_specific_price_rule = 0;
        $sp->price = -1;
        $sp->from_quantity = 1;
        $sp->reduction_type = 'percentage';
        $sp->reduction_tax = 1;
        $sp->from = '0000-00-00 00:00:00';
        $sp->to = '0000-00-00 00:00:00';
        $sp->reduction = $reduction;
        $ok = $sp->id ? $sp->update() : $sp->add();
        $state['id_specific_price'] = $ok ? (int)$sp->id : 0;
        $this->flushCaches($cart);
    }

    /**
     * Borra un precio específico idéntico al que crea el módulo que haya
     * quedado sin registrar (mismo carrito, producto, porcentaje, sin cliente).
     */
    protected function deleteOwnLeftovers($cart, array $state)
    {
        $ids = Db::getInstance()->executeS(
            'SELECT `id_specific_price` FROM `' . _DB_PREFIX_ . 'specific_price`
             WHERE `id_cart` = ' . (int)$cart->id . '
               AND `id_product` = ' . (int)$state['id_product'] . '
               AND `id_product_attribute` = ' . (int)$state['id_product_attribute'] . '
               AND `id_customer` = 0 AND `id_specific_price_rule` = 0
               AND `price` < 0 AND `reduction_type` = \'percentage\' AND `from_quantity` = 1'
        );
        foreach ((array)$ids as $row) {
            $old = new SpecificPrice((int)$row['id_specific_price']);
            if (Validate::isLoadedObject($old)) {
                $old->delete();
            }
        }
    }

    protected function flushCaches($cart)
    {
        if (method_exists('Product', 'flushPriceCache')) {
            Product::flushPriceCache();
        }
        if (method_exists('SpecificPrice', 'flushCache')) {
            $m = new ReflectionMethod('SpecificPrice', 'flushCache');
            if ($m->isStatic() && $m->isPublic()) {
                SpecificPrice::flushCache();
            }
        }
        $cart->getProducts(true);
    }

    // =========================================================================
    // PEDIDOS
    // =========================================================================

    /**
     * Registra el regalo del pedido, marca la línea y libera el precio específico.
     * Funciona aunque lo valide un TPV sin la sesión del cliente.
     */
    public function onOrderValidated($order, $cart)
    {
        if (!Validate::isLoadedObject($order) || !$cart) {
            return;
        }
        $idCart = (int)(is_object($cart) ? $cart->id : $cart);
        $state = JosraGiftCart::get($idCart);
        if (!$state || !$state['in_cart'] || !$state['id_product']) {
            return;
        }
        $detail = Db::getInstance()->getRow(
            'SELECT `id_order_detail`, `product_name`, `product_quantity`, `original_product_price`
             FROM `' . _DB_PREFIX_ . 'order_detail`
             WHERE `id_order` = ' . (int)$order->id . '
               AND `product_id` = ' . (int)$state['id_product'] . '
               AND `product_attribute_id` = ' . (int)$state['id_product_attribute']
        );
        if (!$detail) {
            return;
        }
        if (JosraGiftOrderLog::getByOrderId((int)$order->id)) {
            return;
        }
        $free = min((int)$state['gift_qty'], (int)$detail['product_quantity']);
        $rule = new JosraGiftRule((int)$state['id_josra_gift_rule']);

        $log = new JosraGiftOrderLog();
        $log->id_order = (int)$order->id;
        $log->id_cart = $idCart;
        $log->id_josra_gift_rule = (int)$state['id_josra_gift_rule'];
        $log->id_josra_gift_rule_level = (int)$state['id_josra_gift_rule_level'];
        $log->id_product = (int)$state['id_product'];
        $log->id_product_attribute = (int)$state['id_product_attribute'];
        $log->gift_qty = $free;
        $log->product_name = Tools::substr($detail['product_name'], 0, 255);
        $log->original_price = (float)$detail['original_product_price'];
        $log->rule_name = Validate::isLoadedObject($rule) ? $rule->name : '';
        $log->status = 'valid';
        $log->date_add = date('Y-m-d H:i:s');
        $log->add();

        JosraGiftRule::addUses((int)$state['id_josra_gift_rule'], $free);

        if (Configuration::get('JOSRA_GIFT_MARK_ORDER_LINE')) {
            $label = Configuration::get('JOSRA_GIFT_BADGE_TEXT', (int)$order->id_lang);
            if (!$label) {
                $label = 'REGALO';
            }
            if ($free < (int)$detail['product_quantity']) {
                $label = $free . ' x ' . $label;
            }
            Db::getInstance()->update(
                'order_detail',
                array('product_name' => pSQL(Tools::substr($detail['product_name'] . ' - ' . $label, 0, 255))),
                'id_order_detail = ' . (int)$detail['id_order_detail']
            );
        }

        // El precio ya está guardado en la línea del pedido.
        JosraGiftCart::deleteSpecificPrice($state['id_specific_price'], $idCart);
    }

    /**
     * Pedido cancelado/reembolsado/error: devuelve las unidades a la regla.
     * Si vuelve a un estado válido, las descuenta otra vez.
     */
    public function onOrderStatusChanged($idOrder, $idNewState)
    {
        $log = JosraGiftOrderLog::getByOrderId((int)$idOrder);
        if (!$log) {
            return;
        }
        $cancelStates = array_filter(array(
            (int)Configuration::get('PS_OS_CANCELED'),
            (int)Configuration::get('PS_OS_REFUND'),
            (int)Configuration::get('PS_OS_ERROR'),
        ));
        $isCancel = in_array((int)$idNewState, $cancelStates);
        if ($isCancel && $log['status'] === 'valid') {
            JosraGiftRule::addUses((int)$log['id_josra_gift_rule'], -(int)$log['gift_qty']);
            JosraGiftOrderLog::setStatus((int)$log['id_josra_gift_order_log'], 'cancelled');
        } elseif (!$isCancel && $log['status'] === 'cancelled') {
            JosraGiftRule::addUses((int)$log['id_josra_gift_rule'], (int)$log['gift_qty']);
            JosraGiftOrderLog::setStatus((int)$log['id_josra_gift_order_log'], 'valid');
        }
    }
}
