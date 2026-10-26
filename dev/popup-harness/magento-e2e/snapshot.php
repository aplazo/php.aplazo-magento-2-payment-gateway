<?php
// Read-only snapshot for the BNPL-1607/1664 local tests. Usage: php -- <sku> [increment_id ...] < demo_snapshot.php
require 'app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$db = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$sku = $argv[1] ?? 'APLAZO-TEST-001';
$out = [
    'sku' => $sku,
    'physical_source_qty' => (float)$db->fetchOne("SELECT quantity FROM inventory_source_item WHERE sku = ? AND source_code = 'default'", [$sku]),
    'stock_index_qty' => (float)$db->fetchOne('SELECT quantity FROM inventory_stock_1 WHERE sku = ?', [$sku]),
    'reservations_sum_all_orders' => (float)$db->fetchOne('SELECT COALESCE(SUM(quantity), 0) FROM inventory_reservation WHERE sku = ?', [$sku]),
    'salable_qty' => (float)$om->get(\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class)->execute($sku, 1),
    'orders' => [],
];
foreach (array_slice($argv, 2) as $inc) {
    $o = $db->fetchRow(
        "SELECT o.entity_id, o.state, o.status, JSON_UNQUOTE(JSON_EXTRACT(p.additional_information, '$.aplazo_stock_compensation')) AS marker
         FROM sales_order o JOIN sales_order_payment p ON p.parent_id = o.entity_id WHERE o.increment_id = ?", [$inc]);
    $res = $db->fetchCol(
        "SELECT CONCAT(quantity, ' ', JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.event_type'))) FROM inventory_reservation
         WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.object_increment_id')) = ? ORDER BY reservation_id", [$inc]);
    $out['orders'][$inc] = $o ? $o + ['reservations' => $res, 'reservations_net' => array_sum(array_map('floatval', $res))] : 'not found';
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
