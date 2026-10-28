<?php
// LOCAL TEST ONLY (BNPL-1664, H-3). Invokes the payment-first reconciliation cron class the way the scheduler does
// (crontab area, Cron\ReconcilePaymentAttempts::execute()), and nothing else: no cron:run, no other job. With the kit's
// var/localdev_pf_cron_allowlist it only touches the listed synthetic attempts. This does not validate the scheduler.
//   bin/clinotty php < dev/popup-harness/magento-e2e/cron_job.php
require 'app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode(\Magento\Framework\App\Area::AREA_CRONTAB);
$jobs = $om->get(\Magento\Cron\Model\ConfigInterface::class)->getJobs();
$job = $jobs['default']['aplazo_payment_first_reconcile'] ?? null;
$started = microtime(true);
$error = null;
try {
    $om->get($job['instance'])->{$job['method']}();
} catch (\Throwable $e) {
    $error = get_class($e);
}
echo json_encode(['job_registered' => $job !== null, 'schedule' => $job['schedule'] ?? null, 'instance' => $job['instance'] ?? null,
    'seconds' => round(microtime(true) - $started, 2), 'error' => $error]), PHP_EOL;
