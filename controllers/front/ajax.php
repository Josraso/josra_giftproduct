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

        $action = (string)Tools::getValue('josra_action');
        if ($hasCart && $action === 'restore') {
            $this->module->getManager()->restore($cart);
        }

        // «Elige tu regalo»: elegir, cambiar de combinación o quitar
        $error = '';
        if ($hasCart && ($action === 'choose' || $action === 'unchoose') && JosraGiftChoice::ensureSchema()) {
            $choice = $this->module->getChoice();
            $slot = preg_replace('/[^a-z0-9.]/', '', (string)Tools::getValue('slot'));
            $r = $action === 'choose'
                ? $choice->choose($cart, $slot, (int)Tools::getValue('id_level'), (int)Tools::getValue('id_attr'))
                : $choice->remove($cart, $slot);
            if ($r !== true) {
                $error = (string)$r;
            }
        }

        $notice = $hasCart ? JosraGiftCart::popNotice($cart->id) : null;
        $this->json(array(
            'ok' => $error === '',
            'error' => $error,
            'gift' => $hasCart ? $this->module->getGiftLine($cart) : null,
            'gifts' => $hasCart ? $this->module->getGiftLines($cart) : array(),
            'notice' => $notice ? $this->module->noticeText($notice) : '',
            'block' => $this->module->renderCartBlock($hasCart ? $cart : null),
            'choice' => $this->module->renderChoiceBlock($hasCart ? $cart : null, Tools::getValue('where') === 'checkout' ? 'checkout' : 'cart'),
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
