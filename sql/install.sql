-- josra_giftproduct - tablas
-- PREFIX_ y ENGINE_TYPE se sustituyen al instalar.
-- Sin ENUM ni "IF NOT EXISTS" en columnas: válido en MySQL y MariaDB.

CREATE TABLE IF NOT EXISTS `PREFIX_josra_gift_rule` (
    `id_josra_gift_rule`  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`                VARCHAR(128)     NOT NULL,
    `active`              TINYINT(1)       NOT NULL DEFAULT 1,
    `trigger_type`        VARCHAR(16)      NOT NULL DEFAULT 'amount',
    `id_trigger_product`  INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `trigger_min_qty`     INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `calc_mode`           VARCHAR(16)      NOT NULL DEFAULT 'with_tax',
    `include_shipping`    TINYINT(1)       NOT NULL DEFAULT 0,
    `date_start`          DATE             NULL DEFAULT NULL,
    `date_end`            DATE             NULL DEFAULT NULL,
    `max_uses`            INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `uses_count`          INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `priority`            INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `date_add`            DATETIME         NOT NULL,
    `date_upd`            DATETIME         NOT NULL,
    PRIMARY KEY (`id_josra_gift_rule`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_josra_gift_rule_level` (
    `id_josra_gift_rule_level` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_josra_gift_rule`       INT(10) UNSIGNED NOT NULL,
    `trigger_value`            DECIMAL(20,6)    NOT NULL DEFAULT 0,
    `id_product`               INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `id_product_attribute`     INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `gift_qty`                 INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `sort_order`               INT(10) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id_josra_gift_rule_level`),
    KEY `idx_rule` (`id_josra_gift_rule`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_josra_gift_rule_restriction` (
    `id_josra_gift_rule_restriction` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_josra_gift_rule`             INT(10) UNSIGNED NOT NULL,
    `restriction_type`               VARCHAR(16)      NOT NULL,
    `id_value`                       INT(10) UNSIGNED NOT NULL,
    PRIMARY KEY (`id_josra_gift_rule_restriction`),
    KEY `idx_rule_type` (`id_josra_gift_rule`, `restriction_type`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_josra_gift_cart` (
    `id_cart`                  INT(10) UNSIGNED NOT NULL,
    `id_josra_gift_rule`       INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `id_josra_gift_rule_level` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `id_product`               INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `id_product_attribute`     INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `gift_qty`                 INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `in_cart`                  TINYINT(1)       NOT NULL DEFAULT 0,
    `declined`                 TINYINT(1)       NOT NULL DEFAULT 0,
    `id_specific_price`        INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `notice`                   VARCHAR(16)      NOT NULL DEFAULT '',
    `notice_data`              VARCHAR(255)     NOT NULL DEFAULT '',
    `date_upd`                 DATETIME         NOT NULL,
    PRIMARY KEY (`id_cart`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_josra_gift_order_log` (
    `id_josra_gift_order_log`  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_order`                 INT(10) UNSIGNED NOT NULL,
    `id_cart`                  INT(10) UNSIGNED NOT NULL,
    `id_josra_gift_rule`       INT(10) UNSIGNED NOT NULL,
    `id_josra_gift_rule_level` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `id_product`               INT(10) UNSIGNED NOT NULL,
    `id_product_attribute`     INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `gift_qty`                 INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `product_name`             VARCHAR(255)     NOT NULL DEFAULT '',
    `original_price`           DECIMAL(20,6)    NOT NULL DEFAULT 0,
    `rule_name`                VARCHAR(128)     NOT NULL DEFAULT '',
    `status`                   VARCHAR(16)      NOT NULL DEFAULT 'valid',
    `date_add`                 DATETIME         NOT NULL,
    PRIMARY KEY (`id_josra_gift_order_log`),
    KEY `idx_order` (`id_order`),
    KEY `idx_rule` (`id_josra_gift_rule`),
    KEY `idx_product` (`id_product`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=utf8mb4;
