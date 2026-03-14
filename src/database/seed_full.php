<?php
// ============================================================
// database/seed_full.php
// Comprehensive seed script — populates all tables with ~20 rows
// Run ONCE:  php seed_full.php   (or visit in browser)
// Requires: eldershield.sql + subscription_migration.sql + billing_migration.sql
// ============================================================

require_once __DIR__ . '/../config/db.php';

$db = getDB();
$pw = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);

echo "<pre>\n";
echo "========================================\n";
echo " ElderShield — Full Database Seed\n";
echo "========================================\n\n";

// ── 1. USERS (20 rows: 2 admins, 6 caregivers, 12 elders) ────
$users = [
    ['Admin User',          'admin@eldershield.com',     'admin'],
    ['System Admin',        'sysadmin@eldershield.com',  'admin'],
    ['Sarah Johnson',       'sarah@example.com',         'caregiver'],
    ['Michael Chen',        'michael.chen@example.com',  'caregiver'],
    ['Lisa Patel',          'lisa.patel@example.com',    'caregiver'],
    ['James Wilson',        'james.wilson@example.com',  'caregiver'],
    ['Maria Garcia',        'maria.garcia@example.com',  'caregiver'],
    ['Robert Kim',          'robert.kim@example.com',    'caregiver'],
    ['Dorothy Johnson',     'dorothy@example.com',       'elder'],
    ['Harold Williams',     'harold@example.com',        'elder'],
    ['Betty Davis',         'betty@example.com',         'elder'],
    ['Walter Brown',        'walter@example.com',        'elder'],
    ['Margaret Miller',     'margaret@example.com',      'elder'],
    ['Arthur Anderson',     'arthur@example.com',        'elder'],
    ['Helen Thomas',        'helen@example.com',         'elder'],
    ['George Jackson',      'george@example.com',        'elder'],
    ['Frances White',       'frances@example.com',       'elder'],
    ['Earl Harris',         'earl@example.com',          'elder'],
    ['Ruth Martin',         'ruth@example.com',          'elder'],
    ['Frank Robinson',      'frank@example.com',         'elder'],
];

$userIds = [];
foreach ($users as [$name, $email, $role]) {
    $stmt = $db->prepare(
        'INSERT INTO users (full_name, email, password_hash, role)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), full_name=VALUES(full_name)'
    );
    $stmt->execute([$name, $email, $pw, $role]);
    $id = $db->lastInsertId();
    if (!$id) {
        $s = $db->prepare('SELECT user_id FROM users WHERE email=?');
        $s->execute([$email]);
        $id = $s->fetchColumn();
    }
    $userIds[$email] = (int)$id;
    echo "User: $email ($role) -> ID $id\n";
}

// ── 2. ACCOUNT LINKS (12 rows) ───────────────────────────────
$links = [
    ['dorothy@example.com',  'sarah@example.com',       'family',    'active'],
    ['harold@example.com',   'sarah@example.com',       'family',    'active'],
    ['betty@example.com',    'michael.chen@example.com', 'family',   'active'],
    ['walter@example.com',   'michael.chen@example.com', 'family',   'active'],
    ['margaret@example.com', 'lisa.patel@example.com',   'caregiver','active'],
    ['arthur@example.com',   'lisa.patel@example.com',   'family',   'active'],
    ['helen@example.com',    'james.wilson@example.com', 'caregiver','active'],
    ['george@example.com',   'james.wilson@example.com', 'family',   'active'],
    ['frances@example.com',  'maria.garcia@example.com', 'caregiver','active'],
    ['earl@example.com',     'maria.garcia@example.com', 'family',   'active'],
    ['ruth@example.com',     'robert.kim@example.com',   'caregiver','active'],
    ['frank@example.com',    'robert.kim@example.com',   'family',   'pending'],
];

