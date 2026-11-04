<?php
// LOCAL TEST ONLY (BNPL-1664). Checks PlacedOrders and PopupCheckout's order authorization with in-memory fakes:
// no database writes, no orders, no network. Run from the Magento root with this module mounted:
//   bin/clinotty php < dev/popup-harness/magento-e2e/session_orders_check.php
require 'app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

use Aplazo\AplazoPayment\Model\PlacedOrders;
use Aplazo\AplazoPayment\Model\PopupCheckout;

function fakeSession(): \Magento\Checkout\Model\Session
{
    return new class extends \Magento\Checkout\Model\Session {
        private array $values = [];
        public function __construct() {}
        public function getData($key = '', $index = null) { return $this->values[$key] ?? null; }
        public function setData($key, $value = null) { $this->values[$key] = $value; return $this; }
    };
}

function fakeOrder($om, $entityId, string $incrementId, int $storeId, string $method = 'aplazo_gateway'): \Magento\Sales\Model\Order
{
    $payment = $om->create(\Magento\Sales\Model\Order\Payment::class)->setMethod($method);
    $order = $om->create(\Magento\Sales\Model\Order::class);
    $order->setData(['entity_id' => $entityId, 'increment_id' => $incrementId, 'store_id' => $storeId, 'state' => 'new']);
    $order->setPayment($payment);
    return $order;
}

function popupCheckout($om, PlacedOrders $placed, array $orders, int $currentStoreId): PopupCheckout
{
    $repository = new class($orders) extends \Magento\Sales\Model\OrderRepository {
        private array $orders;
        public function __construct(array $orders) { $this->orders = $orders; }
        public function get($id)
        {
            if (!isset($this->orders[$id])) {
                throw new \Magento\Framework\Exception\NoSuchEntityException(__('missing'));
            }
            return $this->orders[$id];
        }
    };
    $stores = new class($currentStoreId) extends \Magento\Store\Model\StoreManager {
        private int $id;
        public function __construct(int $id) { $this->id = $id; }
        public function getStore($storeId = null) { return new \Magento\Framework\DataObject(['id' => $this->id]); }
    };
    return $om->create(PopupCheckout::class, ['placedOrders' => $placed, 'orderRepository' => $repository, 'storeManager' => $stores]);
}

$results = [];
$check = function (string $name, bool $ok) use (&$results) { $results[] = [$ok ? 'PASS' : 'FAIL', $name]; };

// Same increment id in two stores, different entity ids.
$store1 = fakeOrder($om, '101', '000000101', 1);
$store2 = fakeOrder($om, '202', '000000101', 2);
$session = fakeSession();
$placed = new PlacedOrders($session);
$placed->remember($store1);
$check('session order accepted (entity id)', $placed->contains($store1));
$check('other store, same increment id, rejected', !$placed->contains($store2));

// Malformed ids never normalize.
foreach (['', '0', '-1', '0101', '+101', '101 ', ' 101', '101abc', '1e3', '10.0', '000000101', '9999999999999999999', null, 101.0, true, [], '١٠١'] as $bad) {
    $check('malformed id rejected: ' . json_encode($bad), PlacedOrders::normalizeId($bad) === null);
}
$check('valid id normalized', PlacedOrders::normalizeId('101') === 101 && PlacedOrders::normalizeId(101) === 101);

// Old or tampered session data is never read as an entity id.
$legacy = fakeSession();
$legacy->setData('aplazo_placed_order_increment_ids', ['000000101', '101']);
$legacy->setData('aplazo_popup_placed_order_entity_ids', ['101', '202']);
$check('increment ids under the old key and string ids are ignored', !(new PlacedOrders($legacy))->contains($store1));

// Guests and tabs: one session holds both orders; another session holds neither.
$tabA = fakeOrder($om, '301', '000000301', 1);
$tabB = fakeOrder($om, '302', '000000302', 1);
$placed->remember($tabA);
$placed->remember($tabB);
$check('two tabs of one session: both orders known', $placed->contains($tabA) && $placed->contains($tabB));
$check('another session (another guest) knows neither', !(new PlacedOrders(fakeSession()))->contains($tabA));

// Purchase: only the session's last order, named exactly.
$pc = popupCheckout($om, $placed, ['101' => $store1, '202' => $store2, '301' => $tabA, '302' => $tabB], 1);
$check('purchase: last order accepted', $pc->isPurchaseTarget($tabB, '302'));
$check('purchase: older session order rejected', !$pc->isPurchaseTarget($tabB, '301'));
$check('purchase: malformed id rejected', !$pc->isPurchaseTarget($tabB, '0302'));
$check('purchase: no last order rejected', !$pc->isPurchaseTarget($om->create(\Magento\Sales\Model\Order::class), '302'));
$check('purchase: last order not placed in this session rejected',
    !popupCheckout($om, new PlacedOrders(fakeSession()), ['302' => $tabB], 1)->isPurchaseTarget($tabB, '302'));

// Showing status (popup_return): any order of the session in the current store; others rejected.
$check('status: older session order shown', $pc->getSessionOrder('301') !== null);
$check('status: other store order rejected', $pc->getSessionOrder('202') === null);
$placed->remember($store2);
$check('status: other store order rejected even if remembered', $pc->getSessionOrder('202') === null);
$check('status: malformed id rejected', $pc->getSessionOrder('0301') === null);
$check('status: unknown id rejected', $pc->getSessionOrder('999') === null);
$other = fakeOrder($om, '401', '000000401', 1, 'checkmo');
$placed->remember($other);
$check('status: non-Aplazo order rejected', popupCheckout($om, $placed, ['401' => $other], 1)->getSessionOrder('401') === null);

// The list keeps the last ten orders.
$bounded = new PlacedOrders(fakeSession());
for ($i = 1; $i <= 11; $i++) {
    $bounded->remember(fakeOrder($om, (string)(500 + $i), sprintf('%09d', 500 + $i), 1));
}
$check('history bounded to ten orders', !$bounded->contains(fakeOrder($om, '501', '000000501', 1))
    && $bounded->contains(fakeOrder($om, '511', '000000511', 1)));

foreach ($results as [$status, $name]) {
    echo $status, '  ', $name, PHP_EOL;
}
$failed = count(array_filter($results, function ($r) { return $r[0] === 'FAIL'; }));
echo count($results), ' checks, ', $failed, ' failed', PHP_EOL;
exit($failed ? 1 : 0);
