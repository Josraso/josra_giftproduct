<?php
/**
 * josra_giftproduct - Producto de regalo automático
 *
 * Añade solo al carrito un producto de regalo según reglas (importe, número
 * de productos o un producto concreto), gratis y con etiqueta "REGALO": a precio
 * cero o a su precio con un vale de descuento nativo (ajuste «Cómo sale el regalo»).
 * Compatible con PrestaShop 1.6, 1.7, 8 y 9 (PHP 5.6+). Sin overrides.
 *
 * @author    josra
 * @copyright josra
 * @license   Comercial
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/JosraGiftRule.php';
require_once dirname(__FILE__) . '/classes/JosraGiftCart.php';
require_once dirname(__FILE__) . '/classes/JosraGiftOrderLog.php';
require_once dirname(__FILE__) . '/classes/JosraGiftManager.php';
require_once dirname(__FILE__) . '/classes/JosraGiftChoice.php';
require_once dirname(__FILE__) . '/classes/JosraGiftAdmin.php';
require_once dirname(__FILE__) . '/classes/Josra_giftproductUpdater.php';

class Josra_giftproduct extends Module
{
    /** @var JosraGiftManager */
    protected $manager;

    public function __construct()
    {
        $this->name = 'josra_giftproduct';
        $this->tab = 'pricing_promotion';
        $this->version = '2.2.0';
        $this->author = 'josra';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.6.0.4', 'max' => '9.99.99');

        parent::__construct();

        $this->displayName = $this->l('Producto de regalo automático');
        $this->description = $this->l('Regalos gratis según importe, cantidad o producto comprado, automáticos o a elegir por tramos: a precio cero o a su precio con un vale de descuento.');
        $this->confirmUninstall = $this->l('Se borrarán las reglas y las estadísticas de regalos. ¿Seguro?');
    }

    public function getManager()
    {
        if (!$this->manager) {
            $this->manager = new JosraGiftManager($this->context);
        }
        return $this->manager;
    }

    public static function isPs17()
    {
        return version_compare(_PS_VERSION_, '1.7.0.0', '>=');
    }

    public static function hasOrderGrid()
    {
        return version_compare(_PS_VERSION_, '1.7.7.0', '>=');
    }

    public function getUpdater()
    {
        return new Josra_giftproductUpdater($this);
    }

    /** Aviso periódico al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }

    // =========================================================================
    // INSTALACIÓN
    // =========================================================================

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->registerHooks()
            && $this->getUpdater()->registerHooks()
            && $this->installConfig()
            && Configuration::updateGlobalValue('JOSRA_GIFT_SCHEMA', JosraGiftChoice::SCHEMA);
    }

    public function uninstall()
    {
        $this->getUpdater()->notifyUninstall();
        Configuration::deleteByName('JOSRA_GIFTPRODUCT_UPD_TS');
        Configuration::deleteByName('JOSRA_GIFTPRODUCT_UPD_INFO');
        Configuration::deleteByName('JOSRA_GIFTPRODUCT_UPD_SU');
        Configuration::deleteByName('JOSRA_GIFTPRODUCT_UPD_LT');

        // Primero los precios a cero y los vales de los carritos abiertos: si no, seguirían gratis.
        JosraGiftCart::deleteAllSpecificPrices();
        JosraGiftChoice::deleteAllSpecificPrices();
        foreach (array('josra_gift_rule', 'josra_gift_rule_level', 'josra_gift_rule_restriction',
            'josra_gift_cart', 'josra_gift_order_log', 'josra_gift_rule_product', 'josra_gift_choice') as $table) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
        }
        foreach (JosraGiftAdmin::configKeys() as $key) {
            Configuration::deleteByName($key);
        }
        foreach (array('JOSRA_GIFT_CALC_MODE', 'JOSRA_GIFT_INCLUDE_SHIPPING', 'JOSRA_GIFT_SCHEMA') as $key) {
            Configuration::deleteByName($key);
        }
        return parent::uninstall();
    }

    public function installDb()
    {
        $sql = Tools::file_get_contents(dirname(__FILE__) . '/sql/install.sql');
        if (!$sql) {
            return false;
        }
        $sql = str_replace(array('PREFIX_', 'ENGINE_TYPE'), array(_DB_PREFIX_, _MYSQL_ENGINE_), $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }
        return true;
    }

    public function getHookList()
    {
        $hooks = array(
            'actionCartSave',
            'actionValidateOrder',
            'actionOrderStatusPostUpdate',
            'displayShoppingCartFooter',
            // vuelve a meter el vale del regalo si el cliente lo quita (modo vale)
            'actionDispatcher',
        );
        if (self::isPs17()) {
            $hooks[] = 'actionFrontControllerSetMedia';
            $hooks[] = 'actionObjectProductInCartDeleteAfter';
            $hooks[] = 'displayCartExtraProductActions';
        } else {
            $hooks[] = 'displayHeader';
            $hooks[] = 'actionAfterDeleteProductInCart';
        }
        if (self::hasOrderGrid()) {
            $hooks[] = 'actionOrderGridDefinitionModifier';
            $hooks[] = 'actionOrderGridQueryBuilderModifier';
            $hooks[] = 'displayAdminOrderSide';
        } else {
            $hooks[] = 'actionAdminOrdersListingFieldsModifier';
            $hooks[] = 'displayAdminOrder';
        }
        return $hooks;
    }

    public function registerHooks()
    {
        foreach ($this->getHookList() as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }
        return true;
    }

    public function installConfig()
    {
        $badge = array();
        $motiv = array();
        foreach (Language::getLanguages(false) as $lang) {
            $badge[(int)$lang['id_lang']] = 'REGALO';
            $motiv[(int)$lang['id_lang']] = 'Te faltan {amount} para llevarte de regalo: {gift}';
        }
        $defaults = array(
            'JOSRA_GIFT_BADGE_BG_COLOR' => '#e74c3c',
            'JOSRA_GIFT_BADGE_TEXT_COLOR' => '#ffffff',
            'JOSRA_GIFT_SHOW_PROGRESS' => 1,
            'JOSRA_GIFT_MARK_ORDER_LINE' => 1,
            'JOSRA_GIFT_LOW_STOCK' => 5,
            'JOSRA_GIFT_DEBUG' => 0,
            // como siempre: el regalo a precio cero
            'JOSRA_GIFT_PRICE_MODE' => 'zero',
        );
        foreach ($defaults as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }
        if (!Configuration::get('JOSRA_GIFT_BADGE_TEXT', (int)Configuration::get('PS_LANG_DEFAULT'))) {
            Configuration::updateValue('JOSRA_GIFT_BADGE_TEXT', $badge);
        }
        if (Configuration::get('JOSRA_GIFT_MOTIVATIONAL_TEXT', (int)Configuration::get('PS_LANG_DEFAULT')) === false) {
            Configuration::updateValue('JOSRA_GIFT_MOTIVATIONAL_TEXT', $motiv);
        }
        return true;
    }

    // =========================================================================
    // CONFIGURACIÓN (Módulos > Configurar)
    // =========================================================================

    public function getContent()
    {
        // Servidor de actualizaciones: botones del panel, hooks y upgrades pendientes
        $updater = $this->getUpdater();
        $output = $updater->processRequest();
        // tablas de la 2.1 aunque se haya actualizado subiendo los archivos
        JosraGiftChoice::ensureSchema();

        $admin = new JosraGiftAdmin($this);
        $forms = $admin->getContent();

        // Panel de actualizaciones: arriba si hay versión nueva, abajo si no
        $info = $updater->getInfo();
        $panel = $updater->renderPanel();

        return !empty($info['update_available'])
            ? $output . $panel . $forms
            : $output . $forms . $panel;
    }

    public function fetchTemplate($name)
    {
        return $this->display(__FILE__, 'views/templates/' . $name);
    }

    // =========================================================================
    // UTILIDADES
    // =========================================================================

    public function formatPrice($amount, $currency = null)
    {
        if (!$currency) {
            $currency = $this->context->currency;
        } elseif (!is_object($currency)) {
            $currency = Currency::getCurrencyInstance((int)$currency);
        }
        if (method_exists($this->context, 'getCurrentLocale')) {
            $locale = $this->context->getCurrentLocale();
            if ($locale && is_object($currency)) {
                try {
                    return $locale->formatPrice((float)$amount, $currency->iso_code);
                } catch (Exception $e) {
                    // sigue con el método antiguo
                }
            }
        }
        if (method_exists('Tools', 'displayPrice')) {
            return Tools::displayPrice((float)$amount, $currency);
        }
        return number_format((float)$amount, 2, ',', '.') . ' ' . (is_object($currency) ? $currency->sign : '');
    }

    public function formatDate($date, $full = false)
    {
        if (version_compare(_PS_VERSION_, '8.0.0', '>=')) {
            return Tools::displayDate($date, (bool)$full);
        }
        return Tools::displayDate($date, null, (bool)$full);
    }

    protected function getBadgeText($idLang = null)
    {
        $text = Configuration::get('JOSRA_GIFT_BADGE_TEXT', $idLang ? (int)$idLang : (int)$this->context->language->id);
        return $text ? $text : 'REGALO';
    }

    protected function isCartPage()
    {
        $controller = $this->context->controller;
        if (!is_object($controller)) {
            return false;
        }
        $page = isset($controller->php_self) ? (string)$controller->php_self : '';
        // También los checkouts de módulos (josracheckout: module-josracheckout-checkout).
        return in_array($page, array('cart', 'order', 'order-opc')) || strpos($page, 'checkout') !== false;
    }

    /**
     * Línea del regalo en el carrito (para pintar la etiqueta).
     */
    public function getGiftLine($cart)
    {
        if (!Validate::isLoadedObject($cart)) {
            return null;
        }
        $state = JosraGiftCart::get($cart->id);
        if (!$state || !$state['in_cart']) {
            return null;
        }
        $lineQty = 0;
        $name = '';
        foreach ($cart->getProducts() as $p) {
            if ((int)$p['id_product'] === $state['id_product']
                && (int)$p['id_product_attribute'] === $state['id_product_attribute']) {
                $lineQty += (int)$p['cart_quantity'];
                $name = $p['name'];
            }
        }
        if (!$lineQty) {
            return null;
        }
        $free = min($state['gift_qty'], $lineQty);
        return array(
            'id_product' => $state['id_product'],
            'id_product_attribute' => $state['id_product_attribute'],
            'name' => $name,
            'free' => $free,
            'line_qty' => $lineQty,
            'label' => $free < $lineQty ? $free . ' x ' . $this->getBadgeText() : $this->getBadgeText(),
        );
    }

    /**
     * Líneas de regalo del carrito para la etiqueta: el automático y los que ha elegido el cliente.
     */
    public function getGiftLines($cart)
    {
        $out = array();
        $auto = $this->getGiftLine($cart);
        if ($auto) {
            $out[] = $auto;
        }
        if (!Validate::isLoadedObject($cart) || !JosraGiftChoice::ensureSchema()) {
            return $out;
        }
        $products = $cart->getProducts();
        foreach (JosraGiftChoice::rows($cart->id) as $row) {
            $lineQty = $this->getManager()->lineQty($products, $row['id_product'], $row['id_product_attribute']);
            if (!$lineQty) {
                continue;
            }
            $free = min($row['gift_qty'], $lineQty);
            $out[] = array(
                'id_product' => $row['id_product'],
                'id_product_attribute' => $row['id_product_attribute'],
                'name' => JosraGiftRule::getProductName($row['id_product']),
                'free' => $free,
                'line_qty' => $lineQty,
                'label' => $free < $lineQty ? $free . ' x ' . $this->getBadgeText() : $this->getBadgeText(),
            );
        }
        return $out;
    }

    /**
     * Bloque del carrito: regalo rechazado + mensaje y barra de progreso.
     */
    public function renderCartBlock($cart)
    {
        $declined = null;
        $goal = null;
        $gift = null;
        if (Validate::isLoadedObject($cart)) {
            $state = JosraGiftCart::get($cart->id);
            if ($state && $state['declined'] && $state['id_product']) {
                $level = array(
                    'id_product' => $state['id_product'],
                    'gift_qty' => $state['gift_qty'],
                    'combination_name' => $state['id_product_attribute']
                        ? JosraGiftRule::getCombinationName($state['id_product_attribute'], (int)$this->context->language->id)
                        : '',
                );
                $declined = $this->getManager()->giftLabel($level);
            }
            if ($state && $state['in_cart']) {
                $level = array(
                    'id_product' => $state['id_product'],
                    'gift_qty' => $state['gift_qty'],
                    'combination_name' => $state['id_product_attribute']
                        ? JosraGiftRule::getCombinationName($state['id_product_attribute'], (int)$this->context->language->id)
                        : '',
                );
                $gift = $this->getManager()->giftLabel($level);
            }
            $text = Configuration::get('JOSRA_GIFT_MOTIVATIONAL_TEXT', (int)$this->context->language->id);
            if ($text && !$declined) {
                $next = $this->getManager()->getNextGoal($cart);
                if ($next) {
                    $amount = $next['is_quantity']
                        ? sprintf($next['missing'] == 1 ? $this->l('%d producto') : $this->l('%d productos'), $next['missing'])
                        : $this->formatPrice(
                            $this->getManager()->fromDefaultCurrency($next['missing'], (int)$cart->id_currency),
                            (int)$cart->id_currency
                        );
                    $goal = array(
                        'text' => str_replace(array('{amount}', '{gift}'), array($amount, $next['gift']), $text),
                        'percent' => $next['percent'],
                    );
                }
            }
        }

        $this->context->smarty->assign(array(
            'josra_declined' => $declined,
            'josra_goal' => $goal,
            'josra_gift' => $gift,
            'josra_show_progress' => (int)Configuration::get('JOSRA_GIFT_SHOW_PROGRESS'),
            'josra_ps17' => self::isPs17(),
        ));
        return $this->display(__FILE__, 'views/templates/hook/cart_block.tpl');
    }

    // =========================================================================
    // HOOKS: CARRITO
    // =========================================================================

    public function hookActionCartSave($params)
    {
        $cart = isset($params['cart']) ? $params['cart'] : $this->context->cart;
        $this->getManager()->sync($cart);
    }

    /** 1.7, 8, 9 */
    public function hookActionObjectProductInCartDeleteAfter($params)
    {
        $this->getManager()->sync($this->context->cart);
    }

    /** 1.6 */
    public function hookActionAfterDeleteProductInCart($params)
    {
        $this->getManager()->sync($this->context->cart);
    }

    /**
     * Antes de cada página de la tienda (también las de los módulos de pago):
     * si el cliente ha quitado el vale de un regalo, se vuelve a meter.
     * Solo hace algo en el modo «a su precio con un vale».
     */
    public function hookActionDispatcher($params)
    {
        if (defined('_PS_ADMIN_DIR_') || JosraGiftCart::priceMode() !== 'coupon') {
            return;
        }
        if (isset($params['controller_type']) && (int)$params['controller_type'] === (int)Dispatcher::FC_ADMIN) {
            return;
        }
        $cookie = $this->context->cookie;
        $idCart = is_object($cookie) && isset($cookie->id_cart) ? (int)$cookie->id_cart : 0;
        if ($idCart) {
            try {
                $this->getManager()->reattachCoupons($idCart);
            } catch (Exception $e) {
                JosraGiftManager::log('ERROR vale cart=' . $idCart . ' ' . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // HOOKS: FRONT
    // =========================================================================

    /** 1.7, 8, 9 */
    public function hookActionFrontControllerSetMedia($params)
    {
        $this->frontAssets();
    }

    /** 1.6 */
    public function hookDisplayHeader($params)
    {
        $this->frontAssets();
    }

    protected function frontAssets()
    {
        $controller = $this->context->controller;
        if (!is_object($controller) || ($controller instanceof ModuleFrontController && isset($controller->module)
            && is_object($controller->module) && $controller->module->name === $this->name)) {
            return;
        }
        // Peticiones AJAX de otros módulos (p. ej. josracheckout): ni recursos ni avisos,
        // que se consumirían sin llegar a verse.
        if (Tools::getValue('ajax') || (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && Tools::strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            return;
        }
        $cart = $this->context->cart;
        $hasCart = is_object($cart) && Validate::isLoadedObject($cart);

        JosraGiftChoice::ensureSchema();
        // Red de seguridad en carrito y checkout: reglas caducadas, stock, etc.
        if ($hasCart && $this->isCartPage()) {
            $this->getManager()->sync($cart);
        }

        if (self::isPs17()) {
            $controller->registerStylesheet('josra-gift', 'modules/' . $this->name . '/views/css/front.css', array('media' => 'all', 'priority' => 150));
            $controller->registerJavascript('josra-gift', 'modules/' . $this->name . '/views/js/front.js', array('position' => 'bottom', 'priority' => 150));
        } else {
            $controller->addCSS($this->_path . 'views/css/front.css');
            $controller->addJS($this->_path . 'views/js/front.js');
        }

        $notice = $hasCart ? JosraGiftCart::popNotice($cart->id) : null;
        Media::addJsDef(array('josraGift' => array(
            'ajaxUrl' => $this->context->link->getModuleLink($this->name, 'ajax', array(), true),
            'ps17' => self::isPs17(),
            'gift' => $hasCart ? $this->getGiftLine($cart) : null,
            'gifts' => $hasCart ? $this->getGiftLines($cart) : array(),
            'notice' => $notice ? $this->noticeText($notice) : '',
            'bg' => Configuration::get('JOSRA_GIFT_BADGE_BG_COLOR'),
            'fg' => Configuration::get('JOSRA_GIFT_BADGE_TEXT_COLOR'),
        )));
    }

    public function noticeText($notice)
    {
        $gift = isset($notice['data']['gift']) ? $notice['data']['gift'] : '';
        switch ($notice['type']) {
            case 'unlocked':
                return sprintf($this->l('¡Has conseguido un regalo: %s!'), $gift);
            case 'upgraded':
                return sprintf($this->l('¡Regalo mejorado! Ahora te llevas: %s'), $gift);
            case 'fallback':
                $wanted = isset($notice['data']['wanted']) ? $notice['data']['wanted'] : '';
                return sprintf($this->l('Se ha agotado %s. Te llevas de regalo: %s'), $wanted, $gift);
        }
        return '';
    }

    public function hookDisplayShoppingCartFooter($params)
    {
        return $this->renderCartBlock($this->context->cart) . $this->renderChoiceBlock($this->context->cart, 'cart');
    }

    public function getChoice()
    {
        return new JosraGiftChoice($this->getManager(), $this->context);
    }

    /**
     * Bloque «Elige tu regalo» (reglas «el cliente elige»): en el carrito y en el resumen de josracheckout.
     * Vacío si no hay ninguna regla que valga para este carrito.
     *
     * @param string $where cart|checkout
     */
    public function renderChoiceBlock($cart, $where = 'cart')
    {
        if (!JosraGiftChoice::ensureSchema()) {
            return '';
        }
        $view = $this->getChoice()->view($cart);
        $this->context->smarty->assign(array(
            'jgc' => $view,
            'jgc_where' => $where === 'checkout' ? 'checkout' : 'cart',
            'jgc_bg' => Configuration::get('JOSRA_GIFT_BADGE_BG_COLOR'),
            'jgc_fg' => Configuration::get('JOSRA_GIFT_BADGE_TEXT_COLOR'),
        ));
        return $this->display(__FILE__, 'views/templates/hook/choice_block.tpl');
    }

    /** Etiqueta en la línea del carrito (1.7, 8, 9). */
    public function hookDisplayCartExtraProductActions($params)
    {
        if (empty($params['product'])) {
            return '';
        }
        $product = $params['product'];
        if (is_object($product) && method_exists($product, 'offsetGet')) {
            $idProduct = (int)$product['id_product'];
            $idAttr = (int)$product['id_product_attribute'];
        } else {
            $product = (array)$product;
            $idProduct = isset($product['id_product']) ? (int)$product['id_product'] : 0;
            $idAttr = isset($product['id_product_attribute']) ? (int)$product['id_product_attribute'] : 0;
        }
        $line = null;
        foreach ($this->getGiftLines($this->context->cart) as $l) {
            if ($l['id_product'] === $idProduct && $l['id_product_attribute'] === $idAttr) {
                $line = $l;
            }
        }
        if (!$line) {
            return '';
        }
        $this->context->smarty->assign(array(
            'josra_badge' => $line['label'],
            'josra_badge_bg' => Configuration::get('JOSRA_GIFT_BADGE_BG_COLOR'),
            'josra_badge_fg' => Configuration::get('JOSRA_GIFT_BADGE_TEXT_COLOR'),
        ));
        return $this->display(__FILE__, 'views/templates/hook/badge.tpl');
    }

    // =========================================================================
    // HOOKS: PEDIDOS
    // =========================================================================

    public function hookActionValidateOrder($params)
    {
        if (empty($params['order']) || empty($params['cart'])) {
            return;
        }
        $this->getManager()->onOrderValidated($params['order'], $params['cart']);
        if (Validate::isLoadedObject($params['order']) && JosraGiftChoice::ensureSchema()) {
            $this->getChoice()->onOrderValidated($params['order'], $params['cart']);
        }
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        if (empty($params['id_order']) || empty($params['newOrderStatus'])) {
            return;
        }
        $this->getManager()->onOrderStatusChanged((int)$params['id_order'], (int)$params['newOrderStatus']->id);
    }

    // =========================================================================
    // HOOKS: BACK-OFFICE PEDIDOS
    // =========================================================================

    /** Ficha del pedido 1.7.7, 8 y 9 */
    public function hookDisplayAdminOrderSide($params)
    {
        return $this->renderOrderPanel(isset($params['id_order']) ? (int)$params['id_order'] : 0, true);
    }

    /** Ficha del pedido 1.6 y 1.7.0 - 1.7.6 */
    public function hookDisplayAdminOrder($params)
    {
        return $this->renderOrderPanel(isset($params['id_order']) ? (int)$params['id_order'] : (int)Tools::getValue('id_order'), false);
    }

    protected function renderOrderPanel($idOrder, $card)
    {
        $logs = $idOrder ? JosraGiftOrderLog::getAllByOrderId($idOrder) : array();
        if (!$logs) {
            return '';
        }
        $order = new Order($idOrder);
        $currency = Validate::isLoadedObject($order) ? (int)$order->id_currency : null;
        foreach ($logs as &$log) {
            $log['unit_price'] = $this->formatPrice((float)$log['original_price'], $currency);
            $log['total_price'] = $this->formatPrice((float)$log['original_price'] * (int)$log['gift_qty'], $currency);
        }
        unset($log);
        $this->context->smarty->assign(array(
            'josra_logs' => $logs,
            'josra_card' => $card,
            'josra_badge' => $this->getBadgeText(),
            'josra_badge_bg' => Configuration::get('JOSRA_GIFT_BADGE_BG_COLOR'),
            'josra_badge_fg' => Configuration::get('JOSRA_GIFT_BADGE_TEXT_COLOR'),
        ));
        return $this->display(__FILE__, 'views/templates/admin/order_panel.tpl');
    }

    /** Columna "Regalo" en el listado de pedidos 1.6 y 1.7.0 - 1.7.6 */
    public function hookActionAdminOrdersListingFieldsModifier($params)
    {
        if (isset($params['select']) && strpos($params['select'], 'josra_gift') === false) {
            $params['select'] .= ($params['select'] ? ', ' : '') . 'jgl.`product_name` AS josra_gift';
        }
        if (isset($params['join']) && strpos($params['join'], 'josra_gift_order_log') === false) {
            $params['join'] .= ' LEFT JOIN `' . _DB_PREFIX_ . 'josra_gift_order_log` jgl
                ON (jgl.`id_order` = a.`id_order` AND jgl.`status` = \'valid\')';
        }
        if (isset($params['fields'])) {
            $params['fields']['josra_gift'] = array(
                'title' => $this->l('Regalo'),
                'search' => false,
                'orderby' => false,
                'havingFilter' => false,
            );
        }
    }

    /** Columna "Regalo" en el listado de pedidos 1.7.7, 8 y 9 */
    public function hookActionOrderGridDefinitionModifier($params)
    {
        if (empty($params['definition'])) {
            return;
        }
        $columns = $params['definition']->getColumns();
        $class = 'PrestaShop\PrestaShop\Core\Grid\Column\Type\DataColumn';
        if (!class_exists($class)) {
            return;
        }
        $column = new $class('josra_gift');
        $column->setName($this->l('Regalo'));
        $column->setOptions(array('field' => 'josra_gift'));
        try {
            $columns->addAfter('total_paid_tax_incl', $column);
        } catch (Exception $e) {
            try {
                $columns->addBefore('actions', $column);
            } catch (Exception $e2) {
                $columns->add($column);
            }
        }
    }

    public function hookActionOrderGridQueryBuilderModifier($params)
    {
        if (empty($params['search_query_builder'])) {
            return;
        }
        $qb = $params['search_query_builder'];
        $qb->addSelect('jgl.`product_name` AS josra_gift');
        $qb->leftJoin(
            'o',
            '`' . _DB_PREFIX_ . 'josra_gift_order_log`',
            'jgl',
            'jgl.`id_order` = o.`id_order` AND jgl.`status` = \'valid\''
        );
    }
}
