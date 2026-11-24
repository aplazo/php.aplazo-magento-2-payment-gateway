<?php
// LOCAL TEST ONLY (BNPL-1664, MSI salability message). Read-only state of one synthetic product on the default stock,
// read by a NEW process (so only committed rows are visible). InnoDB transactions and lock waits are read separately
// by the runner (pf_e2e.py innodb_state(), read-only, from the database container: the application user has no PROCESS
// privilege and none is granted). Usage, from the Magento root:
//   bin/clinotty php -- <APLAZO-1664-* sku> [increment_id ...] < dev/popup-harness/magento-e2e/msi_state.php
// Prints one JSON object. No personal data.
require 'app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$db = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$sku = (string)($argv[1] ?? '');
if (strpos($sku, 'APLAZO-1664-') !== 0) {
    fwrite(STDERR, "only APLAZO-1664-* test products\n");
    exit(2);
}
$productId = (int)$db->fetchOne('SELECT entity_id FROM catalog_product_entity WHERE sku = ?', [$sku]);
$out = [
    'sku' => $sku,
    'product_id' => $productId,
    'source_item' => $db->fetchRow("SELECT quantity, status FROM inventory_source_item WHERE sku = ? AND source_code = 'default'", [$sku]),
    'legacy_stock_item' => $db->fetchRow('SELECT qty, is_in_stock FROM cataloginventory_stock_item WHERE product_id = ? AND stock_id = 1', [$productId]),
    // What UpdateIndexSalabilityStatus reads as "current" and writes for the default stock.
    'legacy_stock_status' => $db->fetchRow('SELECT qty, stock_status FROM cataloginventory_stock_status WHERE product_id = ? AND stock_id = 1', [$productId]),
    'stock_index_view' => $db->fetchRow('SELECT quantity, is_salable FROM inventory_stock_1 WHERE sku = ?', [$sku]),
    'reservations' => $db->fetchRow('SELECT COUNT(*) AS count, COALESCE(SUM(quantity), 0) AS sum FROM inventory_reservation WHERE sku = ? AND stock_id = 1', [$sku]),
    'salable_qty' => (float)$om->get(\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class)->execute($sku, 1),
    // What the consumer would compute if it ran now.
    'is_salable_now' => $om->get(\Magento\InventorySalesApi\Api\IsProductSalableInterface::class)->execute($sku, 1),
    'orders' => [],
];
foreach (array_slice($argv, 2) as $inc) {
    $out['orders'][$inc] = $db->fetchRow(
        "SELECT o.entity_id, o.state, o.status,
                (SELECT COUNT(*) FROM sales_invoice i WHERE i.order_id = o.entity_id) AS invoices,
                (SELECT GROUP_CONCAT(CONCAT(r.quantity, ' ', JSON_UNQUOTE(JSON_EXTRACT(r.metadata, '$.event_type'))))
                   FROM inventory_reservation r WHERE JSON_UNQUOTE(JSON_EXTRACT(r.metadata, '$.object_increment_id')) = o.increment_id) AS reservations
         FROM sales_order o WHERE o.increment_id = ?",
        [$inc]
    ) ?: null;
}
echo json_encode($out, JSON_UNESCAPED_SLASHES), PHP_EOL;
