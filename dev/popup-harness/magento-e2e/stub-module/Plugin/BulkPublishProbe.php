<?php

namespace LocalDev\AplazoStub\Plugin;

use LocalDev\AplazoStub\Service\StubLog;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\Bulk\Publisher;

/**
 * LOCAL TEST ONLY (BNPL-1664, coupon usage). Around the bulk publisher that BulkManagement::scheduleBulk() calls inside
 * its own transaction, for the topic sales.rule.update.coupon.usage only:
 *  - records the default connection's transaction level at the moment of publishing, and the newest order with a kit
 *    coupon (PFKIT*) as seen from an INDEPENDENT database session (so only committed rows are visible);
 *  - with var/localdev_pf_bulk_publish_fault = exception | error, throws a RuntimeException or an \Error there, once
 *    (the file is removed): a publication failure after scheduleBulk() opened its transaction. Synthetic.
 * No message is changed. Credentials are read from env.php and never written anywhere.
 */
class BulkPublishProbe
{
    private const TOPIC = 'sales.rule.update.coupon.usage';

    public function __construct(private ResourceConnection $resource, private DeploymentConfig $deploymentConfig)
    {
    }

    public function aroundPublish(Publisher $subject, callable $proceed, $topicName, $data)
    {
        if ($topicName !== self::TOPIC) {
            return $proceed($topicName, $data);
        }
        $file = BP . '/var/localdev_pf_bulk_publish_fault';
        $fault = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        $info = [];
        foreach ((array)$data as $operation) {
            $payload = json_decode((string)$operation->getSerializedData(), true) ?: [];
            $info[] = ['increment' => (bool)($payload['is_increment'] ?? false), 'customer_id' => (int)($payload['customer_id'] ?? 0),
                'coupon' => (string)($payload['coupon_code'] ?? '')];
        }
        StubLog::write('probe', 'coupon_bulk_publish', [
            'transaction_level' => $this->resource->getConnection()->getTransactionLevel(),
            'committed_newest_kit_order' => $this->newestCommittedKitOrder(),
            'operations' => $info,
            'fault' => $fault ?: null,
        ]);
        if ($fault !== '' && @unlink($file)) { // nosemgrep: php.lang.security.unlink-use.unlink-use -- local test kit (dev/ is export-ignore): fixed switch file under var/
            if ($fault === 'error') {
                throw new \Error('kit: synthetic Error while publishing');
            }
            throw new \RuntimeException('kit: synthetic publication failure');
        }

        return $proceed($topicName, $data);
    }

    private function newestCommittedKitOrder(): ?string
    {
        try {
            $db = $this->deploymentConfig->get('db/connection/default');
            $pdo = new \PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'], $db['username'], $db['password']);
            $row = $pdo->query("SELECT increment_id FROM sales_order WHERE coupon_code LIKE 'PFKIT%' ORDER BY entity_id DESC LIMIT 1")
                ->fetchColumn();

            return $row === false ? null : (string)$row;
        } catch (\Throwable $e) {
            return 'unavailable:' . get_class($e);
        }
    }
}
