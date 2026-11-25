<?php

namespace LocalDev\AplazoStub\Plugin;

/**
 * LOCAL TEST ONLY (BNPL-1665). Keeps the plugin's cancel cron away from orders that are not part of a test run: while
 * var/localdev_aplazo_cron_allowlist exists, getOrderToCancelCollection() only returns the increment ids listed in it
 * (one per line) and ignores the order age. Without the file nothing changes. The cron's own logic (loan status,
 * approval or cancellation) is not touched.
 */
class CronAllowlist
{
    public function aroundGetOrderToCancelCollection($subject, callable $proceed, $minutes)
    {
        $file = BP . '/var/localdev_aplazo_cron_allowlist';
        if (!is_file($file)) {
            return $proceed($minutes);
        }
        $ids = array_values(array_filter(array_map('trim', file($file, FILE_IGNORE_NEW_LINES) ?: []), function ($id) {
            return preg_match('/^[0-9A-Za-z-]+$/', $id) === 1;
        }));
        $collection = $proceed(0);
        $collection->addFieldToFilter('main_table.increment_id', ['in' => $ids ?: ['-none-']]);

        return $collection;
    }
}