foreach ($links as [$elderEmail, $cgEmail, $relType, $status]) {
    $eid = $userIds[$elderEmail] ?? 0;
    $cid = $userIds[$cgEmail] ?? 0;
    if (!$eid || !$cid) continue;
    try {
        $linkedAt = ($status === 'active') ? date('Y-m-d H:i:s', strtotime('-' . rand(10,90) . ' days')) : null;
        $db->prepare(
            'INSERT INTO account_links (elder_user_id, caregiver_user_id, relationship_type, status, linked_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status=VALUES(status)'
        )->execute([$eid, $cid, $relType, $status, $linkedAt]);
        echo "Link: $elderEmail -> $cgEmail ($status)\n";
    } catch (PDOException $e) {
        echo "Link skip (dup): $elderEmail -> $cgEmail\n";
    }
}

// ── 3. INCIDENTS (20 rows) ────────────────────────────────────
$scamTexts = [
    'I received a call from someone claiming to be from the IRS saying I owe back taxes and need to pay immediately with gift cards or face arrest.',
    'Got an email from "Microsoft Support" saying my computer has a virus. They want remote access to fix it and are charging $299 for the service.',
    'Someone on Facebook messaged me saying they are a US Army general stationed overseas and need money to come visit me. We have been talking for 3 weeks.',
    'I got a text saying I won a $5,000 Walmart gift card and need to click a link and enter my social security number to claim it.',
    'A person called saying they are my grandson and got arrested. They need $2,000 in bail money wired to them right away.',
    'Received an email from my bank asking me to verify my account by clicking a link. The email looks official but the link goes to a strange website.',
    'Someone called offering a free medical alert device but needs my Medicare number and credit card for shipping.',
    'Got a popup on my computer saying it is infected and to call a number. The man on the phone wants $500 to fix it.',
    'A charity called asking for donations for veterans. They were very pushy and wanted my credit card number right away.',
    'Someone emailed me about an inheritance from a distant relative in Nigeria. I just need to send $500 processing fee.',
    'I received a call from Social Security saying my number has been compromised and I need to verify my identity immediately.',
    'A man at my door said he was from the electric company and my bill was overdue. He wanted cash payment on the spot.',
    'Got a text from what looks like Amazon saying my order could not be delivered and I need to update payment information.',
    'Someone called about my car warranty expiring and offered to extend it for a special one-time price of $1200.',
    'An email from a prince offering me 10 million dollars if I help him transfer money out of his country. He needs my bank details.',
    'A woman called saying she was from a local hospital and I had an unpaid bill that would go to collections today unless I paid by phone.',
    'I got a letter saying I won the Publishers Clearing House sweepstakes but need to pay taxes upfront to receive my prize.',
    'Someone on a dating site says they love me and wants to meet but first needs me to send money for a plane ticket.',
    'A tech company called saying they detected unusual activity on my internet and need to install security software remotely for $150.',
    'Got a voicemail from the county sheriff saying there is a warrant for my arrest for missing jury duty. I need to pay a fine with gift cards.',
];

$categories = ['phishing','impersonation','romance_scam','tech_support','lottery_prize','grandparent_scam','investment_fraud','other','phishing','investment_fraud',
               'impersonation','impersonation','phishing','other','investment_fraud','impersonation','lottery_prize','romance_scam','tech_support','impersonation'];

$elderEmails = ['dorothy@example.com','harold@example.com','betty@example.com','walter@example.com',
                'margaret@example.com','arthur@example.com','helen@example.com','george@example.com',
                'frances@example.com','earl@example.com','ruth@example.com','frank@example.com'];

