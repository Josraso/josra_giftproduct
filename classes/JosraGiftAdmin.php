<?php
/**
 * Página de configuración del módulo (Módulos > Configurar).
 * Todo con los helpers nativos: HelperList para las reglas y HelperForm
 * para la regla y los ajustes. Sin pestañas ni entradas de menú.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class JosraGiftAdmin
{
    /** @var Josra_giftproduct */
    protected $module;

    /** @var Context */
    protected $context;

    protected $table = 'josra_gift_rule';
    protected $identifier = 'id_josra_gift_rule';

    public function __construct($module)
    {
        $this->module = $module;
        $this->context = Context::getContext();
    }

    protected function l($string)
    {
        return $this->module->l($string, 'josragiftadmin');
    }

    // =========================================================================
    // URLS (siempre absolutas: en 8/9 la configuración va por ruta Symfony)
    // =========================================================================

    public function baseUrl()
    {
        return Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . basename(_PS_ADMIN_DIR_)
            . '/index.php?controller=AdminModules&configure=' . $this->module->name
            . '&tab_module=' . $this->module->tab . '&module_name=' . $this->module->name;
    }

    public function token()
    {
        return Tools::getAdminTokenLite('AdminModules');
    }

    public function url($extra = '')
    {
        return $this->baseUrl() . '&token=' . $this->token() . $extra;
    }

    protected function redirect($extra = '')
    {
        Tools::redirectAdmin($this->url($extra));
    }

    protected function flash($message = null)
    {
        if ($message !== null) {
            $this->context->cookie->josra_gift_flash = $message;
            $this->context->cookie->write();
            return '';
        }
        $msg = isset($this->context->cookie->josra_gift_flash) ? $this->context->cookie->josra_gift_flash : '';
        if ($msg) {
            $this->context->cookie->josra_gift_flash = '';
            $this->context->cookie->write();
            return $this->module->displayConfirmation($msg);
        }
        return '';
    }

    // =========================================================================
    // ENTRADA
    // =========================================================================

    public function getContent()
    {
        if (Tools::getValue('josra_ajax')) {
            $this->ajax();
        }

        JosraGiftCart::cleanup(30);
        $html = $this->flash();

        if (Tools::isSubmit('submitJosraGiftConfig')) {
            $errors = $this->saveConfig();
            if (!$errors) {
                $this->flash($this->l('Ajustes guardados.'));
                $this->redirect();
            }
            $html .= $this->module->displayError(implode('<br>', $errors));
        }

        if (Tools::isSubmit('submitJosraGiftRule') || Tools::isSubmit('submitJosraGiftRuleStay')) {
            $id = 0;
            $errors = $this->saveRule($id);
            if (!$errors) {
                $this->flash($this->l('Regla guardada.'));
                if (Tools::isSubmit('submitJosraGiftRuleStay')) {
                    $this->redirect('&update' . $this->table . '&' . $this->identifier . '=' . (int)$id);
                }
                $this->redirect();
            }
            return $html . $this->module->displayError(implode('<br>', $errors)) . $this->renderRuleForm(true);
        }

        if (Tools::isSubmit('duplicateJosraGiftRule')) {
            $newId = JosraGiftRule::duplicate((int)Tools::getValue($this->identifier));
            if ($newId) {
                $this->flash($this->l('Regla duplicada. Está desactivada: revísala y actívala.'));
                $this->redirect('&update' . $this->table . '&' . $this->identifier . '=' . (int)$newId);
            }
        }

        if (Tools::getIsset('status' . $this->table)) {
            $rule = new JosraGiftRule((int)Tools::getValue($this->identifier));
            if (Validate::isLoadedObject($rule)) {
                $rule->active = $rule->active ? 0 : 1;
                $rule->update();
                $this->flash($this->l('Estado actualizado.'));
            }
            $this->redirect();
        }

        if (Tools::getIsset('delete' . $this->table)) {
            $rule = new JosraGiftRule((int)Tools::getValue($this->identifier));
            if (Validate::isLoadedObject($rule)) {
                $rule->delete();
                $this->flash($this->l('Regla eliminada.'));
            }
            $this->redirect();
        }

        if (Tools::getIsset('add' . $this->table) || Tools::getIsset('update' . $this->table)) {
            return $html . $this->renderRuleForm(false);
        }

        return $html . $this->renderRuleList() . $this->renderStats() . $this->renderConfigForm();
    }

    // =========================================================================
    // AJAX (buscador de productos y combinaciones del formulario de regla)
    // =========================================================================

    protected function ajax()
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        $idLang = (int)$this->context->language->id;
        $out = array();

        switch (Tools::getValue('josra_action')) {
            case 'search_product':
                $q = trim((string)Tools::getValue('q'));
                if (Tools::strlen($q) >= 2) {
                    $like = pSQL(addcslashes($q, '%_'));
                    $rows = Db::getInstance()->executeS(
                        'SELECT p.`id_product`, pl.`name`, p.`reference`,
                                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute` pa
                                  WHERE pa.`id_product` = p.`id_product`) AS combinations
                         FROM `' . _DB_PREFIX_ . 'product` p
                         INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                                 ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $idLang . '
                                AND pl.`id_shop` = ' . (int)$this->context->shop->id . '
                         WHERE (pl.`name` LIKE \'%' . $like . '%\' OR p.`reference` LIKE \'%' . $like . '%\'
                                OR p.`id_product` = ' . (int)$q . ')
                         ORDER BY p.`active` DESC, pl.`name` ASC
                         LIMIT 15'
                    );
                    foreach ((array)$rows as $r) {
                        $out[] = array(
                            'id' => (int)$r['id_product'],
                            'name' => $r['name'],
                            'reference' => $r['reference'],
                            'combinations' => (int)$r['combinations'] > 0,
                        );
                    }
                }
                break;

            case 'combinations':
                $out = $this->getCombinations((int)Tools::getValue('id_product'), $idLang);
                break;
        }

        die(json_encode($out));
    }

    protected function getCombinations($idProduct, $idLang)
    {
        $out = array();
        if (!$idProduct) {
            return $out;
        }
        $rows = Db::getInstance()->executeS(
            'SELECT pa.`id_product_attribute`, pa.`reference`
             FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             WHERE pa.`id_product` = ' . (int)$idProduct . '
             ORDER BY pa.`id_product_attribute` ASC'
        );
        foreach ((array)$rows as $r) {
            $idAttr = (int)$r['id_product_attribute'];
            $name = JosraGiftRule::getCombinationName($idAttr, $idLang);
            $stock = (int)StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttr);
            $out[] = array(
                'id' => $idAttr,
                'name' => ($name ? $name : '#' . $idAttr) . ($r['reference'] ? ' · ' . $r['reference'] : '')
                    . ' · ' . sprintf($this->l('stock %d'), $stock),
            );
        }
        return $out;
    }

    // =========================================================================
    // LISTA DE REGLAS
    // =========================================================================

    protected function renderRuleList()
    {
        $idLang = (int)$this->context->language->id;
        $rows = JosraGiftRule::getListForAdmin($idLang);
        $currency = $this->defaultCurrency();
        $types = $this->triggerTypes();

        foreach ($rows as &$row) {
            $levels = JosraGiftRule::getLevels((int)$row['id_josra_gift_rule'], $idLang);
            $parts = array();
            foreach ($levels as $lv) {
                $gift = $this->module->getManager()->giftLabel($lv);
                if ($row['trigger_type'] === JosraGiftRule::TRIGGER_PRODUCT) {
                    $parts[] = $gift;
                    break;
                }
                $threshold = $row['trigger_type'] === JosraGiftRule::TRIGGER_QUANTITY
                    ? (int)$lv['trigger_value'] . ' ' . $this->l('uds.')
                    : $this->module->formatPrice((float)$lv['trigger_value'], $currency);
                $parts[] = '≥ ' . $threshold . ' → ' . $gift;
            }
            $type = isset($types[$row['trigger_type']]) ? $types[$row['trigger_type']] : $row['trigger_type'];
            if ($row['trigger_type'] === JosraGiftRule::TRIGGER_PRODUCT && $row['id_trigger_product']) {
                $type .= ': ' . (int)max(1, $row['trigger_min_qty']) . ' x '
                    . JosraGiftRule::getProductName((int)$row['id_trigger_product'], $idLang);
            }
            $row['type_label'] = $type;
            $row['levels_label'] = $parts ? implode(' | ', $parts) : $this->l('Sin tramos');
            $row['dates_label'] = $this->datesLabel($row['date_start'], $row['date_end']);
            $row['uses_label'] = (int)$row['uses_count'] . ' / ' . ((int)$row['max_uses'] ? (int)$row['max_uses'] : '∞');
        }
        unset($row);

        $fields = array(
            'id_josra_gift_rule' => array('title' => $this->l('ID'), 'class' => 'fixed-width-xs', 'align' => 'center'),
            'name' => array('title' => $this->l('Nombre')),
            'type_label' => array('title' => $this->l('Se activa por')),
            'levels_label' => array('title' => $this->l('Tramos y regalos')),
            'dates_label' => array('title' => $this->l('Vigencia')),
            'uses_label' => array('title' => $this->l('Entregados'), 'align' => 'center', 'class' => 'fixed-width-sm'),
            'orders_count' => array('title' => $this->l('Pedidos'), 'align' => 'center', 'class' => 'fixed-width-xs'),
            'priority' => array('title' => $this->l('Prioridad'), 'align' => 'center', 'class' => 'fixed-width-xs'),
            'active' => array('title' => $this->l('Activa'), 'active' => 'status', 'type' => 'bool', 'align' => 'center', 'class' => 'fixed-width-sm'),
        );
        foreach ($fields as &$f) {
            $f['search'] = false;
            $f['orderby'] = false;
        }
        unset($f);

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = false;
        $helper->list_id = $this->table;
        $helper->identifier = $this->identifier;
        $helper->table = $this->table;
        $helper->actions = array('edit', 'delete');
        $helper->show_toolbar = true;
        $helper->toolbar_btn = array(
            'new' => array(
                'href' => $this->url('&add' . $this->table),
                'desc' => $this->l('Nueva regla'),
            ),
        );
        $helper->title = $this->l('Reglas de regalo');
        $helper->module = $this->module;
        $helper->token = $this->token();
        $helper->currentIndex = $this->baseUrl();
        $helper->listTotal = count($rows);

        $html = $helper->generateList($rows, $fields);
        if (!$rows) {
            $html .= '<div class="alert alert-info">'
                . Tools::safeOutput($this->l('Todavía no hay reglas. Pulsa el botón + de la lista para crear la primera.'))
                . '</div>';
        }
        return $html;
    }

    protected function datesLabel($start, $end)
    {
        $start = ($start && $start !== '0000-00-00') ? $this->module->formatDate($start) : '';
        $end = ($end && $end !== '0000-00-00') ? $this->module->formatDate($end) : '';
        if (!$start && !$end) {
            return $this->l('Siempre');
        }
        if ($start && $end) {
            return $start . ' – ' . $end;
        }
        return $start ? sprintf($this->l('Desde %s'), $start) : sprintf($this->l('Hasta %s'), $end);
    }

    protected function triggerTypes()
    {
        return array(
            JosraGiftRule::TRIGGER_AMOUNT => $this->l('Importe del carrito'),
            JosraGiftRule::TRIGGER_QUANTITY => $this->l('Número de productos'),
            JosraGiftRule::TRIGGER_PRODUCT => $this->l('Producto concreto en el carrito'),
        );
    }

    protected function defaultCurrency()
    {
        return Currency::getCurrencyInstance((int)Configuration::get('PS_CURRENCY_DEFAULT'));
    }

    // =========================================================================
    // FORMULARIO DE REGLA
    // =========================================================================

    protected function renderRuleForm($fromPost)
    {
        $idLang = (int)$this->context->language->id;
        $id = (int)Tools::getValue($this->identifier);
        $rule = new JosraGiftRule($id ? $id : null);
        $isNew = !Validate::isLoadedObject($rule);

        // Valores
        if ($fromPost) {
            $values = array(
                $this->identifier => $id,
                'name' => Tools::getValue('name'),
                'active' => (int)Tools::getValue('active'),
                'trigger_type' => Tools::getValue('trigger_type'),
                'calc_mode' => Tools::getValue('calc_mode'),
                'include_shipping' => (int)Tools::getValue('include_shipping'),
                'trigger_min_qty' => Tools::getValue('trigger_min_qty'),
                'max_uses' => Tools::getValue('max_uses'),
                'priority' => Tools::getValue('priority'),
            );
            $dateStart = Tools::getValue('date_start');
            $dateEnd = Tools::getValue('date_end');
            $triggerProduct = (int)Tools::getValue('id_trigger_product');
            $levels = $this->levelsFromPost(false);
            $groups = $this->checkedIds('groups');
            $zones = $this->checkedIds('zones');
        } else {
            $values = array(
                $this->identifier => $isNew ? 0 : (int)$rule->id,
                'name' => $rule->name,
                'active' => $isNew ? 1 : (int)$rule->active,
                'trigger_type' => $rule->trigger_type ? $rule->trigger_type : JosraGiftRule::TRIGGER_AMOUNT,
                'calc_mode' => $rule->calc_mode ? $rule->calc_mode : 'with_tax',
                'include_shipping' => (int)$rule->include_shipping,
                'trigger_min_qty' => max(1, (int)$rule->trigger_min_qty),
                'max_uses' => (int)$rule->max_uses,
                'priority' => (int)$rule->priority,
            );
            $dateStart = $rule->date_start;
            $dateEnd = $rule->date_end;
            $triggerProduct = (int)$rule->id_trigger_product;
            $levels = $isNew ? array() : JosraGiftRule::getLevels((int)$rule->id, $idLang);
            $r = $isNew ? array('group' => array(), 'zone' => array()) : JosraGiftRule::getRestrictions((int)$rule->id);
            $groups = $r['group'];
            $zones = $r['zone'];
        }

        // Combinaciones de cada tramo precargadas (sin esperar al AJAX)
        foreach ($levels as &$lv) {
            $lv['combinations'] = $lv['id_product'] ? $this->getCombinations((int)$lv['id_product'], $idLang) : array();
            if (empty($lv['product_name']) && $lv['id_product']) {
                $lv['product_name'] = JosraGiftRule::getProductName((int)$lv['id_product'], $idLang);
            }
            // 200.000000 -> 200 ; 49.950000 -> 49.95
            $lv['trigger_display'] = (float)$lv['trigger_value'] > 0
                ? rtrim(rtrim(number_format((float)$lv['trigger_value'], 6, '.', ''), '0'), '.')
                : '';
        }
        unset($lv);

        $groupList = Group::getGroups($idLang);
        $zoneList = Zone::getZones(true);
        foreach ($groupList as $g) {
            $values['groups_' . (int)$g['id_group']] = in_array((int)$g['id_group'], $groups);
        }
        foreach ($zoneList as $z) {
            $values['zones_' . (int)$z['id_zone']] = in_array((int)$z['id_zone'], $zones);
        }

        $currency = $this->defaultCurrency();
        $this->context->smarty->assign(array(
            'josra_levels' => $levels,
            'josra_currency_sign' => $currency ? $currency->sign : '',
            'josra_trigger_product_id' => $triggerProduct,
            'josra_trigger_product_name' => $triggerProduct ? JosraGiftRule::getProductName($triggerProduct, $idLang) : '',
            'josra_date_start' => ($dateStart && $dateStart !== '0000-00-00') ? Tools::substr($dateStart, 0, 10) : '',
            'josra_date_end' => ($dateEnd && $dateEnd !== '0000-00-00') ? Tools::substr($dateEnd, 0, 10) : '',
        ));

        $yesNo = array(
            array('id' => 'on', 'value' => 1, 'label' => $this->l('Sí')),
            array('id' => 'off', 'value' => 0, 'label' => $this->l('No')),
        );
        $typeOptions = array();
        foreach ($this->triggerTypes() as $k => $v) {
            $typeOptions[] = array('id' => $k, 'name' => $v);
        }

        $buttons = array(
            array(
                'href' => $this->url(),
                'title' => $this->l('Volver a la lista'),
                'icon' => 'process-icon-back',
            ),
            array(
                'type' => 'submit',
                'name' => 'submitJosraGiftRuleStay',
                'title' => $this->l('Guardar y seguir editando'),
                'icon' => 'process-icon-save',
                'class' => 'pull-right',
            ),
        );
        if (!$isNew) {
            $buttons[] = array(
                'type' => 'submit',
                'name' => 'duplicateJosraGiftRule',
                'title' => $this->l('Duplicar'),
                'icon' => 'process-icon-duplicate',
                'class' => 'pull-right',
            );
        }

        $form = array(
            'form' => array(
                'legend' => array(
                    'title' => $isNew ? $this->l('Nueva regla de regalo') : sprintf($this->l('Editar regla: %s'), $rule->name),
                    'icon' => 'icon-gift',
                ),
                'input' => array(
                    array('type' => 'hidden', 'name' => $this->identifier),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Nombre'),
                        'name' => 'name',
                        'required' => true,
                        'col' => 6,
                        'hint' => $this->l('Solo lo ves tú (lista, pedidos y estadísticas).'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Activa'),
                        'name' => 'active',
                        'is_bool' => true,
                        'values' => $yesNo,
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('El regalo se consigue por'),
                        'name' => 'trigger_type',
                        'id' => 'josra_trigger_type',
                        'options' => array('query' => $typeOptions, 'id' => 'id', 'name' => 'name'),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Importe que cuenta'),
                        'name' => 'calc_mode',
                        'form_group_class' => 'josra-only-amount',
                        'options' => array(
                            'query' => array(
                                array('id' => 'with_tax', 'name' => $this->l('Productos con IVA')),
                                array('id' => 'without_tax', 'name' => $this->l('Productos sin IVA')),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                        'desc' => $this->l('Se usa el precio que paga el cliente (con las rebajas del producto). Los cupones no restan.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Sumar gastos de envío'),
                        'name' => 'include_shipping',
                        'is_bool' => true,
                        'values' => $yesNo,
                        'form_group_class' => 'josra-only-amount',
                    ),
                    array(
                        'type' => 'html',
                        'label' => $this->l('Producto que hay que comprar'),
                        'name' => 'josra_trigger_product',
                        'form_group_class' => 'josra-only-product',
                        'html_content' => $this->module->fetchTemplate('admin/trigger_product.tpl'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Unidades mínimas de ese producto'),
                        'name' => 'trigger_min_qty',
                        'class' => 'fixed-width-sm',
                        'form_group_class' => 'josra-only-product',
                    ),
                    array(
                        'type' => 'html',
                        'label' => $this->l('Tramos y regalos'),
                        'name' => 'josra_levels',
                        'html_content' => $this->module->fetchTemplate('admin/levels.tpl'),
                    ),
                    array(
                        'type' => 'html',
                        'label' => $this->l('Vigencia'),
                        'name' => 'josra_dates',
                        'html_content' => $this->module->fetchTemplate('admin/dates.tpl'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Unidades de regalo disponibles'),
                        'name' => 'max_uses',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Cuando se entreguen todas, la regla deja de aplicarse. 0 = sin límite.')
                            . ($isNew ? '' : ' ' . sprintf($this->l('Entregadas hasta ahora: %d.'), (int)$rule->uses_count)),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Prioridad'),
                        'name' => 'priority',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Si el carrito cumple varias reglas, solo se da el regalo de la de número más alto.'),
                    ),
                    array(
                        'type' => 'checkbox',
                        'label' => $this->l('Solo para estos grupos'),
                        'name' => 'groups',
                        'values' => array('query' => $groupList, 'id' => 'id_group', 'name' => 'name'),
                        'desc' => $this->l('Sin marcar ninguno = todos los clientes.'),
                    ),
                    array(
                        'type' => 'checkbox',
                        'label' => $this->l('Solo para estas zonas'),
                        'name' => 'zones',
                        'values' => array('query' => $zoneList, 'id' => 'id_zone', 'name' => 'name'),
                        'desc' => $this->l('Según la dirección de entrega. Sin marcar ninguna = todas.'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Guardar'),
                    'name' => 'submitJosraGiftRule',
                ),
                'buttons' => $buttons,
            ),
        );

        $helper = $this->formHelper('submitJosraGiftRule');
        $helper->currentIndex = $this->baseUrl() . ($isNew ? '&add' . $this->table : '&update' . $this->table . '&' . $this->identifier . '=' . (int)$rule->id);
        $helper->fields_value = $values;

        return $this->adminAssets() . $helper->generateForm(array($form));
    }

    protected function formHelper($submitAction)
    {
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->module->name;
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->token = $this->token();
        $helper->currentIndex = $this->baseUrl();
        $helper->submit_action = $submitAction;
        $helper->show_toolbar = false;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->languages = $this->getLanguages();
        return $helper;
    }

    protected function getLanguages()
    {
        $languages = Language::getLanguages(false);
        $default = (int)Configuration::get('PS_LANG_DEFAULT');
        foreach ($languages as &$lang) {
            $lang['is_default'] = (int)$lang['id_lang'] === $default ? 1 : 0;
        }
        return $languages;
    }

    protected function adminAssets()
    {
        $this->context->smarty->assign(array(
            'josra_admin_js' => $this->module->getPathUri() . 'views/js/admin.js?v=' . $this->module->version,
            'josra_admin_css' => $this->module->getPathUri() . 'views/css/admin.css?v=' . $this->module->version,
            'josra_ajax_url' => $this->url('&josra_ajax=1'),
            'josra_texts' => array(
                'no_results' => $this->l('Sin resultados'),
                'searching' => $this->l('Buscando...'),
                'choose_combination' => $this->l('Elige combinación'),
                'remove' => $this->l('Quitar tramo'),
                'from_units' => $this->l('Desde (uds.)'),
                'from_amount' => $this->l('Desde'),
            ),
        ));
        return $this->module->fetchTemplate('admin/assets.tpl');
    }

    protected function checkedIds($name)
    {
        $ids = array();
        foreach ($_POST as $key => $val) {
            if (strpos($key, $name . '_') === 0 && $val) {
                $ids[] = (int)Tools::substr($key, Tools::strlen($name) + 1);
            }
        }
        return array_filter($ids);
    }

    /**
     * Tramos enviados en el formulario. Con $clean se descartan las filas
     * vacías y se normalizan valores.
     */
    protected function levelsFromPost($clean)
    {
        $raw = Tools::getValue('josra_levels');
        $out = array();
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $level = array(
                'trigger_value' => (float)str_replace(',', '.', isset($row['trigger_value']) ? $row['trigger_value'] : 0),
                'id_product' => isset($row['id_product']) ? (int)$row['id_product'] : 0,
                'id_product_attribute' => isset($row['id_product_attribute']) ? (int)$row['id_product_attribute'] : 0,
                'gift_qty' => isset($row['gift_qty']) ? max(1, (int)$row['gift_qty']) : 1,
                'product_name' => isset($row['product_name']) ? (string)$row['product_name'] : '',
            );
            if ($clean && !$level['id_product'] && !$level['trigger_value']) {
                continue;
            }
            $out[] = $level;
        }
        return $out;
    }

    protected function saveRule(&$id)
    {
        $errors = array();
        $id = (int)Tools::getValue($this->identifier);
        $rule = new JosraGiftRule($id ? $id : null);
        if ($id && !Validate::isLoadedObject($rule)) {
            return array($this->l('La regla no existe.'));
        }

        $name = trim((string)Tools::getValue('name'));
        $type = Tools::getValue('trigger_type');
        $types = $this->triggerTypes();
        if ($name === '' || !Validate::isGenericName($name)) {
            $errors[] = $this->l('Pon un nombre válido a la regla.');
        }
        if (!isset($types[$type])) {
            $type = JosraGiftRule::TRIGGER_AMOUNT;
        }

        $dateStart = trim((string)Tools::getValue('date_start'));
        $dateEnd = trim((string)Tools::getValue('date_end'));
        if ($dateStart !== '' && !Validate::isDateFormat($dateStart)) {
            $errors[] = $this->l('La fecha de inicio no es válida.');
        }
        if ($dateEnd !== '' && !Validate::isDateFormat($dateEnd)) {
            $errors[] = $this->l('La fecha de fin no es válida.');
        }
        if ($dateStart !== '' && $dateEnd !== '' && $dateStart > $dateEnd) {
            $errors[] = $this->l('La fecha de fin es anterior a la de inicio.');
        }

        $triggerProduct = (int)Tools::getValue('id_trigger_product');
        if ($type === JosraGiftRule::TRIGGER_PRODUCT && !$triggerProduct) {
            $errors[] = $this->l('Elige el producto que hay que comprar para conseguir el regalo.');
        }

        $levels = $this->levelsFromPost(true);
        $seen = array();
        foreach ($levels as $i => &$lv) {
            $n = $i + 1;
            if (!$lv['id_product']) {
                $errors[] = sprintf($this->l('Tramo %d: elige el producto de regalo.'), $n);
                continue;
            }
            $product = new Product((int)$lv['id_product']);
            if (!Validate::isLoadedObject($product)) {
                $errors[] = sprintf($this->l('Tramo %d: el producto no existe.'), $n);
                continue;
            }
            $hasCombinations = (bool)Db::getInstance()->getValue(
                'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product` = ' . (int)$lv['id_product']
            );
            if ($hasCombinations) {
                $belongs = $lv['id_product_attribute'] && Db::getInstance()->getValue(
                    'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute`
                     WHERE `id_product` = ' . (int)$lv['id_product'] . '
                       AND `id_product_attribute` = ' . (int)$lv['id_product_attribute']
                );
                if (!$belongs) {
                    $errors[] = sprintf($this->l('Tramo %d: elige qué combinación se regala.'), $n);
                }
            } else {
                $lv['id_product_attribute'] = 0;
            }
            if ($type === JosraGiftRule::TRIGGER_PRODUCT) {
                $lv['trigger_value'] = 0;
            } else {
                if ($type === JosraGiftRule::TRIGGER_QUANTITY) {
                    $lv['trigger_value'] = (int)round($lv['trigger_value']);
                }
                if ($lv['trigger_value'] <= 0) {
                    $errors[] = sprintf($this->l('Tramo %d: indica a partir de qué importe o cantidad se regala.'), $n);
                }
                $key = (string)$lv['trigger_value'];
                if (isset($seen[$key])) {
                    $errors[] = sprintf($this->l('Tramo %d: ya hay otro tramo con el mismo mínimo.'), $n);
                }
                $seen[$key] = true;
            }
        }
        unset($lv);

        if (!$levels) {
            $errors[] = $this->l('Añade al menos un tramo con su producto de regalo.');
        }
        if ($type === JosraGiftRule::TRIGGER_PRODUCT) {
            $levels = array_slice($levels, 0, 1);
        }

        if ($errors) {
            return array_unique($errors);
        }

        usort($levels, array('JosraGiftAdmin', 'sortLevels'));

        $rule->name = $name;
        $rule->active = (int)Tools::getValue('active') ? 1 : 0;
        $rule->trigger_type = $type;
        $rule->calc_mode = Tools::getValue('calc_mode') === 'without_tax' ? 'without_tax' : 'with_tax';
        $rule->include_shipping = (int)Tools::getValue('include_shipping') ? 1 : 0;
        $rule->id_trigger_product = $type === JosraGiftRule::TRIGGER_PRODUCT ? $triggerProduct : 0;
        $rule->trigger_min_qty = max(1, (int)Tools::getValue('trigger_min_qty'));
        $rule->date_start = $dateStart !== '' ? $dateStart : null;
        $rule->date_end = $dateEnd !== '' ? $dateEnd : null;
        $rule->max_uses = max(0, (int)Tools::getValue('max_uses'));
        $rule->priority = max(0, (int)Tools::getValue('priority'));
        if (!$rule->id) {
            $rule->uses_count = 0;
        }

        $ok = $rule->id ? $rule->update() : $rule->add();
        if (!$ok) {
            return array($this->l('No se ha podido guardar la regla.'));
        }
        $id = (int)$rule->id;
        JosraGiftRule::saveLevels($id, $levels);
        JosraGiftRule::saveRestrictions($id, $this->checkedIds('groups'), $this->checkedIds('zones'));
        return array();
    }

    public static function sortLevels($a, $b)
    {
        if ($a['trigger_value'] == $b['trigger_value']) {
            return 0;
        }
        return $a['trigger_value'] < $b['trigger_value'] ? -1 : 1;
    }

    // =========================================================================
    // ESTADÍSTICAS
    // =========================================================================

    protected function renderStats()
    {
        $from = Tools::getValue('josra_from');
        $to = Tools::getValue('josra_to');
        $from = ($from && Validate::isDate($from)) ? $from : '';
        $to = ($to && Validate::isDate($to)) ? $to : '';
        $idLang = (int)$this->context->language->id;
        $currency = $this->defaultCurrency();

        $stats = JosraGiftOrderLog::getStats($from, $to);
        $recent = JosraGiftOrderLog::getRecent(20, $from, $to);
        foreach ($recent as &$r) {
            $r['order_url'] = $this->orderUrl((int)$r['id_order']);
            $r['value'] = $this->module->formatPrice((float)$r['original_price'] * (int)$r['gift_qty'], $currency);
            $r['date'] = $this->module->formatDate($r['date_add'], true);
        }
        unset($r);

        $this->context->smarty->assign(array(
            'josra_stats' => $stats,
            'josra_stats_value' => $this->module->formatPrice($stats['value'], $currency),
            'josra_recent' => $recent,
            'josra_low_stock' => JosraGiftOrderLog::getLowStock((int)Configuration::get('JOSRA_GIFT_LOW_STOCK'), $idLang),
            'josra_from' => $from,
            'josra_to' => $to,
            'josra_stats_url' => $this->url(),
            'josra_stats_base' => $this->baseUrl(),
            'josra_stats_token' => $this->token(),
            'josra_module_name' => $this->module->name,
        ));
        return $this->module->fetchTemplate('admin/stats.tpl');
    }

    public function orderUrl($idOrder)
    {
        $link = $this->context->link;
        if (version_compare(_PS_VERSION_, '1.7.7.0', '>=')) {
            return $link->getAdminLink('AdminOrders', true, array('route' => 'admin_orders_view', 'orderId' => (int)$idOrder));
        }
        return $link->getAdminLink('AdminOrders') . '&id_order=' . (int)$idOrder . '&vieworder';
    }

    // =========================================================================
    // AJUSTES GENERALES
    // =========================================================================

    public static function configKeys()
    {
        return array(
            'JOSRA_GIFT_BADGE_TEXT',
            'JOSRA_GIFT_BADGE_BG_COLOR',
            'JOSRA_GIFT_BADGE_TEXT_COLOR',
            'JOSRA_GIFT_MOTIVATIONAL_TEXT',
            'JOSRA_GIFT_SHOW_PROGRESS',
            'JOSRA_GIFT_MARK_ORDER_LINE',
            'JOSRA_GIFT_LOW_STOCK',
            'JOSRA_GIFT_DEBUG',
        );
    }

    protected function renderConfigForm()
    {
        $yesNo = array(
            array('id' => 'on', 'value' => 1, 'label' => $this->l('Sí')),
            array('id' => 'off', 'value' => 0, 'label' => $this->l('No')),
        );
        $form = array(
            'form' => array(
                'legend' => array('title' => $this->l('Ajustes generales'), 'icon' => 'icon-cogs'),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Texto de la etiqueta'),
                        'name' => 'JOSRA_GIFT_BADGE_TEXT',
                        'lang' => true,
                        'required' => true,
                        'col' => 4,
                        'desc' => $this->l('Se ve junto al producto regalado en el carrito y se añade al nombre de la línea del pedido.'),
                    ),
                    array(
                        'type' => 'color',
                        'label' => $this->l('Color de fondo de la etiqueta'),
                        'name' => 'JOSRA_GIFT_BADGE_BG_COLOR',
                    ),
                    array(
                        'type' => 'color',
                        'label' => $this->l('Color del texto de la etiqueta'),
                        'name' => 'JOSRA_GIFT_BADGE_TEXT_COLOR',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Mensaje en el carrito'),
                        'name' => 'JOSRA_GIFT_MOTIVATIONAL_TEXT',
                        'lang' => true,
                        'desc' => $this->l('{amount} = lo que falta, {gift} = el regalo. Vacío = no se muestra.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Mostrar barra de progreso'),
                        'name' => 'JOSRA_GIFT_SHOW_PROGRESS',
                        'is_bool' => true,
                        'values' => $yesNo,
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Marcar el regalo en el pedido'),
                        'name' => 'JOSRA_GIFT_MARK_ORDER_LINE',
                        'is_bool' => true,
                        'values' => $yesNo,
                        'desc' => $this->l('Añade la etiqueta al nombre de la línea del pedido: se ve en el back-office, historial del cliente, factura y en lo que importe el pedido (p. ej. FacturaScripts).'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Aviso de stock bajo'),
                        'name' => 'JOSRA_GIFT_LOW_STOCK',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Avisar en esta página cuando a un regalo de una regla activa le queden estas unidades o menos.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Modo depuración'),
                        'name' => 'JOSRA_GIFT_DEBUG',
                        'is_bool' => true,
                        'values' => $yesNo,
                        'desc' => $this->l('Guarda un registro en var/logs/josra_giftproduct.log. Déjalo apagado en producción.'),
                    ),
                ),
                'submit' => array('title' => $this->l('Guardar'), 'name' => 'submitJosraGiftConfig'),
            ),
        );

        $helper = $this->formHelper('submitJosraGiftConfig');
        $values = array();
        foreach (self::configKeys() as $key) {
            $values[$key] = Configuration::get($key);
        }
        // Los campos multiidioma van como array por idioma.
        $values['JOSRA_GIFT_BADGE_TEXT'] = array();
        $values['JOSRA_GIFT_MOTIVATIONAL_TEXT'] = array();
        foreach (Language::getLanguages(false) as $lang) {
            $idL = (int)$lang['id_lang'];
            $values['JOSRA_GIFT_BADGE_TEXT'][$idL] = Configuration::get('JOSRA_GIFT_BADGE_TEXT', $idL);
            $values['JOSRA_GIFT_MOTIVATIONAL_TEXT'][$idL] = Configuration::get('JOSRA_GIFT_MOTIVATIONAL_TEXT', $idL);
        }
        $helper->fields_value = $values;
        return $helper->generateForm(array($form));
    }

    protected function saveConfig()
    {
        $errors = array();
        $badge = array();
        $motiv = array();
        $default = (int)Configuration::get('PS_LANG_DEFAULT');
        foreach (Language::getLanguages(false) as $lang) {
            $idL = (int)$lang['id_lang'];
            $badge[$idL] = trim(strip_tags((string)Tools::getValue('JOSRA_GIFT_BADGE_TEXT_' . $idL)));
            $motiv[$idL] = trim(strip_tags((string)Tools::getValue('JOSRA_GIFT_MOTIVATIONAL_TEXT_' . $idL)));
        }
        if ($badge[$default] === '') {
            $errors[] = $this->l('El texto de la etiqueta es obligatorio en el idioma por defecto.');
        }
        foreach ($badge as $idL => $v) {
            if ($v === '') {
                $badge[$idL] = $badge[$default];
            }
        }
        foreach (array('JOSRA_GIFT_BADGE_BG_COLOR', 'JOSRA_GIFT_BADGE_TEXT_COLOR') as $key) {
            $color = trim((string)Tools::getValue($key));
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $errors[] = $this->l('Los colores tienen que ser del tipo #e74c3c.');
                break;
            }
        }
        if ($errors) {
            return $errors;
        }
        Configuration::updateValue('JOSRA_GIFT_BADGE_TEXT', $badge);
        Configuration::updateValue('JOSRA_GIFT_MOTIVATIONAL_TEXT', $motiv);
        Configuration::updateValue('JOSRA_GIFT_BADGE_BG_COLOR', Tools::getValue('JOSRA_GIFT_BADGE_BG_COLOR'));
        Configuration::updateValue('JOSRA_GIFT_BADGE_TEXT_COLOR', Tools::getValue('JOSRA_GIFT_BADGE_TEXT_COLOR'));
        Configuration::updateValue('JOSRA_GIFT_SHOW_PROGRESS', (int)Tools::getValue('JOSRA_GIFT_SHOW_PROGRESS'));
        Configuration::updateValue('JOSRA_GIFT_MARK_ORDER_LINE', (int)Tools::getValue('JOSRA_GIFT_MARK_ORDER_LINE'));
        Configuration::updateValue('JOSRA_GIFT_LOW_STOCK', max(0, (int)Tools::getValue('JOSRA_GIFT_LOW_STOCK')));
        Configuration::updateValue('JOSRA_GIFT_DEBUG', (int)Tools::getValue('JOSRA_GIFT_DEBUG'));
        return array();
    }
}
