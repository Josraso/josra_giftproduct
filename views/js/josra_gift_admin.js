/**
 * josra_giftproduct - Admin JS
 * Gestiona el panel de configuración: CRUD reglas, buscador productos,
 * selector combinaciones, estadísticas con filtro de fechas, color picker preview.
 */
(function ($) {
    'use strict';

    var G = window.josraGiftAdmin || {};

    // =========================================================================
    // INIT
    // =========================================================================

    $(document).ready(function () {
        initTabs();
        initColorPickers();
        initLangSwitcher();
        initRulesTable();
        initStats();
    });

    // =========================================================================
    // TABS (Bootstrap ya lo maneja, solo inicialización)
    // =========================================================================

    function initTabs() {
        $('#josra-gift-nav-tabs a').on('click', function (e) {
            e.preventDefault();
            $(this).tab('show');
        });
    }

    // =========================================================================
    // COLOR PICKERS - Sincronizar input[type=color] con input[type=text] + preview
    // =========================================================================

    function initColorPickers() {
        syncColorPair('#josra-badge-bg', '#josra-badge-bg-text');
        syncColorPair('#josra-badge-text-color', '#josra-badge-text-color-text');

        $('[id^="josra-badge-bg"], [id^="josra-badge-text-color"]').on('input change', updateBadgePreview);
        updateBadgePreview();
    }

    function syncColorPair(colorInputSel, textInputSel) {
        $(colorInputSel).on('input', function () {
            $(textInputSel).val(this.value);
            updateBadgePreview();
        });
        $(textInputSel).on('input', function () {
            var val = this.value.trim();
            if (/^#[0-9a-fA-F]{6}$/.test(val)) {
                $(colorInputSel).val(val);
                updateBadgePreview();
            }
        });
    }

    function updateBadgePreview() {
        var bg   = $('#josra-badge-bg').val() || '#e74c3c';
        var fg   = $('#josra-badge-text-color').val() || '#ffffff';
        var text = $('.josra-lang-field input:visible').first().val() || 'REGALO';
        $('#josra-badge-preview')
            .css({ 'background-color': bg, 'color': fg })
            .text(text);
    }

    // =========================================================================
    // LANGUAGE SWITCHER
    // =========================================================================

    function initLangSwitcher() {
        $(document).on('click', '.josra-lang-btn', function () {
            var langId = $(this).data('lang');
            // Badge text
            $('.josra-lang-field').hide();
            $('.josra-lang-field input').each(function () {
                if (this.id === 'badge-text-' + langId) {
                    $(this).closest('.josra-lang-field').show();
                }
            });
            // Motivational text
            $('.josra-lang-field-motiv').hide();
            $('.josra-lang-field-motiv').each(function () {
                var inp = $(this).find('input');
                if (inp.attr('name') === 'JOSRA_GIFT_MOTIVATIONAL_TEXT_' + langId) {
                    $(this).show();
                }
            });
            // Botones activos
            $('.josra-lang-btn').removeClass('btn-primary').addClass('btn-default');
            $(this).removeClass('btn-default').addClass('btn-primary');

            updateBadgePreview();
        });
    }

    // =========================================================================
    // TABLA DE REGLAS
    // =========================================================================

    function initRulesTable() {
        // Nuevo
        $(document).on('click', '#josra-btn-new-rule, #josra-btn-new-rule-empty', function () {
            openRuleModal(0);
        });

        // Editar
        $(document).on('click', '.josra-btn-edit-rule', function () {
            openRuleModal(parseInt($(this).data('id')));
        });

        // Borrar
        $(document).on('click', '.josra-btn-delete-rule', function () {
            var id   = $(this).data('id');
            var name = $(this).data('name');
            if (!confirm('¿Eliminar la regla "' + name + '"? Esta acción no se puede deshacer.')) {
                return;
            }
            adminAjax({ action: 'delete_rule', id_josra_gift_rule: id }, function (data) {
                if (data.success) {
                    $('tr[data-rule-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
                    showAdminAlert('success', data.message);
                } else {
                    showAdminAlert('danger', data.error || 'Error al eliminar');
                }
            });
        });

        // Toggle activo
        $(document).on('click', '.josra-gift-toggle-active', function () {
            var id = $(this).data('id');
            var $el = $(this);
            adminAjax({ action: 'toggle_rule', id_josra_gift_rule: id }, function (data) {
                if (data.success) {
                    $el.find('.josra-active-label')
                       .removeClass('label-success label-danger')
                       .addClass(data.active ? 'label-success' : 'label-danger')
                       .text(data.active ? 'Sí' : 'No');
                }
            });
        });

        // Guardar desde modal
        $(document).on('click', '#josra-rule-save-btn', function () {
            saveRule();
        });
    }

    // =========================================================================
    // MODAL DE REGLA
    // =========================================================================

    function openRuleModal(ruleId) {
        G.editingRuleId = ruleId;
        G.levelCount    = 0;

        var title = ruleId ? 'Editar regla' : 'Nueva regla de regalo';
        $('#josra-rule-modal-title').text(title);

        var modalBody = buildRuleModalHtml();
        $('#josra-rule-modal-body').html(modalBody);

        if (ruleId) {
            // Cargar datos existentes
            adminAjax({ action: 'get_rule', id_josra_gift_rule: ruleId }, function (data) {
                if (data.rule) {
                    fillRuleForm(data.rule, data.levels || [], data.restrictions || []);
                }
            });
        } else {
            // Añadir primer tramo vacío
            addLevelRow();
        }

        initModalEvents();
        $('#josra-rule-modal').modal('show');
    }

    function buildRuleModalHtml() {
        return [
            '<form id="josra-rule-form" class="form-horizontal">',
            '<input type="hidden" id="josra-rule-id" value="0">',

            // Nombre
            '<div class="form-group">',
            '<label class="control-label col-lg-3">Nombre *</label>',
            '<div class="col-lg-9"><input type="text" id="josra-rule-name" class="form-control" placeholder="Ej: Campaña verano 2024" maxlength="128"></div>',
            '</div>',

            // Tipo disparador
            '<div class="form-group">',
            '<label class="control-label col-lg-3">Tipo de regla</label>',
            '<div class="col-lg-9">',
            '<select id="josra-rule-trigger-type" class="form-control" style="width:auto">',
            '<option value="amount">Por importe del carrito (€)</option>',
            '<option value="quantity">Por cantidad de productos</option>',
            '<option value="product">Por producto en carrito</option>',
            '</select>',
            '</div></div>',

            // Modo cálculo
            '<div class="form-group josra-amount-only">',
            '<label class="control-label col-lg-3">Cálculo del importe</label>',
            '<div class="col-lg-9">',
            '<select id="josra-rule-calc-mode" class="form-control" style="width:auto">',
            '<option value="without_tax">Sin IVA</option>',
            '<option value="with_tax">Con IVA</option>',
            '</select>',
            '</div></div>',

            // Incluir envío
            '<div class="form-group josra-amount-only">',
            '<label class="control-label col-lg-3">Incluir envío</label>',
            '<div class="col-lg-9"><div class="checkbox"><label>',
            '<input type="checkbox" id="josra-rule-include-shipping"> Sumar el coste de envío al importe',
            '</label></div></div></div>',

            // Producto disparador (solo para trigger_type=product)
            '<div class="form-group josra-product-trigger-only" style="display:none;">',
            '<label class="control-label col-lg-3">Producto disparador</label>',
            '<div class="col-lg-9">',
            '<div class="josra-product-picker" id="josra-trigger-product-picker">',
            '<input type="text" class="form-control" id="josra-trigger-product-search"',
            '       placeholder="Busca el producto que activa el regalo..."',
            '       autocomplete="off">',
            '<div id="josra-trigger-product-results" style="display:none;"></div>',
            '<input type="hidden" id="josra-trigger-product-id" value="">',
            '</div>',
            '</div></div>',

            '<div class="form-group josra-product-trigger-only" style="display:none;">',
            '<label class="control-label col-lg-3">Cantidad mínima en carrito</label>',
            '<div class="col-lg-3">',
            '<input type="number" id="josra-rule-trigger-min-qty" class="form-control" value="1" min="1" step="1">',
            '</div>',
            '<div class="col-lg-6"><p class="help-block">El cliente debe tener al menos esta cantidad del producto en el carrito.</p></div>',
            '</div>',

            // Prioridad
            '<div class="form-group">',
            '<label class="control-label col-lg-3">Prioridad</label>',
            '<div class="col-lg-3"><input type="number" id="josra-rule-priority" class="form-control" value="0" min="0"></div>',
            '<div class="col-lg-6"><p class="help-block">Mayor número = se evalúa antes.</p></div>',
            '</div>',

            // Activo
            '<div class="form-group">',
            '<label class="control-label col-lg-3">Activo</label>',
            '<div class="col-lg-9"><div class="checkbox"><label>',
            '<input type="checkbox" id="josra-rule-active" checked> Regla activa',
            '</label></div></div></div>',

            // Fechas
            '<div class="form-group">',
            '<label class="control-label col-lg-3">Fecha inicio</label>',
            '<div class="col-lg-3"><input type="date" id="josra-rule-date-start" class="form-control"></div>',
            '<label class="control-label col-lg-2">Fecha fin</label>',
            '<div class="col-lg-3"><input type="date" id="josra-rule-date-end" class="form-control"></div>',
            '</div>',

            // Máximo usos
            '<div class="form-group">',
            '<label class="control-label col-lg-3">Máx. regalos totales</label>',
            '<div class="col-lg-3"><input type="number" id="josra-rule-max-uses" class="form-control" placeholder="Ilimitado" min="1"></div>',
            '</div>',

            '<hr>',

            // TRAMOS
            '<h4><i class="icon-list-ol"></i> Tramos de regalo</h4>',
            '<p class="help-block">Cada tramo define un importe/cantidad mínima y el producto de regalo correspondiente.</p>',
            '<div id="josra-levels-container"></div>',
            '<button type="button" class="btn btn-default btn-sm" id="josra-add-level">',
            '<i class="icon-plus"></i> Añadir tramo',
            '</button>',

            '<hr>',

            // RESTRICCIONES
            '<h4><i class="icon-filter"></i> Segmentación (opcional)</h4>',
            '<p class="help-block">Sin restricciones la regla aplica a todos los clientes y zonas.</p>',
            '<div id="josra-restrictions-container"></div>',
            '<button type="button" class="btn btn-default btn-sm" id="josra-add-restriction">',
            '<i class="icon-plus"></i> Añadir restricción',
            '</button>',

            '</form>',
        ].join('\n');
    }

    function fillRuleForm(rule, levels, restrictions) {
        $('#josra-rule-id').val(rule.id_josra_gift_rule);
        $('#josra-rule-name').val(rule.name);
        $('#josra-rule-trigger-type').val(rule.trigger_type);
        $('#josra-rule-calc-mode').val(rule.calc_mode);
        $('#josra-rule-include-shipping').prop('checked', rule.include_shipping == 1);
        $('#josra-rule-priority').val(rule.priority);
        $('#josra-rule-active').prop('checked', rule.active == 1);
        $('#josra-rule-date-start').val(rule.date_start || '');
        $('#josra-rule-date-end').val(rule.date_end || '');
        $('#josra-rule-max-uses').val(rule.max_uses || '');

        toggleAmountOnlyFields(rule.trigger_type);

        // Restaurar campos de producto disparador
        if (rule.trigger_type === 'product') {
            if (rule.id_trigger_product) {
                $('#josra-trigger-product-id').val(rule.id_trigger_product);
                if (rule.trigger_product_name) {
                    $('#josra-trigger-product-search').val(rule.trigger_product_name);
                }
            }
            $('#josra-rule-trigger-min-qty').val(rule.trigger_min_qty || 1);
        }

        // Tramos
        levels.forEach(function (level) {
            addLevelRow(level);
        });
        if (levels.length === 0) {
            addLevelRow();
        }

        // Restricciones
        restrictions.forEach(function (r) {
            addRestrictionRow(r);
        });
    }

    function initModalEvents() {
        // Cambio de tipo disparador
        $('#josra-rule-trigger-type').off('change').on('change', function () {
            toggleAmountOnlyFields($(this).val());
        });

        // Añadir tramo
        $('#josra-add-level').off('click').on('click', function () {
            addLevelRow();
        });

        // Añadir restricción
        $('#josra-add-restriction').off('click').on('click', function () {
            addRestrictionRow();
        });

        // Borrar tramo
        $(document).off('click.josra-level').on('click.josra-level', '.josra-remove-level', function () {
            $(this).closest('.josra-level-row').remove();
        });

        // Borrar restricción
        $(document).off('click.josra-restr').on('click.josra-restr', '.josra-remove-restriction', function () {
            $(this).closest('.josra-restriction-row').remove();
        });

        // Buscador de producto disparador (trigger)
        $(document).off('input.josra-trigger').on('input.josra-trigger', '#josra-trigger-product-search', debounce(function () {
            var $input   = $(this);
            var $results = $('#josra-trigger-product-results');
            var q        = $input.val().trim();
            if (q.length < 2) {
                $results.hide().empty();
                return;
            }
            adminAjax({ action: 'search_product', q: q }, function (products) {
                renderTriggerProductResults($results, products, $input);
            });
        }, 300));

        // Buscador de productos
        $(document).off('input.josra-product').on('input.josra-product', '.josra-product-search', debounce(function () {
            var $input   = $(this);
            var $results = $input.closest('.josra-level-row').find('.josra-product-results');
            var q        = $input.val().trim();
            if (q.length < 2) {
                $results.hide().empty();
                return;
            }
            adminAjax({ action: 'search_product', q: q }, function (products) {
                renderProductResults($results, products, $input);
            });
        }, 300));
    }

    function toggleAmountOnlyFields(triggerType) {
        if (triggerType === 'amount') {
            $('.josra-amount-only').show();
            $('.josra-product-trigger-only').hide();
            $('.josra-level-trigger-value').closest('.col-lg-3').show();
        } else if (triggerType === 'quantity') {
            $('.josra-amount-only').hide();
            $('.josra-product-trigger-only').hide();
            $('.josra-level-trigger-value').closest('.col-lg-3').show();
        } else if (triggerType === 'product') {
            $('.josra-amount-only').hide();
            $('.josra-product-trigger-only').show();
            // Para tipo product, ocultar el campo trigger_value del tramo (no aplica)
            $('.josra-level-trigger-value').closest('.col-lg-3').hide();
        }
    }

    // -------------------------------------------------------------------------
    // Tramos (levels)
    // -------------------------------------------------------------------------

    function addLevelRow(data) {
        data = data || {};
        var idx = G.levelCount++;
        var labelTrigger = 'Importe mínimo (€)';

        var html = [
            '<div class="josra-level-row panel panel-default" data-idx="' + idx + '">',
            '<div class="panel-body">',
            '<div class="row">',

            '<div class="col-lg-3">',
            '<label>' + labelTrigger + '</label>',
            '<input type="number" class="form-control josra-level-trigger-value"',
            '       value="' + (data.trigger_value || '') + '"',
            '       min="0" step="0.01" placeholder="0.00">',
            '</div>',

            '<div class="col-lg-6">',
            '<label>Producto regalo</label>',
            '<div class="josra-product-picker">',
            '<input type="text" class="form-control josra-product-search"',
            '       placeholder="Busca por nombre o referencia..."',
            '       value="' + (data.product_name || '') + '"',
            '       autocomplete="off">',
            '<div class="josra-product-results" style="display:none;"></div>',
            '<input type="hidden" class="josra-level-product-id" value="' + (data.id_product || '') + '">',
            '<input type="hidden" class="josra-level-attr-id" value="' + (data.id_product_attribute || '') + '">',
            '</div>',

            // Selector de combinación (se muestra si el producto tiene combinaciones)
            '<div class="josra-combination-wrap" style="' + (data.id_product ? '' : 'display:none;') + 'margin-top:8px;">',
            '<label>Combinación</label>',
            '<select class="form-control josra-combination-select">',
            '<option value="0">Sin combinación / única</option>',
            '</select>',
            '</div>',

            '</div>',

            '<div class="col-lg-2 josra-gift-qty-wrap">',
            '<label>Uds. a regalar</label>',
            '<input type="number" class="form-control josra-level-gift-qty"',
            '       value="' + (data.gift_qty || 1) + '"',
            '       min="1" step="1">',
            '</div>',

            '<div class="col-lg-1" style="padding-top:24px;">',
            '<button type="button" class="btn btn-danger btn-sm josra-remove-level" title="Eliminar tramo">',
            '<i class="icon-trash"></i>',
            '</button>',
            '</div>',

            '</div>',
            '</div>',
            '</div>',
        ].join('');

        $('#josra-levels-container').append(html);

        // Si hay producto, cargar combinaciones
        if (data.id_product) {
            var $row = $('#josra-levels-container .josra-level-row[data-idx="' + idx + '"]');
            loadCombinations(data.id_product, $row.find('.josra-combination-select'), data.id_product_attribute);
        }
    }

    // -------------------------------------------------------------------------
    // Buscador de productos
    // -------------------------------------------------------------------------

    function renderProductResults($container, products, $input) {
        $container.empty();
        if (!products || products.length === 0) {
            $container.html('<div class="josra-no-results">Sin resultados</div>').show();
            return;
        }

        products.forEach(function (p) {
            var $item = $('<div class="josra-product-result-item">')
                .html([
                    p.image_url ? '<img src="' + p.image_url + '" alt="" class="josra-result-img">' : '',
                    '<div class="josra-result-info">',
                    '<strong>' + escHtml(p.name) + '</strong>',
                    p.reference ? '<small> — ' + escHtml(p.reference) + '</small>' : '',
                    '<br><small class="text-muted">Stock: ' + p.stock + '</small>',
                    '</div>',
                ].join(''))
                .on('click', function () {
                    var $row = $input.closest('.josra-level-row');
                    $input.val(p.name);
                    $row.find('.josra-level-product-id').val(p.id);
                    $row.find('.josra-level-attr-id').val(0);
                    $container.hide().empty();

                    // Cargar combinaciones si las tiene
                    if (p.has_combinations) {
                        $row.find('.josra-combination-wrap').show();
                        loadCombinations(p.id, $row.find('.josra-combination-select'), 0);
                    } else {
                        $row.find('.josra-combination-wrap').hide();
                        $row.find('.josra-combination-select').html('<option value="0">Sin combinación</option>');
                    }
                });
            $container.append($item);
        });

        $container.show();

        // Cerrar al hacer click fuera
        $(document).one('click', function (e) {
            if (!$(e.target).closest('.josra-product-picker').length) {
                $container.hide();
            }
        });
    }

    function renderTriggerProductResults($container, products, $input) {
        $container.empty();
        if (!products || products.length === 0) {
            $container.html('<div class="josra-no-results">Sin resultados</div>').show();
            return;
        }
        products.forEach(function (p) {
            var $item = $('<div class="josra-product-result-item">')
                .html([
                    p.image_url ? '<img src="' + p.image_url + '" alt="" class="josra-result-img">' : '',
                    '<div class="josra-result-info">',
                    '<strong>' + escHtml(p.name) + '</strong>',
                    p.reference ? '<small> — ' + escHtml(p.reference) + '</small>' : '',
                    '</div>',
                ].join(''))
                .on('click', function () {
                    $input.val(p.name);
                    $('#josra-trigger-product-id').val(p.id);
                    $container.hide().empty();
                });
            $container.append($item);
        });
        $container.show();
        $(document).one('click', function (e) {
            if (!$(e.target).closest('#josra-trigger-product-picker').length) {
                $container.hide();
            }
        });
    }

    function loadCombinations(productId, $select, selectedAttrId) {
        adminAjax({ action: 'get_combinations', id_product: productId }, function (combinations) {
            $select.empty().append('<option value="0">Sin combinación / única</option>');
            combinations.forEach(function (c) {
                var $opt = $('<option>')
                    .val(c.id)
                    .text(c.name + ' — ' + c.price + ' (Stock: ' + c.stock + ')');
                if (c.id == selectedAttrId) {
                    $opt.prop('selected', true);
                }
                $select.append($opt);
            });

            // Escuchar cambio de combinación
            $select.off('change').on('change', function () {
                $(this).closest('.josra-level-row').find('.josra-level-attr-id').val($(this).val());
            });
        });
    }

    // -------------------------------------------------------------------------
    // Restricciones
    // -------------------------------------------------------------------------

    function addRestrictionRow(data) {
        data = data || {};
        var html = [
            '<div class="josra-restriction-row row" style="margin-bottom:8px;">',
            '<div class="col-lg-4">',
            '<select class="form-control josra-restriction-type">',
            '<option value="group" ' + (data.restriction_type === 'group' ? 'selected' : '') + '>Grupo de clientes</option>',
            '<option value="zone"  ' + (data.restriction_type === 'zone'  ? 'selected' : '') + '>Zona geográfica</option>',
            '</select>',
            '</div>',
            '<div class="col-lg-6">',
            '<input type="number" class="form-control josra-restriction-value"',
            '       value="' + (data.id_value || '') + '"',
            '       placeholder="ID del grupo o zona de PrestaShop">',
            '</div>',
            '<div class="col-lg-1">',
            '<button type="button" class="btn btn-danger btn-sm josra-remove-restriction">',
            '<i class="icon-trash"></i>',
            '</button>',
            '</div>',
            '</div>',
        ].join('');

        $('#josra-restrictions-container').append(html);
    }

    // =========================================================================
    // GUARDAR REGLA
    // =========================================================================

    function saveRule() {
        var ruleId = parseInt($('#josra-rule-id').val()) || 0;
        var name   = $('#josra-rule-name').val().trim();

        if (!name) {
            alert('El nombre de la regla es obligatorio.');
            return;
        }

        // Recoger tramos
        var levels = [];
        var triggerType = $('#josra-rule-trigger-type').val();
        $('.josra-level-row').each(function () {
            var triggerVal = $(this).find('.josra-level-trigger-value').val();
            var productId  = $(this).find('.josra-level-product-id').val();
            var attrId     = $(this).find('.josra-combination-select').val() || $(this).find('.josra-level-attr-id').val() || 0;
            var giftQty    = parseInt($(this).find('.josra-level-gift-qty').val()) || 1;

            if ((triggerType !== 'product' ? triggerVal : true) && productId) {
                levels.push({
                    trigger_value:        parseFloat(triggerVal) || 0,
                    id_product:           parseInt(productId),
                    id_product_attribute: parseInt(attrId),
                    gift_qty:             giftQty,
                });
            }
        });

        if (levels.length === 0) {
            alert('Debes añadir al menos un tramo con producto.');
            return;
        }

        // Recoger restricciones
        var restrictions = [];
        $('.josra-restriction-row').each(function () {
            var type  = $(this).find('.josra-restriction-type').val();
            var value = $(this).find('.josra-restriction-value').val();
            if (type && value) {
                restrictions.push({ type: type, id_value: parseInt(value) });
            }
        });

        var payload = {
            action:             'save_rule',
            id_josra_gift_rule: ruleId,
            name:               name,
            active:             $('#josra-rule-active').is(':checked') ? 1 : 0,
            trigger_type:       triggerType,
            calc_mode:          $('#josra-rule-calc-mode').val(),
            include_shipping:   $('#josra-rule-include-shipping').is(':checked') ? 1 : 0,
            priority:           parseInt($('#josra-rule-priority').val()) || 0,
            date_start:         $('#josra-rule-date-start').val(),
            date_end:           $('#josra-rule-date-end').val(),
            max_uses:           $('#josra-rule-max-uses').val(),
            levels:             JSON.stringify(levels),
            restrictions:       JSON.stringify(restrictions),
            id_trigger_product: (triggerType === 'product') ? parseInt($('#josra-trigger-product-id').val()) || 0 : 0,
            trigger_min_qty:    (triggerType === 'product') ? parseInt($('#josra-rule-trigger-min-qty').val()) || 1 : 1,
        };

        var $btn = $('#josra-rule-save-btn').prop('disabled', true).text('Guardando...');

        adminAjax(payload, function (data) {
            $btn.prop('disabled', false).html('<i class="icon-save"></i> Guardar regla');
            if (data.success) {
                $('#josra-rule-modal').modal('hide');
                showAdminAlert('success', data.message);
                setTimeout(function () { window.location.reload(); }, 1200);
            } else {
                showAdminAlert('danger', data.error || 'Error al guardar');
            }
        });
    }

    // =========================================================================
    // ESTADÍSTICAS
    // =========================================================================

    function initStats() {
        // Cargar stats iniciales con los datos del template
        renderRecentGifts([]);

        $('#josra-btn-load-stats').on('click', function () {
            loadStats(
                $('#josra-stats-from').val(),
                $('#josra-stats-to').val()
            );
        });

        $('#josra-btn-reset-stats').on('click', function () {
            $('#josra-stats-from, #josra-stats-to').val('');
            loadStats('', '');
        });
    }

    function loadStats(dateFrom, dateTo) {
        adminAjax({
            action:    'get_stats',
            date_from: dateFrom,
            date_to:   dateTo,
            limit:     10,
        }, function (data) {
            if (!data.stats) { return; }
            var s = data.stats;
            $('#stat-total-orders').text(s.total_orders_with_gift);
            $('#stat-total-value').text(s.total_gifted_value.toFixed(2) + ' €');
            $('#stat-top-product').text(s.top_product ? s.top_product.product_name : '-');
            $('#stat-top-rule').text(s.top_rule ? s.top_rule.rule_name : '-');
            renderRecentGifts(data.recent || []);
        });
    }

    function renderRecentGifts(gifts) {
        var $wrap = $('#josra-recent-gifts-wrap');
        if (!gifts || gifts.length === 0) {
            $wrap.html('<p class="text-muted">Sin datos en el período seleccionado.</p>');
            return;
        }

        var rows = gifts.map(function (g) {
            return '<tr>' +
                '<td><a href="' + escHtml(g.order_reference || '') + '">' + escHtml(g.order_reference || ('#' + g.id_order)) + '</a></td>' +
                '<td>' + escHtml(g.product_name) + '</td>' +
                '<td>' + escHtml(g.rule_name) + '</td>' +
                '<td>' + parseFloat(g.original_price).toFixed(2) + ' €</td>' +
                '<td>' + g.date_add + '</td>' +
                '</tr>';
        }).join('');

        $wrap.html(
            '<table class="table table-striped table-bordered">' +
            '<thead><tr><th>Pedido</th><th>Producto regalo</th><th>Regla</th><th>Valor real</th><th>Fecha</th></tr></thead>' +
            '<tbody>' + rows + '</tbody></table>'
        );
    }

    // =========================================================================
    // AJAX HELPER
    // =========================================================================

    function adminAjax(params, callback) {
        var data = $.extend({ token: getAdminToken() }, params);
        $.ajax({
            url:      G.ajaxUrl,
            type:     'POST',
            data:     data,
            dataType: 'json',
            success:  function (response) {
                if (typeof callback === 'function') { callback(response); }
            },
            error: function (xhr) {
                console.error('[josra_gift_admin] Ajax error', xhr.status, xhr.responseText);
                if (typeof callback === 'function') { callback({ error: 'Ajax error ' + xhr.status }); }
            },
        });
    }

    function getAdminToken() {
        var m = window.location.search.match(/[?&]token=([^&]+)/);
        return m ? m[1] : '';
    }

    // =========================================================================
    // UI HELPERS
    // =========================================================================

    function showAdminAlert(type, msg) {
        var $alert = $(
            '<div class="alert alert-' + type + ' josra-admin-alert">' +
            '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
            escHtml(msg) +
            '</div>'
        );
        $('#josra-gift-admin').prepend($alert);
        setTimeout(function () { $alert.fadeOut(400, function () { $(this).remove(); }); }, 4000);
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            clearTimeout(timer);
            var ctx = this, args = arguments;
            timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
        };
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

}(jQuery));