$incidentIds = [];
for ($i = 0; $i < 20; $i++) {
    $elderEmail = $elderEmails[$i % count($elderEmails)];
    $uid = $userIds[$elderEmail];
    $daysAgo = rand(1, 60);
    $submittedAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
    $status = ['pending','analyzed','reviewed','dismissed'][rand(0,3)];
    if ($i < 16) $status = 'analyzed'; // most should be analyzed for demo

    $stmt = $db->prepare(
        'INSERT INTO incidents (user_id, content, status, submitted_at)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$uid, $scamTexts[$i], $status, $submittedAt]);
    $incidentIds[] = (int)$db->lastInsertId();
    echo "Incident #" . end($incidentIds) . " ($elderEmail)\n";
}

// ── 4. ANALYSIS (20 rows — one per incident) ──────────────────
$tactics = [
    ['urgency','authority_impersonation','fear'],
    ['tech_jargon','urgency','financial_pressure'],
    ['emotional_manipulation','isolation','trust_building'],
    ['too_good_to_be_true','urgency','personal_info_request'],
    ['urgency','emotional_manipulation','secrecy'],
    ['brand_impersonation','urgency','phishing_link'],
    ['authority_impersonation','personal_info_request'],
    ['fear','urgency','tech_jargon'],
    ['emotional_manipulation','urgency','financial_pressure'],
    ['too_good_to_be_true','financial_pressure','advance_fee'],
    ['authority_impersonation','fear','urgency'],
    ['authority_impersonation','in_person_pressure'],
    ['brand_impersonation','phishing_link','urgency'],
    ['urgency','financial_pressure'],
    ['too_good_to_be_true','advance_fee','authority_impersonation'],
    ['authority_impersonation','urgency','financial_pressure'],
    ['too_good_to_be_true','advance_fee','urgency'],
    ['emotional_manipulation','financial_pressure','isolation'],
    ['tech_jargon','urgency','financial_pressure'],
    ['authority_impersonation','fear','urgency'],
];

for ($i = 0; $i < 20; $i++) {
    $prob = rand(20, 98);
    if ($i % 5 === 0) $prob = rand(10, 35); // some low risk
    $explanation = 'This message shows signs of a ' . str_replace('_', ' ', $categories[$i]) . ' scam. ';
    $explanation .= 'The sender is using common manipulation tactics to pressure you into action.';
    $action = 'Do not respond to this message. Do not share personal information. '
            . 'Report it to your caregiver and consider contacting local authorities.';

    $db->prepare(
        'INSERT INTO analysis
            (incident_id, scam_probability, scam_category, manipulation_tactics,
             explanation_simple, recommended_action, ai_raw_response)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $incidentIds[$i], $prob, $categories[$i],
        json_encode($tactics[$i]), $explanation, $action,
        json_encode(['seeded' => true, 'probability' => $prob])
    ]);
    echo "Analysis for incident #" . $incidentIds[$i] . " (" . $prob . "% " . $categories[$i] . ")\n";
}

