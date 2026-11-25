<?php

namespace LocalDev\AplazoStub\Plugin;

/**
 * LOCAL TEST ONLY (BNPL-1664). Keeps the payment-first reconciliation cron on the attempts of a test run: while
 * var/localdev_pf_cron_allowlist exists, Reconciler::run() without ids (the cron) only processes the attempt ids listed
 * in it (one per line). Without the file nothing changes; calls with explicit ids are not touched.
 */
class ReconcileAllowlist
{
    public function aroundRun($subject, callable $proceed, ?array $onlyIds = null)
    {
        $file = BP . '/var/localdev_pf_cron_allowlist';
        if ($onlyIds !== null || !is_file($file)) {
            return $proceed($onlyIds);
        }
        $ids = array_values(array_filter(array_map('intval', file($file, FILE_IGNORE_NEW_LINES) ?: [])));

        return $proceed($ids ?: [0]);
    }
}
