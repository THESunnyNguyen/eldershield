<?php
// cli/run_billing.php — Generate monthly invoices for premium caregivers
// Run on the 1st of each month:
//   php /path/to/eldershield/src/cli/run_billing.php
// Or schedule via Windows Task Scheduler / cron.

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit('CLI only');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/billing_helper.php';

$billingMonth = date('Y-m-01'); // always 1st of current month
$result = generateMonthlyInvoices($billingMonth);

if (isset($result['error'])) {
    echo "ERROR: " . $result['error'] . PHP_EOL;
    exit(1);
}

echo "Billing complete for {$billingMonth}: "
   . "{$result['created']} invoices created, "
   . "{$result['skipped']} skipped." . PHP_EOL;
exit(0);
