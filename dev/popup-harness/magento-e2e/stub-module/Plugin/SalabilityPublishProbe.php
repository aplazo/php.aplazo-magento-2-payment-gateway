<?php

namespace LocalDev\AplazoStub\Plugin;

use LocalDev\AplazoStub\Service\StubLog;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherPool;

/**
 * LOCAL TEST ONLY (BNPL-1664, MSI salability message). Around Magento's PublisherPool, for the topic
 * inventory.reservations.updateSalabilityStatus only:
 *  - records the default connection's transaction level at the moment the message reaches the queue publisher, the SKUs
 *    and stock, and the newest reservation of the kit product APLAZO-1664-MSI as seen from an INDEPENDENT database
 *    session (so only committed rows are visible);
 *  - with var/localdev_pf_msi_publish_fault = exception | error, throws a RuntimeException or an \Error there, once
 *    (the file is removed): a synthetic publication failure. No message is changed.
 * Credentials are read from env.php and never written anywhere.
 */
class SalabilityPublishProbe
{
    private const TOPIC = 'inventory.reservations.updateSalabilityStatus';

    public function __construct(private ResourceConnection $resource, private DeploymentConfig $deploymentConfig)
    {
    }

    public function aroundPublish(PublisherPool $subject, callable $proceed, $topicName, $data)
    {
        if ($topicName !== self::TOPIC) {
            return $proceed($topicName, $data);
        }
        $file = BP . '/var/localdev_pf_msi_publish_fault';
        $fault = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        StubLog::write('probe', 'salability_publish', [
            'transaction_level' => $this->resource->getConnection()->getTransactionLevel(),
            'skus' => is_object($data) && method_exists($data, 'getSkus') ? $data->getSkus() : null,
            'stock' => is_object($data) && method_exists($data, 'getStock') ? $data->getStock() : null,
            'committed_newest_msi_reservation' => $this->newestCommittedReservation(),
            'fault' => $fault ?: null,
        ]);
        if ($fault !== '' && @unlink($file)) { // nosemgrep: php.lang.security.unlink-use.unlink-use -- local test kit (dev/ is export-ignore): fixed switch file under var/
            if ($fault === 'error') {
                throw new \Error('kit: synthetic Error while publishing the salability message');
            }
            throw new \RuntimeException('kit: synthetic salability publication failure');
        }

        return $proceed($topicName, $data);
    }

    private function newestCommittedReservation(): ?string
    {
        try {
            $db = $this->deploymentConfig->get('db/connection/default');
            $pdo = new \PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'], $db['username'], $db['password']);
            $row = $pdo->query("SELECT CONCAT(reservation_id, ' ', JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.object_increment_id'))) "
                . "FROM inventory_reservation WHERE sku = 'APLAZO-1664-MSI' ORDER BY reservation_id DESC LIMIT 1")->fetchColumn();

            return $row === false ? null : (string)$row;
        } catch (\Throwable $e) {
            return 'unavailable:' . get_class($e);
        }
    }
}
