-- josra_giftproduct upgrade v1.0.0 → v1.1.0
ALTER TABLE `PREFIX_josra_gift_rule`
    MODIFY `trigger_type` ENUM('amount','quantity','product') NOT NULL DEFAULT 'amount',
    ADD COLUMN IF NOT EXISTS `id_trigger_product` INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `trigger_type`,
    ADD COLUMN IF NOT EXISTS `trigger_min_qty`    INT(10) UNSIGNED NOT NULL DEFAULT 1 AFTER `id_trigger_product`;

ALTER TABLE `PREFIX_josra_gift_rule_product`
    ADD COLUMN IF NOT EXISTS `gift_qty` INT(10) UNSIGNED NOT NULL DEFAULT 1;
