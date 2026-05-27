<?php
/**
 * JosraGiftManager - Núcleo lógico del módulo
 * Compatible PHP 5.6+
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraGiftManager
{
    /** @var Context */
    private $context;

    /** @var string '16'|'17'|'8x'|'9x' */
    private $psVersionBranch;

    public function __construct($context, $psVersionBranch)
    {
        $this->context         = $context;
        $this->psVersionBranch = $psVersionBranch;
    }

    public static function log($msg)
    {
        $f = dirname(dirname(__FILE__)) . '/josra_gift.log';
        file_put_contents($f, date('H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }

    // =========================================================================
    // MÉTODO PRINCIPAL
    // =========================================================================

    public function evaluateAndApply($cart)
    {
        // Evitar recursión: addGiftToCart llama a cart->updateQty() que vuelve
        // a disparar actionCartUpdateQuantity, lo que llamaría aquí de nuevo.
        static $running = false;
        if ($running) {
            return;
        }
        $running = true;
        $this->doEvaluateAndApply($cart);
        $running = false;
    }

    private function doEvaluateAndApply($cart)
    {
        self::log('--- evaluateAndApply cart=' . (int)$cart->id . ' customer=' . (int)$cart->id_customer);

        if (!Validate::isLoadedObject($cart)) {
            self::log('ABORT: cart not loaded');
            return;
        }

        $rules = JosraGiftRule::getActiveRules();
        self::log('getActiveRules: ' . count($rules) . ' reglas activas');
        if (empty($rules)) {
            self::log('ABORT: no hay reglas activas');
            $this->removeCurrentGift($cart);
            return;
        }

        $filteredRules = $this->filterRulesBySegmentation($rules, $cart);
        self::log('filteredRules: ' . count($filteredRules) . ' tras segmentación');
        if (empty($filteredRules)) {
            self::log('ABORT: ninguna regla pasa segmentación');
            $this->removeCurrentGift($cart);
            return;
        }

        $rule   = $filteredRules[0];
        self::log('regla activa: id=' . $rule['id_josra_gift_rule'] . ' name=' . $rule['name'] . ' trigger=' . $rule['trigger_type']);

        // Forzar refresco del caché de productos del carrito
        $cart->getProducts(true);

        $levels = JosraGiftRule::getLevelsByRuleId((int)$rule['id_josra_gift_rule']);
        self::log('niveles para la regla: ' . count($levels));
        foreach ($levels as $i => $lv) {
            self::log('  level[' . $i . ']: trigger=' . $lv['trigger_value'] . ' id_product=' . $lv['id_product'] . ' attr=' . $lv['id_product_attribute'] . ' qty=' . $lv['gift_qty']);
        }

        if ($rule['trigger_type'] === 'product') {
            $triggerProductId = (int)$rule['id_trigger_product'];
            $triggerMinQty    = max(1, (int)$rule['trigger_min_qty']);
            $cartQtyTrigger   = $this->getCartQtyForProduct($cart, $triggerProductId);
            self::log('trigger producto: id=' . $triggerProductId . ' min=' . $triggerMinQty . ' en carrito=' . $cartQtyTrigger);
            if (!$triggerProductId || $cartQtyTrigger < $triggerMinQty) {
                self::log('ABORT: producto disparador no alcanzado');
                $this->removeCurrentGift($cart);
                return;
            }
            $activeLevel = !empty($levels) ? $levels[0] : null;
        } else {
            $cartValue   = $this->getCartValue($cart, $rule);
            $cartQty     = $this->getCartQuantity($cart);
            self::log('cartValue=' . $cartValue . ' cartQty=' . $cartQty . ' calc_mode=' . $rule['calc_mode']);
            $activeLevel = $this->getActiveLevel($levels, $cartValue, $cartQty, $rule['trigger_type']);
        }

        self::log('activeLevel: ' . ($activeLevel ? 'trigger=' . $activeLevel['trigger_value'] . ' product=' . $activeLevel['id_product'] : 'NULL'));

        if (!$activeLevel) {
            self::log('ABORT: no hay nivel activo (umbral no alcanzado)');
            $this->removeCurrentGift($cart);
            return;
        }

        $giftProduct = $this->resolveGiftProduct($activeLevel);
        self::log('giftProduct: ' . ($giftProduct ? 'id=' . $giftProduct['id_product'] . ' attr=' . $giftProduct['id_product_attribute'] . ' qty=' . $giftProduct['gift_qty'] : 'NULL (sin stock o sin producto)'));

        if (!$giftProduct) {
            $fallbackLevel = $this->getFallbackLevel($levels, $activeLevel);
            if ($fallbackLevel) {
                $giftProduct = $this->resolveGiftProduct($fallbackLevel);
                if ($giftProduct) {
                    self::log('usando nivel fallback por stock agotado');
                    $this->context->cookie->josra_gift_fallback = 1;
                    $this->context->cookie->write();
                }
            }
        }

        if (!$giftProduct) {
            self::log('ABORT: giftProduct null incluso con fallback');
            $this->removeCurrentGift($cart);
            return;
        }

        $currentGift = $this->getCurrentGiftInCart($cart);
        self::log('currentGift en cookie: ' . ($currentGift ? 'product=' . $currentGift['product_id'] . ' attr=' . $currentGift['attr_id'] : 'null'));

        if ($currentGift) {
            if ((int)$currentGift['product_id'] === (int)$giftProduct['id_product']
                && (int)$currentGift['attr_id'] === (int)$giftProduct['id_product_attribute']) {
                self::log('regalo correcto ya en carrito, nada que hacer');
                return;
            }
            self::log('regalo diferente, quitando el actual');
            $this->removeCurrentGift($cart);
        }

        self::log('AÑADIENDO regalo al carrito...');
        $this->addGiftToCart($cart, $giftProduct, $rule, $activeLevel);
    }

    // =========================================================================
    // CÁLCULO DEL VALOR DEL CARRITO
    // =========================================================================

    private function getCartValue($cart, $rule)
    {
        $withTax         = ($rule['calc_mode'] === 'with_tax');
        $includeShipping = (bool)$rule['include_shipping'];

        if ($withTax) {
            $cartTotal = $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
        } else {
            $cartTotal = $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS);
        }

        if ($includeShipping) {
            if ($withTax) {
                $cartTotal += $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
            } else {
                $cartTotal += $cart->getOrderTotal(false, Cart::ONLY_SHIPPING);
            }
        }

        return (float)$cartTotal;
    }

    private function getCartQuantity($cart)
    {
        $products = $cart->getProducts();
        $total    = 0;
        foreach ($products as $product) {
            if ($this->isGiftCartRow($product)) {
                continue;
            }
            $total += (int)$product['cart_quantity'];
        }
        return $total;
    }

    private function getCartQtyForProduct($cart, $productId)
    {
        $products = $cart->getProducts();
        $total    = 0;
        foreach ($products as $product) {
            if ((int)$product['id_product'] === (int)$productId && !$this->isGiftCartRow($product)) {
                $total += (int)$product['cart_quantity'];
            }
        }
        return $total;
    }

    // =========================================================================
    // TRAMOS
    // =========================================================================

    private function getActiveLevel($levels, $cartValue, $cartQty, $triggerType)
    {
        $activeLevel = null;
        foreach ($levels as $level) {
            $threshold = (float)$level['trigger_value'];
            if ($triggerType === 'quantity') {
                $reached = ($cartQty >= $threshold);
            } else {
                $reached = ($cartValue >= $threshold);
            }
            if ($reached) {
                $activeLevel = $level;
            }
        }
        return $activeLevel;
    }

    private function getFallbackLevel($levels, $activeLevel)
    {
        $activeTrigger = (float)$activeLevel['trigger_value'];
        $fallback      = null;
        foreach ($levels as $level) {
            $threshold = (float)$level['trigger_value'];
            if ($threshold < $activeTrigger) {
                $fallback = $level;
            }
        }
        return $fallback;
    }

    // =========================================================================
    // PRODUCTO REGALO
    // =========================================================================

    private function resolveGiftProduct($level)
    {
        $productId = (int)(isset($level['id_product']) ? $level['id_product'] : 0);
        $attrId    = (int)(isset($level['id_product_attribute']) ? $level['id_product_attribute'] : 0);

        if (!$productId) {
            return null;
        }

        if ($this->isOutOfStock($productId, $attrId)) {
            return null;
        }

        return array(
            'id_product'           => $productId,
            'id_product_attribute' => $attrId,
            'gift_qty'             => max(1, (int)(isset($level['gift_qty']) ? $level['gift_qty'] : 1)),
        );
    }

    private function isOutOfStock($productId, $attrId)
    {
        $qty     = StockAvailable::getQuantityAvailableByProduct($productId, $attrId);
        $product = new Product($productId, false, $this->context->language->id);

        if (Validate::isLoadedObject($product)) {
            $oos = (int)$product->out_of_stock;
            self::log('isOutOfStock prod=' . $productId . ' attr=' . $attrId . ' qty=' . $qty . ' out_of_stock=' . $oos);
            if ($oos === 1) {
                return false;
            }
            if ($oos === 2) {
                if ((int)Configuration::get('PS_ORDER_OUT_OF_STOCK')) {
                    return false;
                }
            }
        } else {
            self::log('isOutOfStock prod=' . $productId . ' NO CARGADO qty=' . $qty);
        }

        return $qty <= 0;
    }

    // =========================================================================
    // AÑADIR / QUITAR REGALO
    // =========================================================================

    private function addGiftToCart($cart, $giftProduct, $rule, $level)
    {
        $productId = (int)$giftProduct['id_product'];
        $attrId    = (int)$giftProduct['id_product_attribute'];
        $giftQty   = max(1, (int)(isset($giftProduct['gift_qty']) ? $giftProduct['gift_qty'] : 1));

        self::log('addGiftToCart: updateQty(' . $giftQty . ', prod=' . $productId . ', attr=' . $attrId . ')');

        $result = $cart->updateQty(
            $giftQty,
            $productId,
            $attrId,
            false,
            'up',
            0,
            null
        );

        self::log('updateQty result: ' . var_export($result, true));

        if (!$result) {
            self::log('ERROR: updateQty devolvió false/0, regalo NO añadido');
            return;
        }

        $this->setGiftPrice($cart, $productId, $attrId);
        $this->markCartItemAsGift($cart, $productId, $attrId, (int)$rule['id_josra_gift_rule'], (int)$level['id_josra_gift_rule_level'], $giftQty);
        self::log('regalo añadido y marcado OK');

        $this->context->cookie->josra_gift_unlocked = 1;
        $this->context->cookie->write();
    }

    public function removeCurrentGift($cart)
    {
        $currentGift = $this->getCurrentGiftInCart($cart);
        if (!$currentGift) {
            return;
        }

        $giftQty = max(1, (int)(isset($currentGift['gift_qty']) ? $currentGift['gift_qty'] : 1));

        $cart->updateQty(
            $giftQty,
            (int)$currentGift['product_id'],
            (int)$currentGift['attr_id'],
            false,
            'down',
            0,
            null
        );

        $this->removeGiftPrice($cart, (int)$currentGift['product_id'], (int)$currentGift['attr_id']);
        $this->clearGiftMeta($cart);
    }

    // =========================================================================
    // PRECIO A CERO
    // =========================================================================

    private function setGiftPrice($cart, $productId, $attrId)
    {
        $this->removeGiftPrice($cart, $productId, $attrId);

        $sp = new SpecificPrice();
        $sp->id_product           = $productId;
        $sp->id_product_attribute = $attrId;
        $sp->id_cart              = (int)$cart->id;
        $sp->id_shop              = (int)$cart->id_shop;
        $sp->id_shop_group        = 0;
        $sp->id_currency          = 0;
        $sp->id_country           = 0;
        $sp->id_group             = 0;
        $sp->id_customer          = (int)$cart->id_customer;
        $sp->price                = '0.000000';
        $sp->from_quantity        = 1;
        $sp->reduction            = '0.000000';
        $sp->reduction_type       = 'amount';
        $sp->reduction_tax        = 1;
        $sp->from                 = '0000-00-00 00:00:00';
        $sp->to                   = '0000-00-00 00:00:00';
        $sp->add();
    }

    private function removeGiftPrice($cart, $productId, $attrId)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_specific_price`
             FROM `' . _DB_PREFIX_ . 'specific_price`
             WHERE `id_product` = ' . (int)$productId . '
               AND `id_product_attribute` = ' . (int)$attrId . '
               AND `id_cart` = ' . (int)$cart->id . '
               AND `price` = \'0.000000\''
        );
        if (!$rows) {
            return;
        }
        foreach ($rows as $row) {
            $sp = new SpecificPrice((int)$row['id_specific_price']);
            if (Validate::isLoadedObject($sp)) {
                $sp->delete();
            }
        }
    }

    // =========================================================================
    // METADATOS DE REGALO EN SESIÓN (cookie)
    // =========================================================================

    private function markCartItemAsGift($cart, $productId, $attrId, $ruleId, $levelId, $giftQty = 1)
    {
        $meta = array(
            'cart_id'    => (int)$cart->id,
            'product_id' => $productId,
            'attr_id'    => $attrId,
            'rule_id'    => $ruleId,
            'level_id'   => $levelId,
            'gift_qty'   => max(1, (int)$giftQty),
            'ts'         => time(),
        );
        $this->context->cookie->josra_gift_meta = json_encode($meta);
        $this->context->cookie->write();
    }

    private function clearGiftMeta($cart)
    {
        $this->context->cookie->josra_gift_meta = '';
        $this->context->cookie->write();
    }

    public function getCurrentGiftInCart($cart)
    {
        $metaRaw = isset($this->context->cookie->josra_gift_meta) ? $this->context->cookie->josra_gift_meta : '';
        if (empty($metaRaw)) {
            return null;
        }

        $meta = json_decode($metaRaw, true);
        if (!$meta || (int)(isset($meta['cart_id']) ? $meta['cart_id'] : 0) !== (int)$cart->id) {
            return null;
        }

        $expectedQty = max(1, (int)(isset($meta['gift_qty']) ? $meta['gift_qty'] : 1));
        $products = $cart->getProducts();
        foreach ($products as $p) {
            if ((int)$p['id_product'] === (int)$meta['product_id']
                && (int)$p['id_product_attribute'] === (int)$meta['attr_id']
                && (int)$p['cart_quantity'] >= $expectedQty) {
                return $meta;
            }
        }

        $this->clearGiftMeta($cart);
        return null;
    }

    public function isGiftProduct($cart, $productId, $attrId)
    {
        $current = $this->getCurrentGiftInCart($cart);
        if (!$current) {
            return false;
        }
        return (int)$current['product_id'] === (int)$productId
            && (int)$current['attr_id'] === (int)$attrId;
    }

    private function isGiftCartRow($productRow)
    {
        $metaRaw = isset($this->context->cookie->josra_gift_meta) ? $this->context->cookie->josra_gift_meta : '';
        if (empty($metaRaw)) {
            return false;
        }
        $meta = json_decode($metaRaw, true);
        if (!$meta) {
            return false;
        }
        return (int)$productRow['id_product'] === (int)(isset($meta['product_id']) ? $meta['product_id'] : 0)
            && (int)$productRow['id_product_attribute'] === (int)(isset($meta['attr_id']) ? $meta['attr_id'] : 0);
    }

    // =========================================================================
    // SEGMENTACIÓN
    // =========================================================================

    private function filterRulesBySegmentation($rules, $cart)
    {
        $customerId = (int)$cart->id_customer;
        $groupIds   = array();
        $zoneId     = 0;

        if ($customerId > 0) {
            $customer = new Customer($customerId);
            if (Validate::isLoadedObject($customer)) {
                $groupIds = $customer->getGroups();
            }
        }
        // Para visitantes no logueados, Customer::getGroupsStatic(0) devuelve
        // PS_UNIDENTIFIED_LANG_GROUP (grupo Visitante, ID 1 por defecto).
        if (empty($groupIds)) {
            $groupIds = $this->context->customer->getGroups();
        }
        if (empty($groupIds)) {
            $groupIds = array((int)Configuration::get('PS_UNIDENTIFIED_LANG_GROUP'));
        }

        if ($cart->id_address_delivery) {
            $address = new Address((int)$cart->id_address_delivery);
            if (Validate::isLoadedObject($address) && $address->id_country) {
                $country = new Country((int)$address->id_country);
                if (Validate::isLoadedObject($country)) {
                    $zoneId = (int)$country->id_zone;
                }
            }
        }

        self::log('segmentacion: groupIds=[' . implode(',', $groupIds) . '] zoneId=' . $zoneId);

        $filtered = array();
        foreach ($rules as $rule) {
            $ruleId       = (int)$rule['id_josra_gift_rule'];
            $restrictions = JosraGiftRule::getRestrictionsByRuleId($ruleId);

            if (empty($restrictions)) {
                $filtered[] = $rule;
                continue;
            }

            $groupRestrictions = array();
            $zoneRestrictions  = array();
            foreach ($restrictions as $r) {
                if ($r['restriction_type'] === 'group') {
                    $groupRestrictions[] = (int)$r['id_value'];
                } else {
                    $zoneRestrictions[] = (int)$r['id_value'];
                }
            }

            $groupOk = empty($groupRestrictions);
            $zoneOk  = empty($zoneRestrictions);

            if (!empty($groupRestrictions)) {
                $groupOk = !empty(array_intersect($groupIds, $groupRestrictions));
            }

            if (!empty($zoneRestrictions)) {
                if ($zoneId > 0) {
                    // Solo filtrar si ya se conoce la zona del cliente
                    $zoneOk = in_array($zoneId, $zoneRestrictions);
                } else {
                    // Sin dirección de entrega aún: no bloquear por zona desconocida
                    $zoneOk = true;
                }
            }

            self::log('regla ' . $ruleId . ': groupOk=' . (int)$groupOk . ' zoneOk=' . (int)$zoneOk);

            if ($groupOk && $zoneOk) {
                $filtered[] = $rule;
            }
        }

        return $filtered;
    }

    // =========================================================================
    // MENSAJE MOTIVACIONAL
    // =========================================================================

    public function getNextRuleInfo($cart)
    {
        $rules = JosraGiftRule::getActiveRules();
        if (empty($rules)) {
            return null;
        }

        $rules = $this->filterRulesBySegmentation($rules, $cart);
        if (empty($rules)) {
            return null;
        }

        $rule   = $rules[0];
        $levels = JosraGiftRule::getLevelsByRuleId((int)$rule['id_josra_gift_rule']);

        if ($rule['trigger_type'] === 'product') {
            return null;
        }

        $cartValue = $this->getCartValue($cart, $rule);
        $cartQty   = $this->getCartQuantity($cart);

        foreach ($levels as $level) {
            $threshold = (float)$level['trigger_value'];
            if ($rule['trigger_type'] === 'quantity') {
                $reached = ($cartQty >= $threshold);
            } else {
                $reached = ($cartValue >= $threshold);
            }

            if (!$reached) {
                if ($rule['trigger_type'] === 'quantity') {
                    $missing = $threshold - $cartQty;
                } else {
                    $missing = $threshold - $cartValue;
                }
                return array(
                    'rule'           => $rule,
                    'level'          => $level,
                    'amount_missing' => round($missing, 2),
                    'trigger_type'   => $rule['trigger_type'],
                );
            }
        }

        return null;
    }

    // =========================================================================
    // LOG EN PEDIDO
    // =========================================================================

    public function logOrderGift($order, $cart)
    {
        $currentGift = $this->getCurrentGiftInCart($cart);
        if (!$currentGift) {
            return;
        }

        $productId = (int)$currentGift['product_id'];
        $attrId    = (int)$currentGift['attr_id'];
        $ruleId    = (int)$currentGift['rule_id'];
        $levelId   = (int)$currentGift['level_id'];

        $product = new Product($productId, false, $this->context->language->id);
        $rule    = new JosraGiftRule($ruleId);

        if ($attrId) {
            $originalPrice = Product::getPriceStatic($productId, false, $attrId);
        } else {
            $originalPrice = Product::getPriceStatic($productId, false);
        }

        $log                        = new JosraGiftOrderLog();
        $log->id_order              = (int)$order->id;
        $log->id_cart               = (int)$cart->id;
        $log->id_josra_gift_rule    = $ruleId;
        $log->id_josra_gift_rule_level = $levelId;
        $log->id_product            = $productId;
        $log->id_product_attribute  = $attrId;
        $log->product_name          = Validate::isLoadedObject($product) ? $product->name : '';
        $log->original_price        = (float)$originalPrice;
        $log->rule_name             = Validate::isLoadedObject($rule) ? $rule->name : '';
        $log->date_add              = date('Y-m-d H:i:s');
        $log->add();

        JosraGiftRule::incrementUses($ruleId);
        $this->clearGiftMeta($cart);
    }

    // =========================================================================
    // DEVOLUCIÓN
    // =========================================================================

    public function handleReturn($orderReturn)
    {
        if (!isset($orderReturn->id_order)) {
            return;
        }
        $log = JosraGiftOrderLog::getByOrderId((int)$orderReturn->id_order);
        if ($log) {
            Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . 'josra_gift_rule`
                 SET `uses_count` = GREATEST(0, `uses_count` - 1)
                 WHERE `id_josra_gift_rule` = ' . (int)$log['id_josra_gift_rule']
            );
        }
    }
}
