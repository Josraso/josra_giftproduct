<?php
/**
 * Motor del regalo: decide qué regalo toca, lo mete o lo quita del carrito
 * y lo deja gratis de una de estas dos formas (ajuste «Cómo sale el regalo»):
 * - a precio cero, con un precio específico ligado al carrito (como siempre);
 * - a su precio, con un vale (regla de carrito nativa) que descuenta su importe.
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
        JosraGiftChoice::ensureSchema();
        self::$running[$idCart] = true;
        try {
            $this->doSync($cart);
            // regalos que elige el cliente (reglas «el cliente elige»)
            $choice = new JosraGiftChoice($this, $this->context);
            $choice->sync($cart);
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
            JosraGiftCart::releaseDiscount($state, $cart->id);
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
                JosraGiftCart::releaseDiscount($state, $cart->id);
                JosraGiftCart::save($state);
            } elseif ($state['id_product'] || $state['id_specific_price'] || $state['id_cart_rule']) {
                JosraGiftCart::releaseDiscount($state, $cart->id);
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
        JosraGiftCart::releaseDiscount($state, $cart->id);

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
            if ($rule['trigger_type'] === JosraGiftRule::TRIGGER_CHOICE) {
                continue;
            }
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
            if ($rule['trigger_type'] === JosraGiftRule::TRIGGER_PRODUCT || $rule['trigger_type'] === JosraGiftRule::TRIGGER_CHOICE) {
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

    public function getApplicableRules($cart)
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
    public function cartValue($cart, array $rule)
    {
        $withTax = $rule['calc_mode'] === 'with_tax';
        // Sin caché: el carrito puede haber cambiado en esta misma petición.
        $value = (float)$cart->getOrderTotal($withTax, Cart::ONLY_PRODUCTS, null, null, false);
        // Regalos a su precio (modo vale): sus unidades regaladas no cuentan para el tramo,
        // igual que no cuentan cuando van a precio cero.
        $couponMode = JosraGiftCart::priceMode() === 'coupon';
        if ($couponMode) {
            $value -= $this->giftLinesValue($cart, $withTax);
        }
        if (!empty($rule['after_discounts'])) {
            // descuentos y cupones del carrito (sin los de transporte gratis ni los vales de los regalos)
            $value -= (float)$cart->getOrderTotal($withTax, Cart::ONLY_DISCOUNTS, null, null, false)
                - (float)$this->freeShippingDiscount($cart, $withTax)
                - ($couponMode ? $this->ownCouponsValue($cart, $withTax) : 0.0);
        }
        $value = max(0, $value);
        if ((int)$rule['include_shipping']) {
            $value += (float)$cart->getOrderTotal($withTax, Cart::ONLY_SHIPPING, null, null, false);
        }
        return $this->toDefaultCurrency($value, (int)$cart->id_currency);
    }

    /** Lo que valen en el carrito las unidades regaladas (automático y elegidas), con o sin IVA */
    protected function giftLinesValue($cart, $withTax)
    {
        $gifts = array();
        $state = JosraGiftCart::get($cart->id);
        if ($state && $state['in_cart'] && $state['id_product']) {
            $gifts[] = $state;
        }
        foreach (JosraGiftChoice::rows($cart->id) as $row) {
            $gifts[] = $row;
        }
        if (!$gifts) {
            return 0.0;
        }
        $products = $cart->getProducts();
        $total = 0.0;
        foreach ($gifts as $g) {
            $line = $this->cartLine($products, $g['id_product'], $g['id_product_attribute']);
            if ($line) {
                $free = min((int)$g['gift_qty'], (int)$line['cart_quantity']);
                $total += $free * (float)($withTax ? $line['price_wt'] : $line['price']);
            }
        }
        return $total;
    }

    /** Lo que descuentan en el carrito los vales de los regalos */
    protected function ownCouponsValue($cart, $withTax)
    {
        $ids = $this->ownCouponIds($cart->id);
        if (!$ids) {
            return 0.0;
        }
        $total = 0.0;
        foreach ((array)$cart->getCartRules() as $r) {
            if (in_array((int)$r['id_cart_rule'], $ids)) {
                $total += (float)($withTax ? $r['value_real'] : $r['value_tax_exc']);
            }
        }
        return $total;
    }

    /** Ids de los vales de regalo de un carrito (automático y elegidos) */
    public function ownCouponIds($idCart)
    {
        $ids = array();
        $state = JosraGiftCart::get($idCart);
        if ($state && $state['id_cart_rule']) {
            $ids[] = (int)$state['id_cart_rule'];
        }
        foreach (JosraGiftChoice::rows($idCart) as $row) {
            if ($row['id_cart_rule']) {
                $ids[] = (int)$row['id_cart_rule'];
            }
        }
        return $ids;
    }

    /** Fila del carrito de un producto y combinación (sin personalizaciones) */
    public function cartLine(array $products, $idProduct, $idAttr)
    {
        foreach ($products as $p) {
            if ((int)$p['id_product'] === (int)$idProduct
                && (int)$p['id_product_attribute'] === (int)$idAttr
                && empty($p['id_customization'])) {
                return $p;
            }
        }
        return null;
    }

    /** Parte de los descuentos que es el transporte gratis (no resta del importe de los productos) */
    protected function freeShippingDiscount($cart, $withTax)
    {
        $total = 0.0;
        foreach ((array)$cart->getCartRules() as $r) {
            if (!empty($r['free_shipping'])) {
                $total += (float)$cart->getOrderTotal($withTax, Cart::ONLY_SHIPPING, null, null, false);
                break;
            }
        }
        return $total;
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

    public function lineQty(array $products, $idProduct, $idAttr)
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
        // los regalos que ha elegido el cliente tampoco cuentan
        if (!empty($state['id_cart'])) {
            foreach (JosraGiftChoice::rows((int)$state['id_cart']) as $row) {
                $total -= (int)$row['gift_qty'];
            }
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

        // Regalos disponibles en la regla (se cuentan pedidos, como en 1.x).
        if ((int)$rule['max_uses'] > 0 && (int)$rule['uses_count'] >= (int)$rule['max_uses']) {
            return array('code' => 'uses', 'given' => (int)$rule['uses_count'], 'max' => (int)$rule['max_uses']);
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
        // Producto con combinaciones sin combinación: el precio a cero valdría
        // para todas sus combinaciones (también las que compra el cliente).
        if (!$idAttr && $this->hasCombinations($idProduct)) {
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
            if ($this->hasCombinations((int)$level['id_product'])) {
                $level['id_product_attribute'] = (int)Product::getDefaultAttribute((int)$level['id_product']);
                $level['combination_name'] = $level['id_product_attribute']
                    ? JosraGiftRule::getCombinationName($level['id_product_attribute'], (int)$this->context->language->id)
                    : '';
            }
        }
        return $level;
    }

    protected function hasCombinations($idProduct)
    {
        return (bool)Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product` = ' . (int)$idProduct
        );
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

    public function updateQty($cart, $idProduct, $idAttr, $qty, $op)
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
        JosraGiftCart::releaseDiscount($state, $cart->id);
        $state['in_cart'] = 0;
        $this->flushCaches($cart);
    }

    /**
     * Deja gratis las unidades regaladas según el ajuste «Cómo sale el regalo».
     * Si se ha cambiado el ajuste con carritos abiertos, quita lo del otro modo.
     */
    public function applyPrice($cart, array &$state)
    {
        if (!isset($state['id_cart_rule'])) {
            $state['id_cart_rule'] = 0;
        }
        if (JosraGiftCart::priceMode() === 'coupon') {
            if ($state['id_specific_price']) {
                JosraGiftCart::deleteSpecificPrice($state['id_specific_price'], $cart->id);
                $state['id_specific_price'] = 0;
                $this->flushCaches($cart);
            }
            $this->applyCoupon($cart, $state);
            return;
        }
        if ($state['id_cart_rule']) {
            JosraGiftCart::deleteCoupon($state['id_cart_rule'], $cart->id);
            $state['id_cart_rule'] = 0;
            $this->flushCaches($cart);
        }
        $this->applyZeroPrice($cart, $state);
    }

    /**
     * Modo vale: el regalo va a su precio y un vale (regla de carrito nativa,
     * con código secreto y solo para ese carrito) descuenta lo que valen las
     * unidades regaladas. El vale va limitado al producto regalado, así que
     * PrestaShop le aplica el IVA (y el recargo) de ese producto.
     */
    public function applyCoupon($cart, array &$state)
    {
        $products = $cart->getProducts(true);
        $line = $this->cartLine($products, $state['id_product'], $state['id_product_attribute']);
        $lineQty = $this->lineQty($products, $state['id_product'], $state['id_product_attribute']);
        $free = $line ? min((int)$state['gift_qty'], $lineQty) : 0;
        // Igual que en el modo precio a cero: nunca sin combinación en un producto con combinaciones.
        if (!(int)$state['id_product_attribute'] && $this->hasCombinations((int)$state['id_product'])) {
            $free = 0;
        }
        $amount = $free > 0 ? Tools::ps_round($free * (float)$line['price_wt'], 2) : 0;
        if ($amount <= 0) {
            JosraGiftCart::deleteCoupon($state['id_cart_rule'], $cart->id);
            $state['id_cart_rule'] = 0;
            $this->flushCaches($cart);
            return;
        }

        $cr = null;
        if ($state['id_cart_rule']) {
            $cr = new CartRule((int)$state['id_cart_rule']);
            if (!JosraGiftCart::isOwnCoupon($cr, $cart->id) || JosraGiftCart::couponUsed($cr->id)) {
                $cr = null;
            }
        }
        $names = $this->couponNames($state, $free);
        $changed = true;
        if ($cr) {
            $changed = abs((float)$cr->reduction_amount - $amount) > 0.001
                || (int)$cr->reduction_currency !== (int)$cart->id_currency
                || (int)$cr->reduction_product !== (int)$state['id_product']
                || !(int)$cr->active || (int)$cr->quantity < 1
                || strtotime($cr->date_to) < time() + 15 * 86400;
            foreach ($names as $idLang => $name) {
                if (!isset($cr->name[$idLang]) || $cr->name[$idLang] !== $name) {
                    $changed = true;
                }
            }
        } else {
            $cr = new CartRule();
            $cr->code = 'REGALO-' . (int)$cart->id . '-' . Tools::strtoupper(Tools::passwdGen(10));
        }

        if ($changed) {
            $cr->name = $names;
            $cr->description = JosraGiftCart::couponTag($cart->id);
            $cr->id_customer = 0;
            $cr->date_from = date('Y-m-d H:i:s', time() - 86400);
            $cr->date_to = date('Y-m-d H:i:s', time() + 60 * 86400);
            $cr->quantity = 1;
            $cr->quantity_per_user = 1;
            $cr->priority = 0;
            $cr->partial_use = 0;
            $cr->minimum_amount = 0;
            $cr->minimum_amount_tax = 0;
            $cr->minimum_amount_currency = (int)$cart->id_currency;
            $cr->minimum_amount_shipping = 0;
            $cr->country_restriction = 0;
            $cr->carrier_restriction = 0;
            $cr->group_restriction = 0;
            $cr->cart_rule_restriction = 0;
            $cr->product_restriction = 0;
            $cr->shop_restriction = 0;
            $cr->free_shipping = 0;
            $cr->reduction_percent = 0;
            $cr->reduction_amount = $amount;
            $cr->reduction_tax = 1;
            $cr->reduction_currency = (int)$cart->id_currency;
            $cr->reduction_product = (int)$state['id_product'];
            if (property_exists($cr, 'reduction_exclude_special')) {
                $cr->reduction_exclude_special = 0;
            }
            $cr->gift_product = 0;
            $cr->gift_product_attribute = 0;
            $cr->highlight = 0;
            $cr->active = 1;
            $ok = $cr->id ? $cr->update() : $cr->add();
            if (!$ok) {
                self::log('cart=' . $cart->id . ' no se pudo guardar el vale del regalo ' . $state['id_product']);
                $state['id_cart_rule'] = 0;
                return;
            }
        }
        $state['id_cart_rule'] = (int)$cr->id;
        $this->attachCoupon($cart, (int)$cr->id);
        $this->flushCaches($cart);
    }

    /** Mete el vale en el carrito si no está (también si el cliente lo ha quitado) */
    public function attachCoupon($cart, $idCartRule)
    {
        $inCart = Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'cart_cart_rule`
             WHERE `id_cart` = ' . (int)$cart->id . ' AND `id_cart_rule` = ' . (int)$idCartRule
        );
        if (!$inCart) {
            $cart->addCartRule((int)$idCartRule);
            JosraGiftCart::flushCartRuleCache($cart->id);
        }
    }

    /**
     * Red de seguridad antes de cada página (también las de los módulos de pago):
     * si el cliente ha quitado el vale de un regalo, se vuelve a meter tal cual
     * (si no, pagaría el regalo). El importe se recalcula en la siguiente sincronización.
     * Solo con el id del carrito: a esta hora PrestaShop aún no ha cargado el carrito en el contexto.
     */
    public function reattachCoupons($idCart)
    {
        $idCart = (int)$idCart;
        if (!$idCart || JosraGiftCart::priceMode() !== 'coupon') {
            return;
        }
        $ids = $this->ownCouponIds($idCart);
        if (!$ids) {
            return;
        }
        $have = array();
        foreach ((array)Db::getInstance()->executeS(
            'SELECT `id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_cart_rule` WHERE `id_cart` = ' . $idCart
        ) as $r) {
            $have[] = (int)$r['id_cart_rule'];
        }
        $missing = array_diff($ids, $have);
        if (!$missing || Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . $idCart)) {
            return;
        }
        foreach ($missing as $id) {
            $cr = new CartRule((int)$id);
            if (JosraGiftCart::isOwnCoupon($cr, $idCart) && (int)$cr->active && (int)$cr->quantity > 0) {
                Db::getInstance()->insert('cart_cart_rule', array('id_cart_rule' => (int)$id, 'id_cart' => $idCart));
                self::log('cart=' . $idCart . ' vale del regalo ' . (int)$id . ' quitado por el cliente: se vuelve a meter');
            }
        }
        JosraGiftCart::flushCartRuleCache($idCart);
    }

    /** Nombre del vale en cada idioma: «REGALO: 2 x Producto (Talla M)» */
    protected function couponNames(array $state, $free)
    {
        $names = array();
        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int)$lang['id_lang'];
            $badge = Configuration::get('JOSRA_GIFT_BADGE_TEXT', $idLang);
            $badge = $badge ? $badge : 'REGALO';
            $name = JosraGiftRule::getProductName((int)$state['id_product'], $idLang);
            if ((int)$state['id_product_attribute']) {
                $comb = JosraGiftRule::getCombinationName((int)$state['id_product_attribute'], $idLang);
                if ($comb !== '') {
                    $name .= ' (' . $comb . ')';
                }
            }
            $text = $badge . ': ' . ((int)$free > 1 ? (int)$free . ' x ' : '') . $name;
            $names[$idLang] = Tools::substr(trim(strip_tags($text)), 0, 250);
        }
        return $names;
    }

    /**
     * Modo precio a cero: precio específico del carrito, porcentaje de descuento
     * sobre la línea para que las unidades de regalo salgan gratis y el resto se cobre.
     * En porcentaje no depende del IVA ni de la dirección del cliente.
     */
    public function applyZeroPrice($cart, array &$state)
    {
        $products = $cart->getProducts(true);
        $lineQty = $this->lineQty($products, $state['id_product'], $state['id_product_attribute']);
        $free = min((int)$state['gift_qty'], $lineQty);
        // Nunca un precio a cero sin combinación en un producto con combinaciones:
        // se aplicaría a todas las combinaciones del carrito.
        if (!(int)$state['id_product_attribute'] && $this->hasCombinations((int)$state['id_product'])) {
            $free = 0;
        }
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
        $this->logAutoGift($order, $idCart, $state);
    }

    protected function logAutoGift($order, $idCart, array $state)
    {
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
        if (Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'josra_gift_order_log` WHERE `id_order` = ' . (int)$order->id
            . ' AND `id_product` = ' . (int)$state['id_product'] . ' AND `id_product_attribute` = ' . (int)$state['id_product_attribute'])) {
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

        JosraGiftRule::addUses((int)$state['id_josra_gift_rule'], 1);

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

        // El precio ya está guardado en la línea del pedido. El vale (modo vale)
        // se queda: ya es parte del pedido.
        JosraGiftCart::deleteSpecificPrice($state['id_specific_price'], $idCart);
    }

    /**
     * Pedido cancelado/reembolsado/error: devuelve las unidades a la regla.
     * Si vuelve a un estado válido, las descuenta otra vez.
     */
    public function onOrderStatusChanged($idOrder, $idNewState)
    {
        $logs = JosraGiftOrderLog::getAllByOrderId((int)$idOrder);
        if (!$logs) {
            return;
        }
        $cancelStates = array_filter(array(
            (int)Configuration::get('PS_OS_CANCELED'),
            (int)Configuration::get('PS_OS_REFUND'),
            (int)Configuration::get('PS_OS_ERROR'),
        ));
        $isCancel = in_array((int)$idNewState, $cancelStates);
        // «Regalos disponibles» va por pedidos: una vez por regla aunque el pedido lleve varios regalos de ella
        $done = array();
        foreach ($logs as $log) {
            $idRule = (int)$log['id_josra_gift_rule'];
            if ($isCancel && $log['status'] === 'valid') {
                if (!isset($done[$idRule])) {
                    JosraGiftRule::addUses($idRule, -1);
                }
                JosraGiftOrderLog::setStatus((int)$log['id_josra_gift_order_log'], 'cancelled');
                $done[$idRule] = true;
            } elseif (!$isCancel && $log['status'] === 'cancelled') {
                if (!isset($done[$idRule])) {
                    JosraGiftRule::addUses($idRule, 1);
                }
                JosraGiftOrderLog::setStatus((int)$log['id_josra_gift_order_log'], 'valid');
                $done[$idRule] = true;
            }
        }
    }
}
