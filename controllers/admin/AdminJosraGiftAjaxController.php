<?php
/**
 * AdminJosraGiftAjax - Controlador admin Ajax
 * Compatible PHP 5.6+
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/JosraGiftRule.php';
require_once dirname(__FILE__) . '/../../classes/JosraGiftOrderLog.php';

class AdminJosraGiftAjaxController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        if (!$this->module) {
            $this->module = Module::getInstanceByName('josra_giftproduct');
        }
    }

    public function postProcess()
    {
        if (!$this->context->employee || !$this->context->employee->isLoggedBack()) {
            $this->jsonResponse(array('error' => 'Unauthorized'), 403);
        }

        $action = Tools::getValue('action');
        switch ($action) {
            case 'search_product':    $this->actionSearchProduct();   break;
            case 'get_combinations':  $this->actionGetCombinations(); break;
            case 'save_rule':         $this->actionSaveRule();        break;
            case 'delete_rule':       $this->actionDeleteRule();      break;
            case 'toggle_rule':       $this->actionToggleRule();      break;
            case 'get_rule':          $this->actionGetRule();         break;
            case 'get_stats':         $this->actionGetStats();        break;
            default: $this->jsonResponse(array('error' => 'Unknown action'), 400);
        }
    }

    private function actionSearchProduct()
    {
        $query  = pSQL(Tools::getValue('q', ''));
        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;

        if (strlen($query) < 2) {
            $this->jsonResponse(array());
            return;
        }

        $results = Db::getInstance()->executeS(
            'SELECT p.`id_product`, pl.`name`, p.`reference`,
                    i.`id_image`, sa.`quantity` AS stock_qty
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                     ON pl.`id_product` = p.`id_product`
                    AND pl.`id_lang` = ' . $idLang . '
             LEFT JOIN `' . _DB_PREFIX_ . 'image` i
                    ON i.`id_product` = p.`id_product` AND i.`cover` = 1
             LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                    ON sa.`id_product` = p.`id_product`
                   AND sa.`id_product_attribute` = 0
             WHERE p.`active` = 1
               AND (pl.`name` LIKE \'%' . $query . '%\' OR p.`reference` LIKE \'%' . $query . '%\')
             GROUP BY p.`id_product`
             ORDER BY pl.`name` ASC
             LIMIT 10'
        );

        $products = array();
        foreach ((array)$results as $row) {
            $imageUrl = '';
            if ($row['id_image']) {
                $imageUrl = $this->context->link->getImageLink('product', $row['id_product'] . '-' . $row['id_image'], 'home_default');
            }
            $hasCombinations = (int)Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product` = ' . (int)$row['id_product']
            ) > 0;

            $products[] = array(
                'id'               => (int)$row['id_product'],
                'name'             => $row['name'],
                'reference'        => $row['reference'],
                'image_url'        => $imageUrl,
                'stock'            => (int)$row['stock_qty'],
                'has_combinations' => $hasCombinations,
            );
        }
        $this->jsonResponse($products);
    }

    private function actionGetCombinations()
    {
        $productId = (int)Tools::getValue('id_product');
        $idLang    = (int)$this->context->language->id;

        if (!$productId) {
            $this->jsonResponse(array());
            return;
        }

        $combinations = Db::getInstance()->executeS(
            'SELECT pa.`id_product_attribute`,
                    GROUP_CONCAT(al.`name` ORDER BY a.`position` SEPARATOR \' / \') AS combination_name,
                    pa.`reference`, pa.`price` AS price_impact,
                    sa.`quantity` AS stock_qty
             FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pac.`id_product_attribute` = pa.`id_product_attribute`
             LEFT JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
             LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $idLang . '
             LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa ON sa.`id_product` = pa.`id_product` AND sa.`id_product_attribute` = pa.`id_product_attribute`
             WHERE pa.`id_product` = ' . $productId . '
             GROUP BY pa.`id_product_attribute`
             ORDER BY pa.`id_product_attribute` ASC'
        );

        $basePrice = Product::getPriceStatic($productId, false);
        $result    = array();
        foreach ((array)$combinations as $c) {
            $result[] = array(
                'id'        => (int)$c['id_product_attribute'],
                'name'      => $c['combination_name'] ? $c['combination_name'] : 'Sin nombre',
                'reference' => $c['reference'],
                'price'     => Tools::displayPrice($basePrice + (float)$c['price_impact']),
                'stock'     => (int)$c['stock_qty'],
            );
        }
        $this->jsonResponse($result);
    }

    private function actionSaveRule()
    {
        $ruleId = (int)Tools::getValue('id_josra_gift_rule', 0);
        $isNew  = ($ruleId === 0);
        $rule   = $isNew ? new JosraGiftRule() : new JosraGiftRule($ruleId);

        $triggerTypeVal = Tools::getValue('trigger_type');
        $calcModeVal    = Tools::getValue('calc_mode');
        $rule->name             = pSQL(Tools::getValue('name', ''));
        $rule->active           = (int)Tools::getValue('active', 1);
        $rule->trigger_type     = in_array($triggerTypeVal, array('amount', 'quantity')) ? $triggerTypeVal : 'amount';
        $rule->calc_mode        = in_array($calcModeVal, array('without_tax', 'with_tax')) ? $calcModeVal : 'without_tax';
        $rule->include_shipping = (int)Tools::getValue('include_shipping', 0);
        $rule->priority         = (int)Tools::getValue('priority', 0);

        $dateStart = Tools::getValue('date_start', '');
        $dateEnd   = Tools::getValue('date_end', '');
        $maxUses   = Tools::getValue('max_uses', '');

        $rule->date_start = !empty($dateStart) ? pSQL($dateStart) : null;
        $rule->date_end   = !empty($dateEnd)   ? pSQL($dateEnd)   : null;
        $rule->max_uses   = !empty($maxUses)   ? (int)$maxUses    : null;

        if (empty($rule->name)) {
            $this->jsonResponse(array('error' => 'El nombre es obligatorio'), 422);
            return;
        }

        if ($isNew) {
            $rule->uses_count = 0;
            $rule->date_add   = date('Y-m-d H:i:s');
        }
        $rule->date_upd = date('Y-m-d H:i:s');

        $saved = $isNew ? $rule->add() : $rule->update();
        if (!$saved) {
            $this->jsonResponse(array('error' => 'Error al guardar la regla'), 500);
            return;
        }
        if ($isNew) {
            $ruleId = (int)$rule->id;
        }

        $levelsJson = Tools::getValue('levels', '[]');
        $levels     = json_decode($levelsJson, true);
        if (is_array($levels) && !empty($levels)) {
            JosraGiftRule::saveLevels($ruleId, $levels);
        }

        $restrictionsJson = Tools::getValue('restrictions', '[]');
        $restrictions     = json_decode($restrictionsJson, true);
        JosraGiftRule::saveRestrictions($ruleId, is_array($restrictions) ? $restrictions : array());

        $this->jsonResponse(array(
            'success' => true,
            'id'      => $ruleId,
            'message' => $isNew ? 'Regla creada correctamente' : 'Regla actualizada correctamente',
        ));
    }

    private function actionDeleteRule()
    {
        $ruleId = (int)Tools::getValue('id_josra_gift_rule');
        if (!$ruleId) {
            $this->jsonResponse(array('error' => 'ID inválido'), 400);
            return;
        }
        $rule = new JosraGiftRule($ruleId);
        if (!Validate::isLoadedObject($rule)) {
            $this->jsonResponse(array('error' => 'Regla no encontrada'), 404);
            return;
        }
        JosraGiftRule::saveLevels($ruleId, array());
        JosraGiftRule::saveRestrictions($ruleId, array());
        $rule->delete();
        $this->jsonResponse(array('success' => true, 'message' => 'Regla eliminada'));
    }

    private function actionToggleRule()
    {
        $ruleId = (int)Tools::getValue('id_josra_gift_rule');
        $rule   = new JosraGiftRule($ruleId);
        if (!Validate::isLoadedObject($rule)) {
            $this->jsonResponse(array('error' => 'Regla no encontrada'), 404);
            return;
        }
        $rule->active   = $rule->active ? 0 : 1;
        $rule->date_upd = date('Y-m-d H:i:s');
        $rule->update();
        $this->jsonResponse(array('success' => true, 'active' => (int)$rule->active));
    }

    private function actionGetRule()
    {
        $ruleId = (int)Tools::getValue('id_josra_gift_rule');
        $rule   = new JosraGiftRule($ruleId);
        if (!Validate::isLoadedObject($rule)) {
            $this->jsonResponse(array('error' => 'Regla no encontrada'), 404);
            return;
        }
        $this->jsonResponse(array(
            'rule'         => (array)$rule,
            'levels'       => JosraGiftRule::getLevelsByRuleId($ruleId),
            'restrictions' => JosraGiftRule::getRestrictionsByRuleId($ruleId),
        ));
    }

    private function actionGetStats()
    {
        $dateFrom = Tools::getValue('date_from', '');
        $dateTo   = Tools::getValue('date_to', '');
        $limit    = (int)Tools::getValue('limit', 10);
        $stats    = JosraGiftOrderLog::getGlobalStats(!empty($dateFrom) ? $dateFrom : null, !empty($dateTo) ? $dateTo : null);
        $recent   = JosraGiftOrderLog::getRecentGifts($limit, !empty($dateFrom) ? $dateFrom : null, !empty($dateTo) ? $dateTo : null);
        $this->jsonResponse(array('stats' => $stats, 'recent' => $recent));
    }

    private function jsonResponse($data, $statusCode = 200)
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
