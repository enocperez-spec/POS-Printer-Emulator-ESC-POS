<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/communications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function upe_migration_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    upe_migration_response(['error' => 'Not found.'], 404);
}
if (!communication_service_authorized()) {
    usleep(250000);
    upe_migration_response(['error' => 'Authentication failed.'], 401);
}

$items = [
    ['UPE-123', 'Device and Listener Health Dashboard', 1123,
        'Give customers one clear view of the health of every registered computer and printer listener.',
        'Show computer online status, installed version, listener names and ports, last successful print job, recent connection warnings, and Maintenance and Support eligibility.',
        'This provides the highest immediate customer and support value by exposing operational health without opening the desktop application.',
        'Customers can identify offline, outdated, or faulted computers and listeners from accurate, privacy-safe portal data.'],
    ['UPE-124', 'Guided Setup Wizard', 1124,
        'Guide customers from account login to a verified working POS connection.',
        'Select a POS or generic ESC/POS profile, choose TCP/IP settings, test connectivity, send a test receipt, confirm rendering, and download a configuration summary.',
        'Guided setup reduces configuration mistakes and shortens time to the first successful receipt.',
        'A customer can complete the supported setup flow and verify a receipt without guessing listener or port settings.'],
    ['UPE-125', 'Diagnostic Package and Support Integration', 1125,
        'Let customers attach privacy-reviewed diagnostic evidence directly to a support request.',
        'Collect application version, listener configuration, operating-system details, and relevant errors while excluding receipt contents, full activation keys, credentials, and unnecessary personal data.',
        'Structured diagnostic packages reduce support time while preserving customer privacy.',
        'Customers can preview and submit a redacted diagnostic package, and support staff can retrieve it only through authorized workflows.'],
    ['UPE-126', 'Active Sessions and Security History', 1126,
        'Give customers visibility and control over Customer Portal access.',
        'List active and recent sessions with browser, approximate location, IP-derived security context, login time, idle time, and security events; allow remote sign-out without exposing session secrets.',
        'Session visibility and revocation strengthen account security and help customers recognize unfamiliar access.',
        'Customers can review account activity and terminate other sessions, with every security action recorded and notified appropriately.'],
    ['UPE-127', 'License Transfer Wizard', 1127,
        'Provide a controlled self-service process for moving a license to another computer.',
        'Select the old computer, verify identity and eligibility, deactivate the installation, confirm the transfer, show installation instructions, and record the complete transfer history.',
        'A guided transfer reduces manual support work without weakening activation limits.',
        'Eligible customers can transfer a license once under enforced limits, while duplicate, unauthorized, or replayed transfers are rejected and audited.'],
    ['UPE-128', 'Enterprise Team Management', 1128,
        'Allow Enterprise organizations to share portal responsibilities safely.',
        'Invite and remove users; assign Owner, Administrator, Technician, Billing, and Read Only roles; enforce least privilege, MFA policy, and organization-scoped audit history.',
        'Enterprise customers need delegated access without sharing one account or exposing unrelated controls.',
        'Each role can perform only its authorized actions, ownership transfers are protected, and organization access is fully auditable.'],
    ['UPE-129', 'Customer Notification Center', 1129,
        'Centralize important customer notices inside the portal.',
        'Display software updates, maintenance reminders, security alerts, purchase confirmations, support responses, and license changes with read state, priority, expiration, and destination links.',
        'An in-portal inbox reduces dependence on email delivery and keeps actionable notices discoverable.',
        'Customers receive each eligible notification once, can mark it read, and can open the correct secure destination without exposing sensitive content.'],
    ['UPE-130', 'Release and Update Center', 1130,
        'Turn Downloads into a complete entitlement-aware software release center.',
        'Show release notes, release date, installed and latest versions, known issues, eligible previous versions, Maintenance and Support eligibility, and trusted download or renewal actions.',
        'A consistent update center helps customers understand what they can install and why.',
        'The portal offers only entitled, integrity-verified installers and accurately explains current, available, behind, and renewal-required states.'],
    ['UPE-131', 'Purchase and Billing History', 1131,
        'Give customers a complete financial record of their product ownership.',
        'Show purchases, upgrades, maintenance renewals, payment status, license association, downloadable receipts, and transaction references without exposing sensitive payment credentials.',
        'Clear billing history reduces purchase confusion and supports customer recordkeeping.',
        'Customers can reconcile every completed or pending transaction with the correct license and download a consistent receipt.'],
    ['UPE-132', 'Portal Activity Timeline', 1132,
        'Present important account, license, device, purchase, support, and security events in one chronological view.',
        'Provide filterable events for activations, transfers, downloads, purchases, renewals, support requests, password and MFA changes, and administrative actions visible to the customer.',
        'A unified timeline makes account changes understandable and improves troubleshooting and trust.',
        'Customers can filter and review accurate, privacy-safe events while protected secrets and internal-only administrative details remain hidden.'],
];

try {
    $pdo = database();
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        "INSERT INTO development_roadmap
            (item_key,version_label,item_type,title,status,priority_rank,purpose,planned_scope,priority_reason,completion_criteria,completed_at)
         VALUES
            (:item_key,NULL,'Backlog',:title,'Planned',:priority_rank,:purpose,:planned_scope,:priority_reason,:completion_criteria,NULL)
         ON DUPLICATE KEY UPDATE
            item_type='Backlog',title=VALUES(title),priority_rank=VALUES(priority_rank),
            purpose=VALUES(purpose),planned_scope=VALUES(planned_scope),
            priority_reason=VALUES(priority_reason),completion_criteria=VALUES(completion_criteria)"
    );
    foreach ($items as [$key, $title, $priority, $purpose, $scope, $reason, $complete]) {
        $statement->execute([
            'item_key' => $key,
            'title' => $title,
            'priority_rank' => $priority,
            'purpose' => $purpose,
            'planned_scope' => $scope,
            'priority_reason' => 'User Portal Enhancement. ' . $reason,
            'completion_criteria' => $complete,
        ]);
    }
    $pdo->exec(
        "UPDATE development_roadmap
         SET status='Released',completed_at=COALESCE(completed_at,UTC_TIMESTAMP(6))
         WHERE item_key='UPE-131'"
    );
    $pdo->commit();
    $status = $pdo->query(
        "SELECT status FROM development_roadmap WHERE item_key='UPE-131' LIMIT 1"
    )->fetchColumn();
    upe_migration_response([
        'ok' => true,
        'migration' => 'user-portal-enhancements-upe-123-through-upe-132',
        'processed' => count($items),
        'upe131Status' => $status,
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('User Portal enhancement roadmap migration failed: ' . get_class($exception));
    upe_migration_response(['error' => 'The roadmap migration could not be completed.'], 500);
}
