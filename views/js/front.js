/**
 * josra_giftproduct - front
 * Etiqueta en las líneas de regalo, avisos y bloque del carrito.
 * 1.7/8/9: escucha los eventos de PrestaShop (prestashop.on). 1.6: ajaxComplete.
 */
(function () {
    'use strict';

    var cfg = window.josraGift || {};
    var lastGiftKey = giftKey(cfg.gift);
    var refreshing = false;

    function giftKey(g) {
        return g ? [g.id_product, g.id_product_attribute, g.label].join('|') : '';
    }

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function closest(el, selector) {
        while (el && el.nodeType === 1) {
            if ((el.matches || el.msMatchesSelector || el.webkitMatchesSelector).call(el, selector)) {
                return el;
            }
            el = el.parentNode;
        }
        return null;
    }

    function makeBadge(text) {
        var span = document.createElement('span');
        span.className = 'josra-gift-badge';
        span.textContent = text;
        if (cfg.bg) {
            span.style.backgroundColor = cfg.bg;
        }
        if (cfg.fg) {
            span.style.color = cfg.fg;
        }
        return span;
    }

    function addBadgeTo(container, target) {
        if (!container || !target || !cfg.gift || container.querySelector('.josra-gift-badge')) {
            return;
        }
        target.appendChild(document.createTextNode(' '));
        target.appendChild(makeBadge(cfg.gift.label));
    }

    // -------------------------------------------------------------------------
    // Etiquetas
    // -------------------------------------------------------------------------

    function decorate() {
        // La etiqueta del hook sale junto a la papelera: se lleva al precio.
        var hookBadges = document.querySelectorAll('.cart-line-product-actions .josra-gift-badge, .josra-gift-badge[data-move]');
        Array.prototype.forEach.call(hookBadges, function (badge) {
            var item = closest(badge, '.cart-item, .product-line-grid, li');
            var price = item && item.querySelector('.product-line-info.product-price, .product-price, .current-price');
            if (price) {
                price.appendChild(badge);
            }
        });

        if (!cfg.gift) {
            return;
        }
        var pid = String(cfg.gift.id_product);
        var aid = String(cfg.gift.id_product_attribute);

        // 1.7+: línea del carrito por sus atributos data-*.
        var links = document.querySelectorAll('[data-id-product="' + pid + '"][data-id-product-attribute="' + aid + '"]');
        Array.prototype.forEach.call(links, function (el) {
            var item = closest(el, '.cart-item, .product-line-grid');
            if (item) {
                addBadgeTo(item, item.querySelector('.product-line-info.product-price, .product-price, .current-price')
                    || item.querySelector('.product-line-info'));
            }
        });

        // 1.6: filas del resumen del carrito (id="product_ID_ATTR_...").
        var rows = document.querySelectorAll('tr[id^="product_' + pid + '_' + aid + '_"]');
        Array.prototype.forEach.call(rows, function (row) {
            addBadgeTo(row, row.querySelector('.cart_description .product-name') || row.querySelector('.cart_description'));
        });

        // Resumen lateral del checkout: por nombre.
        var name = (cfg.gift.name || '').trim().toLowerCase();
        if (name) {
            var lines = document.querySelectorAll('#cart-summary-product-list .media, .cart-summary-products li, #js-checkout-summary .media');
            Array.prototype.forEach.call(lines, function (line) {
                var nameEl = line.querySelector('.product-name, .media-body a, a');
                if (nameEl && nameEl.textContent.trim().toLowerCase().indexOf(name) === 0) {
                    addBadgeTo(line, nameEl.parentNode);
                }
            });
        }
    }

    // -------------------------------------------------------------------------
    // Avisos
    // -------------------------------------------------------------------------

    function toast(message) {
        if (!message) {
            return;
        }
        var el = document.createElement('div');
        el.className = 'josra-gift-toast';
        el.setAttribute('role', 'status');
        el.textContent = '🎁 ' + message;
        document.body.appendChild(el);
        el.offsetHeight; // reflow para la animación
        el.className += ' josra-gift-toast--visible';
        setTimeout(function () {
            el.className = 'josra-gift-toast';
            setTimeout(function () {
                if (el.parentNode) {
                    el.parentNode.removeChild(el);
                }
            }, 400);
        }, 5000);
    }

    // -------------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------------

    function request(params, done) {
        if (!cfg.ajaxUrl) {
            return;
        }
        var body = [];
        for (var k in params) {
            if (params.hasOwnProperty(k)) {
                body.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
            }
        }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function () {
            var data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                data = null;
            }
            if (data && done) {
                done(data);
            }
        };
        xhr.send(body.join('&'));
    }

    function applyStatus(data) {
        cfg.gift = data.gift || null;
        var block = document.getElementById('josra-gift-block');
        if (block && typeof data.block === 'string') {
            var tmp = document.createElement('div');
            tmp.innerHTML = data.block;
            var fresh = tmp.querySelector('#josra-gift-block');
            if (fresh) {
                block.parentNode.replaceChild(fresh, block);
            }
        }
        decorate();
        toast(data.notice);
    }

    function refresh() {
        if (refreshing) {
            return;
        }
        refreshing = true;
        request({ josra_action: 'status' }, function (data) {
            refreshing = false;
            var changed = giftKey(data.gift) !== lastGiftKey;
            lastGiftKey = giftKey(data.gift);
            // 1.6 no repinta las líneas por AJAX: si el regalo cambia, se recarga.
            if (!cfg.ps17 && changed && document.getElementById('cart_summary')) {
                window.location.reload();
                return;
            }
            applyStatus(data);
        });
        setTimeout(function () { refreshing = false; }, 5000);
    }

    function restore(button) {
        if (button) {
            button.disabled = true;
        }
        request({ josra_action: 'restore' }, function (data) {
            lastGiftKey = giftKey(data.gift);
            if (cfg.ps17 && window.prestashop && typeof window.prestashop.emit === 'function'
                && document.querySelector('.cart-grid, .js-cart')) {
                // Pide a PrestaShop que repinte el carrito (luego llega 'updatedCart').
                window.prestashop.emit('updateCart', { reason: { linkAction: 'refresh' }, resp: {} });
                setTimeout(function () {
                    if (!document.querySelector('[data-id-product="' + (data.gift ? data.gift.id_product : 0) + '"]')) {
                        window.location.reload();
                    }
                }, 2500);
            } else {
                window.location.reload();
            }
        });
    }

    // -------------------------------------------------------------------------
    // Eventos
    // -------------------------------------------------------------------------

    function bind() {
        document.addEventListener('click', function (e) {
            var btn = closest(e.target, '.josra-gift-restore');
            if (btn) {
                e.preventDefault();
                restore(btn);
            }
        });

        if (window.prestashop && typeof window.prestashop.on === 'function') {
            // En el carrito: cuando PrestaShop ya ha repintado las líneas.
            window.prestashop.on('updatedCart', function () {
                refresh();
            });
            // Fuera del carrito (ficha de producto, listados): tras añadir.
            window.prestashop.on('updateCart', function () {
                if (!document.querySelector('.cart-grid, .js-cart')) {
                    setTimeout(refresh, 300);
                }
            });
        } else if (window.jQuery) {
            // 1.6: peticiones AJAX al controlador del carrito.
            window.jQuery(document).ajaxComplete(function (event, xhr, settings) {
                var data = typeof settings.data === 'string' ? settings.data : '';
                var url = settings.url || '';
                if ((data + url).indexOf('controller=cart') !== -1 && (data + url).indexOf('josra') === -1) {
                    setTimeout(refresh, 100);
                }
            });
        }
    }

    ready(function () {
        decorate();
        toast(cfg.notice);
        bind();
    });
}());
