/**
 * josra_giftproduct - formulario de regla (back-office)
 * Tramos dinámicos, buscador de productos y combinaciones.
 */
(function ($) {
    'use strict';

    var cfg = window.josraGiftAdmin || { texts: {} };
    var T = cfg.texts || {};
    var searchTimer = null;
    var rowIndex = 1000;

    function esc(str) {
        return String(str === undefined || str === null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function currentType() {
        return $('select[name="trigger_type"]').val() || 'amount';
    }

    function applyType() {
        var type = currentType();
        var $levels = $('.josra-levels');
        var currency = $levels.data('currency') || '';
        var choice = type === 'choice';
        $('.josra-only-amount').toggle(type === 'amount' || choice);
        $('.josra-only-product').toggle(type === 'product');
        $('.josra-only-choice').toggle(choice);
        $levels.find('.josra-comb-label').toggle(!choice);
        $levels.find('.josra-comb-label-choice').toggle(choice);
        $levels.find('tr.josra-level').each(function () {
            var $row = $(this);
            var hasComb = $row.find('.josra-allowed option').length > 0 || $row.find('.josra-combination option').length > 1;
            $row.find('.josra-combination').toggle(!choice && hasComb);
            $row.find('.josra-allowed, .josra-allowed-help').toggle(choice && hasComb);
            $row.find('.josra-no-combination').toggle(!hasComb);
        });
        $levels.find('.josra-help-choice').toggle(choice);
        $levels.find('.josra-col-threshold').toggle(type !== 'product');
        $levels.find('.josra-threshold-label').text(type === 'quantity'
            ? (T.fromUnits || 'Desde (uds.)')
            : (T.fromAmount || 'Desde') + ' (' + currency + ')');
        $levels.find('.josra-level-add').toggle(type !== 'product');
        $levels.find('.josra-help-amount').toggle(type !== 'product' && !choice);
        $levels.find('.josra-help-product').toggle(type === 'product');
        // Por producto solo hay un regalo: se ocultan los tramos de más.
        $levels.find('tr.josra-level').each(function (i) {
            $(this).toggle(type !== 'product' || i === 0);
        });
    }

    function addRow() {
        var html = $('#josra-level-template').html().replace(/__I__/g, rowIndex++);
        $('.josra-levels-table tbody').append(html);
        applyType();
    }

    // -------------------------------------------------------------------------
    // Buscador de productos
    // -------------------------------------------------------------------------

    function resetCombination($picker) {
        var $row = $picker.closest('tr.josra-level');
        if (!$row.length) {
            return;
        }
        $row.find('.josra-combination').hide().html('<option value="0">' + esc(T.chooseCombination) + '</option>');
        $row.find('.josra-allowed').hide().empty();
        $row.find('.josra-allowed-help').hide();
        $row.find('.josra-no-combination').show();
    }

    function loadCombinations($row, idProduct) {
        var $select = $row.find('.josra-combination');
        $.getJSON(cfg.ajaxUrl, { josra_action: 'combinations', id_product: idProduct }).done(function (list) {
            var html = '<option value="0">' + esc(T.chooseCombination) + '</option>';
            $.each(list || [], function (i, c) {
                html += '<option value="' + parseInt(c.id, 10) + '">' + esc(c.name) + '</option>';
            });
            $select.html(html);
            var multi = '';
            $.each(list || [], function (i, c) {
                multi += '<option value="' + parseInt(c.id, 10) + '">' + esc(c.name) + '</option>';
            });
            $row.find('.josra-allowed').html(multi);
            if (list && list.length === 1) {
                $select.val(String(list[0].id));
            }
            applyType();
        });
    }

    function choose($picker, item) {
        $picker.find('.josra-picker-id').val(item.id);
        $picker.find('.josra-picker-input').val(item.name);
        $picker.removeClass('has-error');
        $picker.find('.josra-picker-results').hide().empty();
        var $row = $picker.closest('tr.josra-level');
        if ($row.length) {
            if (item.combinations) {
                loadCombinations($row, item.id);
            } else {
                resetCombination($picker);
            }
        }
    }

    $(document).on('input', '.josra-picker-input', function () {
        var $input = $(this);
        var $picker = $input.closest('.josra-picker');
        var $results = $picker.find('.josra-picker-results');
        var q = $.trim($input.val());

        $picker.find('.josra-picker-id').val(0);
        resetCombination($picker);
        clearTimeout(searchTimer);
        if (q.length < 2) {
            $results.hide().empty();
            return;
        }
        searchTimer = setTimeout(function () {
            $results.html('<li class="disabled"><a href="#">' + esc(T.searching) + '</a></li>').show();
            $.getJSON(cfg.ajaxUrl, { josra_action: 'search_product', q: q }).done(function (list) {
                if (!list || !list.length) {
                    $results.html('<li class="disabled"><a href="#">' + esc(T.noResults) + '</a></li>');
                    return;
                }
                var html = '';
                $.each(list, function (i, p) {
                    html += '<li><a href="#" data-item="' + esc(JSON.stringify(p)) + '">'
                        + '<strong>' + esc(p.name) + '</strong>'
                        + (p.reference ? ' <small class="text-muted">' + esc(p.reference) + '</small>' : '')
                        + ' <small class="text-muted">#' + parseInt(p.id, 10) + '</small>'
                        + '</a></li>';
                });
                $results.html(html).show();
            }).fail(function () {
                $results.html('<li class="disabled"><a href="#">' + esc(T.noResults) + '</a></li>');
            });
        }, 250);
    });

    // mousedown: se dispara antes de que el input pierda el foco y cierre la lista.
    $(document).on('mousedown', '.josra-picker-results a[data-item]', function (e) {
        e.preventDefault();
        choose($(this).closest('.josra-picker'), JSON.parse($(this).attr('data-item')));
    });
    $(document).on('click', '.josra-picker-results a', function (e) {
        e.preventDefault();
    });

    $(document).on('keydown', '.josra-picker-input', function (e) {
        if (e.which !== 13) {
            return;
        }
        e.preventDefault();
        var $first = $(this).closest('.josra-picker').find('.josra-picker-results a[data-item]').first();
        if ($first.length) {
            choose($(this).closest('.josra-picker'), JSON.parse($first.attr('data-item')));
        }
    });

    $(document).on('blur', '.josra-picker-input', function () {
        var $picker = $(this).closest('.josra-picker');
        setTimeout(function () {
            $picker.find('.josra-picker-results').hide();
            var noId = !parseInt($picker.find('.josra-picker-id').val(), 10);
            $picker.toggleClass('has-error', noId && $.trim($picker.find('.josra-picker-input').val()) !== '');
        }, 200);
    });

    // -------------------------------------------------------------------------
    // Arranque
    // -------------------------------------------------------------------------

    $(function () {
        if (!$('.josra-levels').length) {
            return;
        }
        $(document).on('change', 'select[name="trigger_type"]', applyType);
        $(document).on('click', '.josra-level-add', addRow);
        $(document).on('click', '.josra-level-remove', function () {
            $(this).closest('tr.josra-level').remove();
            if (!$('tr.josra-level').length) {
                addRow();
            }
        });
        if (!$('tr.josra-level').length) {
            addRow();
        }
        applyType();
    });
}(jQuery));
