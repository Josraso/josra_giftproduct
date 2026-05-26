-- josra_giftproduct - SQL de instalación
-- Usar PREFIX_ como placeholder, se sustituye en tiempo de instalación

CREATE TABLE IF NOT EXISTS `PREFIX_josra_gift_rule` (
    `id_josra_gift_rule`    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`                  VARCHAR(128)     NOT NULL,
    `active`                TINYINT(1)       NOT NULL DEFAULT 1,
    `trigger_type`          ENUM('amount','quantity','product') NOT NULL DEFAULT 'amount',
    `id_trigger_product`    INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Solo para trigger_type=product',
    `trigger_min_qty`       INT(10) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Cantidad mínima del producto disparador',
    `calc_mode`             ENUM('without_tax','with_tax') NOT NULL DEFAULT 'without_tax',
    `include_shipping`      TINYINT(1)       NOT NULL DEFAULT 0,
    `date_start`            DATE             NULL DEFAULT NULL,
    `date_end`              DATE             NULL DEFAULT NULL,
    `max_uses`              INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = ilimitado',
    `uses_count`            INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `priority`              INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Mayor = se evalúa antes',
    `date_add`              DATETIME         NOT NULL,
    `date_upd`              DATETIME         NOT NULL,
    PRIMARY KEY (`id_josra_gift_rule`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tramos de cada regla (varios niveles de importe → diferente regalo)
CREATE TABLE IF NOT EXISTS `PREFIX_josra_gift_rule_level` (
    `id_josra_gift_rule_level`  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_josra_gift_rule`        INT(10) UNSIGNED NOT NULL,
    `trigger_value`             DECIMAL(20,6)    NOT NULL COMMENT 'Importe o cantidad mínima para este tramo',
    `sort_order`                INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Orden ascendente de tramo',
    PRIMARY KEY (`id_josra_gift_rule_level`),
    KEY `idx_rule` (`id_josra_gift_rule`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Producto regalo de cada tramo (con combinación específica)
CREATE TABLE IF NOT EXISTS `PREFIX_josra_gift_rule_product` (
    `id_josra_gift_rule_product`    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_josra_gift_rule_level`      INT(10) UNSIGNED NOT NULL,
    `id_product`                    INT(10) UNSIGNED NOT NULL,
    `id_product_attribute`          INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = sin combinación',
    `gift_qty`                      INT(10) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Unidades a regalar',
    PRIMARY KEY (`id_josra_gift_rule_product`),
    KEY `idx_level` (`id_josra_gift_rule_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Restricciones de segmentación por regla (grupos de clientes, zonas geográficas)
CREATE TABLE IF NOT EXISTS `PREFIX_josra_gift_rule_restriction` (
    `id_josra_gift_rule_restriction`    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_josra_gift_rule`                INT(10) UNSIGNED NOT NULL,
    `restriction_type`                  ENUM('group','zone') NOT NULL,
    `id_value`                          INT(10) UNSIGNED NOT NULL COMMENT 'id_group o id_zone de PS',
    PRIMARY KEY (`id_josra_gift_rule_restriction`),
    KEY `idx_rule_type` (`id_josra_gift_rule`, `restriction_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de regalos aplicados en pedidos (para estadísticas y backoffice)
CREATE TABLE IF NOT EXISTS `PREFIX_josra_gift_order_log` (
    `id_josra_gift_order_log`   INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_order`                  INT(10) UNSIGNED NOT NULL,
    `id_cart`                   INT(10) UNSIGNED NOT NULL,
    `id_josra_gift_rule`        INT(10) UNSIGNED NOT NULL,
    `id_josra_gift_rule_level`  INT(10) UNSIGNED NOT NULL,
    `id_product`                INT(10) UNSIGNED NOT NULL,
    `id_product_attribute`      INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `product_name`              VARCHAR(255)     NOT NULL COMMENT 'Snapshot del nombre en el momento del pedido',
    `original_price`            DECIMAL(20,6)    NOT NULL DEFAULT 0 COMMENT 'Precio real del producto antes de poner a 0',
    `rule_name`                 VARCHAR(128)     NOT NULL COMMENT 'Snapshot del nombre de la regla',
    `date_add`                  DATETIME         NOT NULL,
    PRIMARY KEY (`id_josra_gift_order_log`),
    KEY `idx_order`  (`id_order`),
    KEY `idx_rule`   (`id_josra_gift_rule`),
    KEY `idx_product`(`id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
