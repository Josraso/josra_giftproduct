<?php
/**
 * AJAX del carrito: estado del regalo y botón "Recuperar regalo".
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Josra_giftproductAjaxModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;
    public $content_only = true;
    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;
        $hasCart = is_object($cart) && Validate::isLoadedObject($cart);

        if ($hasCart && Tools::getValue('josra_action') === 'restore') {
            $this->module->getManager()->restore($cart);
        }

        $notice = $hasCart ? JosraGiftCart::popNotice($cart->id) : null;
        $this->json(array(
            'gift' => $hasCart ? $this->module->getGiftLine($cart) : null,
            'notice' => $notice ? $this->module->noticeText($notice) : '',
            'block' => $this->module->renderCartBlock($hasCart ? $cart : null),
        ));
    }

    protected function json($data)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        die(json_encode($data));
    }
}
