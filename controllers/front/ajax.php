<?php
/**
 * Controlador Ajax frontend
 * Compatible PHP 5.6+
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/JosraGiftManager.php';
require_once dirname(__FILE__) . '/../../classes/JosraGiftRule.php';

class Josra_GiftProductAjaxModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
    }

    public function postProcess()
    {
        $action = Tools::getValue('action');
        header('Content-Type: application/json; charset=utf-8');

        switch ($action) {
            case 'restore_gift':
                $this->actionRestoreGift();
                break;
            case 'get_cart_status':
                $this->actionGetCartStatus();
                break;
            default:
                $this->jsonResponse(array('error' => 'Unknown action'), 400);
        }
    }

    private function actionRestoreGift()
    {
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            $this->jsonResponse(array('error' => 'No cart'), 400);
            return;
        }

        $module = Module::getInstanceByName('josra_giftproduct');
        if (!$module) {
            $this->jsonResponse(array('error' => 'Module not found'), 500);
            return;
        }

        $giftManager = new JosraGiftManager($this->context, $module->psVersionBranch);
        $giftManager->evaluateAndApply($cart);
        $gift = $giftManager->getCurrentGiftInCart($cart);

        $this->jsonResponse(array(
            'success'      => true,
            'gift_added'   => !empty($gift),
            'redirect_url' => $this->context->link->getPageLink('cart'),
        ));
    }

    private function actionGetCartStatus()
    {
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            $this->jsonResponse(array('gift' => null));
            return;
        }

        $module = Module::getInstanceByName('josra_giftproduct');
        if (!$module) {
            $this->jsonResponse(array('gift' => null));
            return;
        }

        $giftManager  = new JosraGiftManager($this->context, $module->psVersionBranch);
        $currentGift  = $giftManager->getCurrentGiftInCart($cart);
        $nextRuleInfo = $giftManager->getNextRuleInfo($cart);

        $deletedFlag  = isset($this->context->cookie->josra_gift_deleted) ? (int)$this->context->cookie->josra_gift_deleted : 0;
        $unlockedFlag = isset($this->context->cookie->josra_gift_unlocked) ? (int)$this->context->cookie->josra_gift_unlocked : 0;

        $response = array(
            'gift' => $currentGift ? array(
                'product_id' => (int)$currentGift['product_id'],
                'attr_id'    => (int)$currentGift['attr_id'],
            ) : null,
            'next_threshold' => $nextRuleInfo ? array(
                'amount_missing' => $nextRuleInfo['amount_missing'],
                'trigger_type'   => $nextRuleInfo['trigger_type'],
            ) : null,
            'unlocked' => $unlockedFlag,
            'deleted'  => $deletedFlag,
        );

        $this->context->cookie->josra_gift_unlocked = 0;
        $this->context->cookie->josra_gift_deleted  = 0;
        $this->context->cookie->write();

        $this->jsonResponse($response);
    }

    private function jsonResponse($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
