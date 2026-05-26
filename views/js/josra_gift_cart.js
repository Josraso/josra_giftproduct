/**
 * josra_giftproduct - Frontend JS
 * Gestiona: badge de regalo, aviso al borrar, mensaje motivacional, notificación de desbloqueo
 */
(function () {
    'use strict';

    var cfg = window.josraGift || {};

    // =========================================================================
    // Inicialización
    // =========================================================================

    document.addEventListener('DOMContentLoaded', function () {
        josraGift.init();
    });

    var josraGift = {

        init: function () {
            this.injectBadges();
            this.checkGiftDeleted();
            this.checkGiftUnlocked();
            this.bindCartEvents();
        },

        // =====================================================================
        // BADGE: inyectar en todas las filas de producto del carrito
        // =====================================================================

        injectBadges: function () {
            // Detectar filas de carrito que son regalo por clase o data attribute
            var rows = document.querySelectorAll('[data-josra-gift="1"]');
            rows.forEach(function (row) {
                josraGift.addBadgeToRow(row);
            });

            // Fallback PS 1.6: buscar por clase legacy
            var rows16 = document.querySelectorAll('.josra-gift-row');
            rows16.forEach(function (row) {
                josraGift.addBadgeToRow(row);
            });
        },

        addBadgeToRow: function (row) {
            // No duplicar
            if (row.querySelector('.josra-gift-badge')) {
                return;
            }
            var nameEl = row.querySelector('.product-name, .cart_item .product-name, td.cart_product');
            if (!nameEl) {
                // Intentar encontrar cualquier elemento con el nombre
                nameEl = row.querySelector('a, .product-title, .cart-item-name');
            }
            if (nameEl) {
                var badge = document.createElement('span');
                badge.className = 'josra-gift-badge';
                badge.textContent = cfg.badge_text || 'REGALO';
                nameEl.appendChild(badge);
            }
        },

        // =====================================================================
        // AVISO: cliente borró el regalo manualmente
        // =====================================================================

        checkGiftDeleted: function () {
            if (!cfg.gift_deleted) {
                return;
            }
            this.showDeletedWarning();
        },

        showDeletedWarning: function () {
            var existing = document.getElementById('josra-gift-deleted-notice');
            if (existing) {
                return;
            }

            var notice = document.createElement('div');
            notice.id = 'josra-gift-deleted-notice';
            notice.className = 'josra-gift-notice josra-gift-notice--warning';
            notice.innerHTML = [
                '<span class="josra-gift-notice__icon">&#x1F381;</span>',
                '<span class="josra-gift-notice__text">' + (cfg.deleted_warning || 'Has eliminado tu producto de regalo.') + '</span>',
                '<button class="josra-gift-notice__btn josra-gift-restore-btn" type="button">',
                    (cfg.restore_label || 'Restaurar mi regalo'),
                '</button>',
                '<button class="josra-gift-notice__close" type="button" aria-label="Cerrar">&times;</button>',
            ].join('');

            // Insertar al inicio del carrito o body
            var cartContainer = document.querySelector(
                '#cart-summary, .cart-grid, .cart_container, #order-detail-content, body'
            );
            if (cartContainer) {
                cartContainer.insertBefore(notice, cartContainer.firstChild);
            }

            // Botón restaurar
            var restoreBtn = notice.querySelector('.josra-gift-restore-btn');
            if (restoreBtn) {
                restoreBtn.addEventListener('click', function () {
                    josraGift.restoreGift(notice);
                });
            }

            // Botón cerrar
            var closeBtn = notice.querySelector('.josra-gift-notice__close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function () {
                    notice.remove();
                });
            }
        },

        restoreGift: function (notice) {
            var btn = notice ? notice.querySelector('.josra-gift-restore-btn') : null;
            if (btn) {
                btn.disabled = true;
                btn.textContent = '...';
            }

            josraGift.ajax({
                action: 'restore_gift',
            }, function (data) {
                if (data && data.success) {
                    if (data.redirect_url) {
                        window.location.href = data.redirect_url;
                    } else {
                        window.location.reload();
                    }
                } else {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = cfg.restore_label || 'Restaurar mi regalo';
                    }
                }
            });
        },

        // =====================================================================
        // NOTIFICACIÓN: regalo desbloqueado o mejorado
        // =====================================================================

        checkGiftUnlocked: function () {
            var unlockedFlag = parseInt(
                document.querySelector('meta[name="josra-gift-unlocked"]')
                    ? document.querySelector('meta[name="josra-gift-unlocked"]').content
                    : '0'
            );
            if (unlockedFlag) {
                this.showUnlockedNotification(cfg.unlocked_msg || '¡Has desbloqueado un regalo!');
            }
        },

        showUnlockedNotification: function (message) {
            var notif = document.createElement('div');
            notif.className = 'josra-gift-toast';
            notif.innerHTML = '<span>&#x1F381;</span> ' + message;
            document.body.appendChild(notif);

            // Forzar reflow para animación CSS
            notif.offsetHeight;
            notif.classList.add('josra-gift-toast--visible');

            setTimeout(function () {
                notif.classList.remove('josra-gift-toast--visible');
                setTimeout(function () {
                    notif.remove();
                }, 500);
            }, 4000);
        },

        // =====================================================================
        // EVENTOS DE CARRITO (escucha eliminación de productos)
        // =====================================================================

        bindCartEvents: function () {
            // PrestaShop 1.7+ emite evento 'updateCart' en el documento
            document.addEventListener('updateCart', function (e) {
                // Re-inyectar badges tras actualización AJAX del carrito
                setTimeout(function () {
                    josraGift.injectBadges();
                    josraGift.checkDeletedViaAjax();
                }, 300);
            });

            // Escuchar clics en botones de eliminar producto del carrito
            document.addEventListener('click', function (e) {
                var removeBtn = e.target.closest(
                    '.remove-from-cart, .js-cart-line-product-delete, ' +
                    '.cart_quantity_delete, [data-link-action="delete-from-cart"]'
                );
                if (!removeBtn) {
                    return;
                }

                // Marcar para comprobar tras la petición AJAX
                josraGift._pendingDeleteCheck = true;
            });
        },

        checkDeletedViaAjax: function () {
            if (!josraGift._pendingDeleteCheck) {
                return;
            }
            josraGift._pendingDeleteCheck = false;

            josraGift.ajax({ action: 'get_cart_status' }, function (data) {
                if (data && data.deleted) {
                    josraGift.showDeletedWarning();
                }
                if (data && data.unlocked) {
                    josraGift.showUnlockedNotification(cfg.unlocked_msg || '¡Has desbloqueado un regalo!');
                }
            });
        },

        // =====================================================================
        // AJAX helper
        // =====================================================================

        ajax: function (params, callback) {
            if (!cfg.ajax_url) {
                return;
            }

            var formData = new FormData();
            Object.keys(params).forEach(function (k) {
                formData.append(k, params[k]);
            });

            fetch(cfg.ajax_url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (typeof callback === 'function') {
                    callback(data);
                }
            })
            .catch(function (err) {
                console.warn('[josra_gift] Ajax error:', err);
            });
        },
    };

    // Exponer para debug
    window._josraGiftFront = josraGift;

}());
