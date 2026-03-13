<?php
// ============================================================
// cli/run_billing.php — Monthly billing runner
// Run on the 1st of each month via cron or MySQL Event Scheduler.
//
// MAMP cron example (runs at 00:00 on the 1st):
//   0 0 1 * * /Applications/MAMP/bin/php/php8.x/bin/php /path/to/src/cli/run_billing.php
//
// Manual run: php src/cli/run_billing.php [YYYY-MM-01]
// ============================================================

// Block web access — CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/billing_helper.php';

// Allow overriding the billing month for testing: php run_billing.php 2026-02-01
$billingMonth = $argv[1] ?? date('Y-m-01');

// Validate format
if (!preg_match('/^\d{4}-\d{2}-01$/', $billingMonth)) {
    fwrite(STDERR, "Error: billing month must be YYYY-MM-01 format.\n");
    exit(1);
}

echo "ElderShield billing runner\n";
echo "Billing month: {$billingMonth}\n";
echo str_repeat('-', 40) . "\n";

$result = generateMonthlyInvoices($billingMonth);

if (isset($result['error'])) {
    fwrite(STDERR, "Error: {$result['error']}\n");
    exit(1);
}

echo "Invoices created : {$result['created']}\n";
echo "Skipped (no links or already billed): {$result['skipped']}\n";
echo "Done.\n";
exit(0);
