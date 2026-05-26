<?php
/**
 * josra_giftproduct - Módulo de producto regalo automático para PrestaShop
 * Compatible con PrestaShop 1.6, 1.7, 8.x y 9.x
 *
 * @author    josra
 * @copyright 2024 josra
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/JosraGiftManager.php';
require_once dirname(__FILE__) . '/classes/JosraGiftRule.php';
require_once dirname(__FILE__) . '/classes/JosraGiftOrderLog.php';

class Josra_giftproduct extends Module
{
    /** @var string versión detectada: '16', '17', '8x', '9x' */
    public $psVersionBranch;

    public function __construct()
    {
        $this->name            = 'josra_giftproduct';
        $this->tab             = 'pricing_promotion';
        $this->version         = '1.1.0';
        $this->author          = 'josra';
        $this->need_instance   = 1;
        $this->bootstrap       = true;
        $this->ps_versions_compliancy = array('min' => '1.6.0.0', 'max' => '9.99.99');

        parent::__construct();

        $this->displayName = $this->l('Gift Product - josra');
        $this->description = $this->l('Añade automáticamente un producto de regalo según reglas de importe o cantidad.');

        $this->psVersionBranch = $this->detectPsVersion();
    }

    public function detectPsVersion()
    {
        $v = _PS_VERSION_;
        if (version_compare($v, '9.0.0', '>=')) {
            return '9x';
        }
        if (version_compare($v, '8.0.0', '>=')) {
            return '8x';
        }
        if (version_compare($v, '1.7.0.0', '>=')) {
            return '17';
        }
        return '16';
    }

    // =========================================================================
    // INSTALL / UNINSTALL
    // =========================================================================

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        if (!$this->installDb()) {
            return false;
        }
        $hooks = $this->getHooksByVersion();
        foreach ($hooks as $hook) {
            $this->registerHook($hook);
        }
        $this->setDefaultConfig();
        return true;
    }

    public function uninstall()
    {
        $this->uninstallDb();
        $this->deleteConfig();
        return parent::uninstall();
    }

    public function upgrade($currentVersion, $newVersion)
    {
        $sqlFile = dirname(__FILE__) . '/sql/upgrade.sql';
        if (!file_exists($sqlFile)) {
            return true;
        }
        $sql = Tools::file_get_contents($sqlFile);
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        $queries = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($queries as $query) {
            if (!empty($query)) {
                Db::getInstance()->execute($query);
            }
        }
        return true;
    }

    private function installDb()
    {
        $sqlFile = dirname(__FILE__) . '/sql/install.sql';
        $sql = Tools::file_get_contents($sqlFile);
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        $queries = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($queries as $query) {
            if (!empty($query) && !Db::getInstance()->execute($query)) {
                return false;
            }
        }
        // Ensure columns added in later versions exist on pre-existing tables
        $this->upgrade('1.0.0', $this->version);
        return true;
    }

    private function uninstallDb()
    {
        $tables = array(
            'josra_gift_rule',
            'josra_gift_rule_level',
            'josra_gift_rule_product',
            'josra_gift_rule_restriction',
            'josra_gift_order_log',
        );
        foreach ($tables as $table) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
        }
    }

    private function deleteConfig()
    {
        $keys = array(
            'JOSRA_GIFT_BADGE_TEXT',
            'JOSRA_GIFT_BADGE_BG_COLOR',
            'JOSRA_GIFT_BADGE_TEXT_COLOR',
            'JOSRA_GIFT_MOTIVATIONAL_TEXT',
            'JOSRA_GIFT_CALC_MODE',
            'JOSRA_GIFT_INCLUDE_SHIPPING',
        );
        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }
    }

    private function setDefaultConfig()
    {
        $langs = Language::getLanguages(false);
        foreach ($langs as $lang) {
            $id = (int)$lang['id_lang'];
            Configuration::updateValue('JOSRA_GIFT_BADGE_TEXT', 'REGALO', false, null, $id);
            Configuration::updateValue('JOSRA_GIFT_MOTIVATIONAL_TEXT', 'Te faltan {amount} para conseguir tu regalo', false, null, $id);
        }
        Configuration::updateValue('JOSRA_GIFT_BADGE_BG_COLOR', '#e74c3c');
        Configuration::updateValue('JOSRA_GIFT_BADGE_TEXT_COLOR', '#ffffff');
        Configuration::updateValue('JOSRA_GIFT_CALC_MODE', 'without_tax');
        Configuration::updateValue('JOSRA_GIFT_INCLUDE_SHIPPING', '0');
    }

    // =========================================================================
    // HOOKS POR VERSIÓN
    // =========================================================================

    private function getHooksByVersion()
    {
        $common = array(
            'displayHeader',
            'actionValidateOrder',
            'actionOrderStatusUpdate',
            'displayOrderDetail',
            'displayOrderConfirmation',
            'displayPDFInvoice',
            'displayAdminOrdersExtra',
            'actionOrderReturn',
        );

        $version16 = array(
            'actionCartSave',
            'displayShoppingCartFooter',
        );

        $version17plus = array(
            'actionCartUpdateQuantity',
            'actionAfterDeleteProductInCart',
            'displayCartExtraProductActions',
        );

        $version9 = array(
            'actionAfterDeleteProductInCart',
            'displayCartExtraProductActions',
            'displayAdminOrdersView',
        );

        switch ($this->psVersionBranch) {
            case '16':
                return array_merge($common, $version16);
            case '9x':
                return array_merge($common, $version17plus, $version9);
            default:
                return array_merge($common, $version17plus);
        }
    }

    // =========================================================================
    // CONFIGURACIÓN
    // =========================================================================

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitJosraGiftConfig')) {
            $output .= $this->saveGlobalConfig();
        }
        return $output . $this->renderConfigPage();
    }

    private function saveGlobalConfig()
    {
        $langs = Language::getLanguages(false);
        foreach ($langs as $lang) {
            $id = (int)$lang['id_lang'];
            $badgeVal = Tools::getValue('JOSRA_GIFT_BADGE_TEXT_' . $id, 'REGALO');
            $motivVal = Tools::getValue('JOSRA_GIFT_MOTIVATIONAL_TEXT_' . $id, '');
            Configuration::updateValue('JOSRA_GIFT_BADGE_TEXT', $badgeVal, false, null, $id);
            Configuration::updateValue('JOSRA_GIFT_MOTIVATIONAL_TEXT', $motivVal, false, null, $id);
        }
        Configuration::updateValue('JOSRA_GIFT_BADGE_BG_COLOR', Tools::getValue('JOSRA_GIFT_BADGE_BG_COLOR', '#e74c3c'));
        Configuration::updateValue('JOSRA_GIFT_BADGE_TEXT_COLOR', Tools::getValue('JOSRA_GIFT_BADGE_TEXT_COLOR', '#ffffff'));
        Configuration::updateValue('JOSRA_GIFT_CALC_MODE', Tools::getValue('JOSRA_GIFT_CALC_MODE', 'without_tax'));
        Configuration::updateValue('JOSRA_GIFT_INCLUDE_SHIPPING', (int)Tools::getValue('JOSRA_GIFT_INCLUDE_SHIPPING', 0));
        return $this->displayConfirmation($this->l('Configuración guardada correctamente.'));
    }

    private function renderConfigPage()
    {
        $langs     = Language::getLanguages(false);
        $badgeText = array();
        $motivText = array();
        foreach ($langs as $lang) {
            $id = (int)$lang['id_lang'];
            $badgeText[$id] = Configuration::get('JOSRA_GIFT_BADGE_TEXT', $id);
            $motivText[$id] = Configuration::get('JOSRA_GIFT_MOTIVATIONAL_TEXT', $id);
        }

        $this->context->smarty->assign(array(
            'module_dir'        => $this->_path,
            'ps_version_branch' => $this->psVersionBranch,
            'ps_version'        => _PS_VERSION_,
            'languages'         => $langs,
            'id_lang_default'   => (int)Configuration::get('PS_LANG_DEFAULT'),
            'badge_text'        => $badgeText,
            'badge_bg_color'    => Configuration::get('JOSRA_GIFT_BADGE_BG_COLOR'),
            'badge_text_color'  => Configuration::get('JOSRA_GIFT_BADGE_TEXT_COLOR'),
            'motivational_text' => $motivText,
            'calc_mode'         => Configuration::get('JOSRA_GIFT_CALC_MODE'),
            'include_shipping'  => (int)Configuration::get('JOSRA_GIFT_INCLUDE_SHIPPING'),
            'rules'             => JosraGiftRule::getAllRulesWithStats(),
            'stats'             => JosraGiftOrderLog::getGlobalStats(),
            'admin_ajax_url'    => $this->context->link->getAdminLink('AdminJosraGiftAjax'),
            'form_action'       => $this->context->link->getAdminLink('AdminModules') . '&configure=' . $this->name,
        ));
        return $this->display(__FILE__, 'views/templates/admin/config.tpl');
    }

    // =========================================================================
    // HOOKS FRONTEND — CARRITO
    // =========================================================================

    public function hookActionCartSave($params)
    {
        $cart = isset($params['cart']) ? $params['cart'] : $this->context->cart;
        $this->processCart($cart);
    }

    public function hookActionCartUpdateQuantity($params)
    {
        $cart = isset($params['cart']) ? $params['cart'] : $this->context->cart;
        if ($cart) {
            $this->processCart($cart);
        }
    }

    public function hookActionAfterDeleteProductInCart($params)
    {
        $cart = isset($params['cart']) ? $params['cart'] : $this->context->cart;
        if (!$cart) {
            return;
        }
        $deletedProductId = (int)(isset($params['id_product']) ? $params['id_product'] : 0);
        $deletedAttrId    = (int)(isset($params['id_product_attribute']) ? $params['id_product_attribute'] : 0);

        $giftManager = new JosraGiftManager($this->context, $this->psVersionBranch);
        if ($giftManager->isGiftProduct($cart, $deletedProductId, $deletedAttrId)) {
            $this->context->cookie->josra_gift_deleted = 1;
            $this->context->cookie->josra_gift_deleted_time = time();
            $this->context->cookie->write();
            return;
        }
        $this->processCart($cart);
    }

    private function processCart($cart)
    {
        if (!Validate::isLoadedObject($cart)) {
            return;
        }
        $giftManager = new JosraGiftManager($this->context, $this->psVersionBranch);
        $giftManager->evaluateAndApply($cart);
    }

    // =========================================================================
    // HOOKS FRONTEND — VISUALIZACIÓN
    // =========================================================================

    public function hookDisplayHeader()
    {
        $badgeBg   = Configuration::get('JOSRA_GIFT_BADGE_BG_COLOR');
        $badgeFg   = Configuration::get('JOSRA_GIFT_BADGE_TEXT_COLOR');

        if ($this->psVersionBranch === '16') {
            $this->context->controller->addCSS($this->_path . 'views/css/josra_gift_front.css');
            $this->context->controller->addJS($this->_path . 'views/js/josra_gift_cart.js');
        } else {
            $this->context->controller->registerStylesheet(
                'josra-gift-front',
                $this->_path . 'views/css/josra_gift_front.css',
                array('media' => 'all', 'priority' => 150)
            );
            $this->context->controller->registerJavascript(
                'josra-gift-cart',
                $this->_path . 'views/js/josra_gift_cart.js',
                array('position' => 'bottom', 'priority' => 150)
            );
        }

        $deletedFlag = $this->context->cookie->josra_gift_deleted ? (int)$this->context->cookie->josra_gift_deleted : 0;

        $this->context->smarty->assign(array(
            'josra_ajax_url'        => $this->context->link->getModuleLink($this->name, 'ajax', array(), true),
            'josra_badge_text'      => Configuration::get('JOSRA_GIFT_BADGE_TEXT', $this->context->language->id),
            'josra_restore_label'   => $this->l('Restaurar mi regalo'),
            'josra_deleted_warning' => $this->l('Has eliminado tu producto de regalo.'),
            'josra_unlocked_msg'    => $this->l('¡Has desbloqueado un regalo!'),
            'josra_upgraded_msg'    => $this->l('¡Has conseguido un regalo mejor!'),
            'josra_gift_deleted'    => $deletedFlag,
        ));

        if ($this->context->cookie->josra_gift_deleted) {
            $this->context->cookie->josra_gift_deleted = 0;
            $this->context->cookie->write();
        }

        return $this->display(__FILE__, 'views/templates/hook/header_js.tpl');
    }

    public function hookDisplayShoppingCartFooter($params)
    {
        $cart = isset($params['cart']) ? $params['cart'] : $this->context->cart;
        return $this->renderCartHook($cart);
    }

    public function hookDisplayCartExtraProductActions($params)
    {
        $cart      = $this->context->cart;
        $productId = (int)(isset($params['product']['id_product']) ? $params['product']['id_product'] : 0);
        $attrId    = (int)(isset($params['product']['id_product_attribute']) ? $params['product']['id_product_attribute'] : 0);

        $giftManager = new JosraGiftManager($this->context, $this->psVersionBranch);
        if (!$giftManager->isGiftProduct($cart, $productId, $attrId)) {
            return '';
        }

        $this->context->smarty->assign(array(
            'josra_badge_text' => Configuration::get('JOSRA_GIFT_BADGE_TEXT', $this->context->language->id),
        ));
        return $this->display(__FILE__, 'views/templates/hook/badge.tpl');
    }

    private function renderCartHook($cart)
    {
        if (!Validate::isLoadedObject($cart)) {
            return '';
        }
        $giftManager  = new JosraGiftManager($this->context, $this->psVersionBranch);
        $nextRuleInfo = $giftManager->getNextRuleInfo($cart);
        if (!$nextRuleInfo) {
            return '';
        }
        $motivText = Configuration::get('JOSRA_GIFT_MOTIVATIONAL_TEXT', $this->context->language->id);
        $motivText = str_replace(
            '{amount}',
            Tools::displayPrice($nextRuleInfo['amount_missing'], $this->context->currency),
            $motivText
        );
        $this->context->smarty->assign(array(
            'josra_motiv_text' => $motivText,
            'josra_next_rule'  => $nextRuleInfo,
        ));
        return $this->display(__FILE__, 'views/templates/hook/motivational.tpl');
    }

    // =========================================================================
    // HOOKS PEDIDO
    // =========================================================================

    public function hookActionValidateOrder($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        $cart  = isset($params['cart'])  ? $params['cart']  : null;
        if (!$order || !$cart) {
            return;
        }
        $giftManager = new JosraGiftManager($this->context, $this->psVersionBranch);
        $giftManager->logOrderGift($order, $cart);
    }

    public function hookActionOrderReturn($params)
    {
        $orderReturn = isset($params['orderReturn']) ? $params['orderReturn'] : null;
        if (!$orderReturn) {
            return;
        }
        $giftManager = new JosraGiftManager($this->context, $this->psVersionBranch);
        $giftManager->handleReturn($orderReturn);
    }

    public function hookDisplayOrderDetail($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order) {
            return '';
        }
        $log = JosraGiftOrderLog::getByOrderId((int)$order->id);
        if (!$log) {
            return '';
        }
        $this->context->smarty->assign(array(
            'josra_gift_log'   => $log,
            'josra_badge_text' => Configuration::get('JOSRA_GIFT_BADGE_TEXT', $this->context->language->id),
        ));
        return $this->display(__FILE__, 'views/templates/hook/order_detail.tpl');
    }

    public function hookDisplayOrderConfirmation($params)
    {
        return $this->hookDisplayOrderDetail($params);
    }

    public function hookDisplayPDFInvoice($params)
    {
        $order = isset($params['object']) ? $params['object'] : null;
        if (!$order) {
            return '';
        }
        $log = JosraGiftOrderLog::getByOrderId((int)$order->id);
        if (!$log) {
            return '';
        }
        $this->context->smarty->assign(array(
            'josra_gift_log'   => $log,
            'josra_badge_text' => Configuration::get('JOSRA_GIFT_BADGE_TEXT', $this->context->language->id),
        ));
        return $this->display(__FILE__, 'views/templates/hook/pdf_invoice.tpl');
    }

    public function hookDisplayAdminOrdersExtra($params)
    {
        $orderId = (int)(isset($params['id_order']) ? $params['id_order'] : Tools::getValue('id_order'));
        if (!$orderId) {
            return '';
        }
        $log = JosraGiftOrderLog::getByOrderId($orderId);
        if (!$log) {
            return '';
        }
        $this->context->smarty->assign(array(
            'josra_gift_log'   => $log,
            'josra_badge_text' => Configuration::get('JOSRA_GIFT_BADGE_TEXT', $this->context->language->id),
        ));
        return $this->display(__FILE__, 'views/templates/admin/order_gift_info.tpl');
    }

    public function hookDisplayAdminOrdersView($params)
    {
        return $this->hookDisplayAdminOrdersExtra($params);
    }

    public function hookActionOrderStatusUpdate($params)
    {
        // Reservado para futuras notificaciones
    }
}