// ── 5. NOTIFICATIONS (22 rows) ────────────────────────────────
$notifTypes = ['high_risk','medium_risk','info','admin_action'];
for ($i = 0; $i < 22; $i++) {
    $recipientEmail = array_values($userIds);
    $recipientId = $recipientEmail[array_rand($recipientEmail)];
    // Use a valid incident_id if available
    $incId = ($i < count($incidentIds)) ? $incidentIds[$i % count($incidentIds)] : null;
    $type  = $notifTypes[$i % count($notifTypes)];
    $msg   = match($type) {
        'high_risk'    => 'HIGH RISK scam report detected (' . rand(70,98) . '% probability). Please review immediately.',
        'medium_risk'  => 'Medium risk scam report submitted (' . rand(40,69) . '% probability). Please review.',
        'admin_action' => 'System maintenance completed. All services are operating normally.',
        default        => 'A new scam report has been submitted for review.',
    };
    $isRead = rand(0,1);
    $daysAgo = rand(0,30);

    $db->prepare(
        'INSERT INTO notifications
            (incident_id, recipient_user_id, message_text, notification_type, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$incId, $recipientId, $msg, $type, $isRead, date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"))]);
}
echo "Notifications: 22 rows inserted\n";

// ── 6. SUBSCRIPTION PLANS (ensure they exist) ─────────────────
$db->exec(
    "INSERT IGNORE INTO subscription_plans (name, price, max_incidents_per_month, notifications_enabled)
     VALUES ('free', 0.00, 5, 0), ('premium', 9.99, -1, 1)"
);
echo "Subscription plans: ensured free + premium exist\n";

// ── 7. SUBSCRIPTIONS (12 rows — one per elder) ───────────────
$planFree = $db->query("SELECT plan_id FROM subscription_plans WHERE name='free'")->fetchColumn();
$planPrem = $db->query("SELECT plan_id FROM subscription_plans WHERE name='premium'")->fetchColumn();

foreach ($elderEmails as $idx => $email) {
    $uid  = $userIds[$email];
    $plan = ($idx < 4) ? $planPrem : $planFree; // first 4 elders are premium
    $exp  = ($plan == $planPrem) ? date('Y-m-d H:i:s', strtotime('+30 days')) : null;
    try {
        $db->prepare(
            'INSERT INTO subscriptions (user_id, plan_id, status, started_at, expires_at)
             VALUES (?, ?, "active", NOW(), ?)
             ON DUPLICATE KEY UPDATE plan_id=VALUES(plan_id), status="active"'
        )->execute([$uid, $plan, $exp]);
        echo "Subscription: $email -> " . ($plan == $planPrem ? 'premium' : 'free') . "\n";
    } catch (PDOException $e) {
        echo "Subscription skip (dup): $email\n";
    }
}

// ── 8. INVOICES + LINE ITEMS (8 invoices, ~20 line items) ─────
$caregiverEmails = ['sarah@example.com','michael.chen@example.com','lisa.patel@example.com',
                    'james.wilson@example.com','maria.garcia@example.com','robert.kim@example.com'];

$invoiceCount = 0;
$lineCount    = 0;
$billingMonths = [
    date('Y-m-01', strtotime('-2 months')),
    date('Y-m-01', strtotime('-1 month')),
];

foreach ($billingMonths as $month) {
    $daysInMonth = (int)date('t', strtotime($month));
    foreach ($caregiverEmails as $cgEmail) {
        $cgId = $userIds[$cgEmail] ?? 0;
        if (!$cgId) continue;

        // Find this caregiver's active elders
        $elderStmt = $db->prepare(
            'SELECT al.elder_user_id, u.full_name
             FROM account_links al JOIN users u ON al.elder_user_id = u.user_id
             WHERE al.caregiver_user_id = ? AND al.status = "active"'
        );
        $elderStmt->execute([$cgId]);
        $elders = $elderStmt->fetchAll();
        if (empty($elders)) continue;

        $totalCents = count($elders) * 99;
        $status = (rand(1,100) <= 90) ? 'paid' : 'failed';
        $paidAt = ($status === 'paid') ? date('Y-m-d H:i:s', strtotime($month . ' +1 day')) : null;

        try {
            $db->prepare(
                'INSERT INTO invoices (caregiver_id, billing_month, elder_count, amount_cents, status, payment_method, paid_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE invoice_id=invoice_id'
            )->execute([$cgId, $month, count($elders), $totalCents, $status,
                        ($status === 'paid') ? 'Demo Card ••••4242' : null, $paidAt]);

            $invoiceId = (int)$db->lastInsertId();
            if ($invoiceId) {
                $invoiceCount++;
                foreach ($elders as $elder) {
                    $db->prepare(
                        'INSERT INTO invoice_line_items (invoice_id, elder_id, elder_name, days_active, days_in_month, amount_cents)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    )->execute([$invoiceId, $elder['elder_user_id'], $elder['full_name'], $daysInMonth, $daysInMonth, 99]);
                    $lineCount++;
                }
            }
        } catch (PDOException $e) {
            // skip duplicates
        }
    }
}
echo "Invoices: $invoiceCount created, $lineCount line items\n";

echo "\n========================================\n";
echo " Seed complete! All tables populated.\n";
echo " Login: admin@eldershield.com / password123\n";
echo " Login: dorothy@example.com  / password123\n";
echo " Login: sarah@example.com    / password123\n";
echo "========================================\n";
echo "</pre>\n";
