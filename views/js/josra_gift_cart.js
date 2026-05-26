/**
 * josra_giftproduct - Frontend JS
 */
(function () {
    'use strict';

    var cfg = window.josraGift || {};

    document.addEventListener('DOMContentLoaded', function () {
        josraGift.init();
    });

    var josraGift = {

        init: function () {
            this.applyBadgeColors();
            this.positionBadges();
            this.injectMotivationalMessage();
            this.checkGiftDeleted();
            this.checkGiftUnlocked();
            this.bindCartEvents();
        },

        // =====================================================================
        // BADGE: aplicar colores configurados y mover junto al precio
        // =====================================================================

        applyBadgeColors: function () {
            var bg = cfg.badge_bg || '#e74c3c';
            var fg = cfg.badge_fg || '#ffffff';
            document.querySelectorAll('.josra-gift-badge').forEach(function (badge) {
                badge.style.backgroundColor = bg;
                badge.style.color = fg;
            });
        },

        positionBadges: function () {
            document.querySelectorAll('.josra-gift-badge').forEach(function (badge) {
                // Evitar mover si ya está dentro de un contenedor de precio
                if (badge.closest('.product-price, .current-price, .price')) {
                    return;
                }
                // Buscar el cart item contenedor
                var item = badge.closest(
                    'li.cart-item, article.cart-item, .cart_item, [class*="cart-item"]'
                );
                if (!item) return;

                // Buscar el elemento precio dentro del item
                var priceWrap = item.querySelector('.product-price, .current-price');
                if (priceWrap) {
                    priceWrap.appendChild(badge);
                    return;
                }
                // Fallback: mover junto al elemento .price
                var priceEl = item.querySelector('.price');
                if (priceEl && priceEl.parentNode) {
                    priceEl.parentNode.insertBefore(badge, priceEl.nextSibling);
                }
            });
        },

        // =====================================================================
        // MENSAJE MOTIVACIONAL
        // =====================================================================

        injectMotivationalMessage: function () {
            var text = cfg.motivational_text || '';

            // Actualizar si ya existe
            var existing = document.getElementById('josra-gift-motivational');
            if (existing) {
                if (text) {
                    existing.querySelector('.josra-gift-motivational__text').textContent = text;
                    existing.style.display = '';
                } else {
                    existing.style.display = 'none';
                }
                return;
            }

            if (!text) return;

            // Solo inyectar en páginas de carrito / checkout
            var anchor = document.querySelector(
                '.cart-grid-body, .cart-overview, #cart-summary-product-list, #cart, #checkout, .cart_container'
            );
            if (!anchor) return;

            var div = document.createElement('div');
            div.id = 'josra-gift-motivational';
            div.className = 'josra-gift-motivational';
            div.innerHTML =
                '<span class="josra-gift-motivational__icon">&#x1F381;</span>' +
                '<span class="josra-gift-motivational__text">' + this.escapeHtml(text) + '</span>';

            anchor.parentNode.insertBefore(div, anchor);
        },

        // =====================================================================
        // AVISO: cliente borró el regalo manualmente
        // =====================================================================

        checkGiftDeleted: function () {
            if (!cfg.gift_deleted) return;
            this.showDeletedWarning();
        },

        showDeletedWarning: function () {
            if (document.getElementById('josra-gift-deleted-notice')) return;

            var notice = document.createElement('div');
            notice.id = 'josra-gift-deleted-notice';
            notice.className = 'josra-gift-notice josra-gift-notice--warning';
            notice.innerHTML = [
                '<span class="josra-gift-notice__icon">&#x1F381;</span>',
                '<span class="josra-gift-notice__text">' + this.escapeHtml(cfg.deleted_warning || 'Has eliminado tu producto de regalo.') + '</span>',
                '<button class="josra-gift-notice__btn josra-gift-restore-btn" type="button">',
                    this.escapeHtml(cfg.restore_label || 'Restaurar mi regalo'),
                '</button>',
                '<button class="josra-gift-notice__close" type="button" aria-label="Cerrar">&times;</button>',
            ].join('');

            var target = document.querySelector(
                '#cart-summary, .cart-grid, .cart_container, #order-detail-content, body'
            );
            if (target) {
                target.insertBefore(notice, target.firstChild);
            }

            var restoreBtn = notice.querySelector('.josra-gift-restore-btn');
            if (restoreBtn) {
                restoreBtn.addEventListener('click', function () {
                    josraGift.restoreGift(notice);
                });
            }
            var closeBtn = notice.querySelector('.josra-gift-notice__close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function () { notice.remove(); });
            }
        },

        restoreGift: function (notice) {
            var btn = notice ? notice.querySelector('.josra-gift-restore-btn') : null;
            if (btn) { btn.disabled = true; btn.textContent = '...'; }

            josraGift.ajax({ action: 'restore_gift' }, function (data) {
                if (data && data.success) {
                    window.location.href = data.redirect_url || window.location.href;
                } else {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = cfg.restore_label || 'Restaurar mi regalo';
                    }
                }
            });
        },

        // =====================================================================
        // NOTIFICACIÓN: regalo desbloqueado
        // =====================================================================

        checkGiftUnlocked: function () {
            if (cfg.gift_unlocked) {
                this.showUnlockedNotification(cfg.unlocked_msg || '¡Has desbloqueado un regalo!');
            }
        },

        showUnlockedNotification: function (message) {
            var notif = document.createElement('div');
            notif.className = 'josra-gift-toast';
            notif.innerHTML = '&#x1F381; ' + this.escapeHtml(message);
            document.body.appendChild(notif);
            notif.offsetHeight; // reflow
            notif.classList.add('josra-gift-toast--visible');
            setTimeout(function () {
                notif.classList.remove('josra-gift-toast--visible');
                setTimeout(function () { notif.remove(); }, 500);
            }, 4000);
        },

        // =====================================================================
        // EVENTOS DE CARRITO
        // =====================================================================

        bindCartEvents: function () {
            document.addEventListener('updateCart', function () {
                setTimeout(function () {
                    josraGift.applyBadgeColors();
                    josraGift.positionBadges();
                    josraGift.checkDeletedViaAjax();
                }, 400);
            });

            document.addEventListener('click', function (e) {
                if (e.target.closest(
                    '.remove-from-cart, .js-cart-line-product-delete, ' +
                    '.cart_quantity_delete, [data-link-action="delete-from-cart"]'
                )) {
                    josraGift._pendingDeleteCheck = true;
                }
            });
        },

        checkDeletedViaAjax: function () {
            if (!josraGift._pendingDeleteCheck) return;
            josraGift._pendingDeleteCheck = false;

            josraGift.ajax({ action: 'get_cart_status' }, function (data) {
                if (data && data.deleted) josraGift.showDeletedWarning();
                if (data && data.unlocked) josraGift.showUnlockedNotification(cfg.unlocked_msg || '¡Has desbloqueado un regalo!');
            });
        },

        // =====================================================================
        // HELPERS
        // =====================================================================

        escapeHtml: function (str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        },

        ajax: function (params, callback) {
            if (!cfg.ajax_url) return;
            var formData = new FormData();
            Object.keys(params).forEach(function (k) { formData.append(k, params[k]); });
            fetch(cfg.ajax_url, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (typeof callback === 'function') callback(d); })
                .catch(function (e) { console.warn('[josra_gift]', e); });
        },
    };

    window._josraGiftFront = josraGift;

}());
