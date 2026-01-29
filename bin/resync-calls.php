#!/usr/bin/php
<?php
/**
 * Resync calls to AutoCRM using PUT /call/{id}
 * Updates existing calls in CRM (does NOT create duplicates)
 *
 * Usage: php resync-calls.php [--dry-run] [--limit=N]
 *
 * Options:
 *   --dry-run    Show what would be sent without actually sending
 *   --limit=N    Process only N calls (default: all)
 */

require_once('Globals.php');

use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleAutoCRM\Lib\AutoCrmApiClient;
use Modules\ModuleAutoCRM\Lib\AutoCrmApiException;
use Modules\ModuleAutoCRM\Lib\Logger;
use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;
use Modules\ModuleAutoCRM\Models\AutoCrmCalls;
use Modules\ModuleAutoCRM\Models\AutoCrmUsers;

$scriptName = 'resync-calls';

// Parse arguments
$dryRun = in_array('--dry-run', $argv);
$limit = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    }
}

if ($dryRun) {
    echo "DRY-RUN MODE: No data will be sent to CRM\n\n";
}

// Get module settings
$settings = ModuleAutoCRM::findFirst();
if (!$settings) {
    echo "ERROR: Module settings not found\n";
    exit(1);
}

if (empty($settings->api_url) || empty($settings->api_token)) {
    echo "ERROR: API not configured\n";
    exit(1);
}

$pbxHost = $settings->pbx_host ?? '';
$pbxProtocol = $settings->pbx_protocol ?? 'https';

echo "Settings:\n";
echo "  API URL: {$settings->api_url}\n";
echo "  PBX Host: {$pbxHost}\n";
echo "  Protocol: {$pbxProtocol}\n\n";

// Initialize API client
$client = new AutoCrmApiClient($settings->api_url, $settings->api_token);
$logger = new Logger('ResyncCalls', 'ModuleAutoCRM');

// Build users cache
$usersCache = [];
$users = AutoCrmUsers::getActiveUsers();
foreach ($users as $user) {
    if (!empty($user->asterisk_extension)) {
        $usersCache[$user->asterisk_extension] = $user;
    }
}

echo "Loaded " . count($usersCache) . " users\n\n";

// Get all sent calls with crm_call_id (these can be updated via PUT)
$conditions = 'sync_status = :status: AND crm_call_id IS NOT NULL AND crm_call_id > 0';
$bind = ['status' => AutoCrmCalls::STATUS_SENT];

$params = [
    'conditions' => $conditions,
    'bind' => $bind,
    'order' => 'id ASC'
];

if ($limit > 0) {
    $params['limit'] = $limit;
}

$calls = AutoCrmCalls::find($params);

$total = count($calls);
$updated = 0;
$errors = 0;
$skipped = 0;

echo "Found {$total} sent calls to resync\n\n";

foreach ($calls as $call) {
    echo "ID: {$call->id}, CRM ID: {$call->crm_call_id}\n";

    // Find user
    $extension = ($call->direction === AutoCrmCalls::DIRECTION_INCOMING)
        ? $call->to_number
        : $call->from_number;

    if (!isset($usersCache[$extension])) {
        echo "  SKIP: No CRM user for extension {$extension}\n\n";
        $skipped++;
        continue;
    }

    $user = $usersCache[$extension];

    // Get autosalon_id
    $autosalonId = $user->getFirstAutosalonId();
    if (empty($autosalonId)) {
        $autosalonId = $settings->autosalon_id;
    }

    if (empty($autosalonId)) {
        echo "  SKIP: No autosalon for user\n\n";
        $skipped++;
        continue;
    }

    // Map status
    $apiStatus = ($call->call_status === AutoCrmCalls::CALL_STATUS_MISSED) ? 'new' : $call->call_status;

    // Build record URL with protocol
    $recordUrl = '';
    if (!empty($call->record_url)) {
        $recordUrl = $call->record_url;
        if (strpos($recordUrl, 'http://') !== 0 && strpos($recordUrl, 'https://') !== 0) {
            $recordUrl = $pbxProtocol . '://' . $recordUrl;
        }
    }

    // Build payload
    $payload = [
        'entry_id'     => AutoCrmCalls::cleanIdForApi($call->linkedid),
        'call_id'      => AutoCrmCalls::cleanIdForApi($call->uniqueid),
        'from'         => $call->from_number,
        'to'           => $call->to_number,
        'datetime'     => $call->call_datetime,
        'datetime_end' => $call->call_datetime_end ?: $call->call_datetime,
        'status'       => $apiStatus,
        'direction'    => $call->direction,
        'user_id'      => $user->crm_user_id,
        'autosalon_id' => $autosalonId,
    ];

    if (!empty($recordUrl)) {
        $payload['record'] = $recordUrl;
    }

    echo "  Record URL: " . ($recordUrl ?: '(none)') . "\n";

    if ($dryRun) {
        echo "  Status: WOULD UPDATE (PUT /call/{$call->crm_call_id})\n\n";
        $updated++;
        continue;
    }

    // Send PUT request
    try {
        $response = $client->updateCall($call->crm_call_id, $payload);

        // Check success
        $isSuccess = false;
        if (isset($response['status']) && $response['status'] == 1) {
            $isSuccess = true;
        } elseif (isset($response['success']) && $response['success'] === true) {
            $isSuccess = true;
        }

        if ($isSuccess) {
            echo "  Status: UPDATED\n\n";
            $updated++;
        } else {
            echo "  Status: ERROR - " . json_encode($response) . "\n\n";
            $errors++;
        }

    } catch (AutoCrmApiException $e) {
        echo "  Status: ERROR - " . $e->getMessage() . "\n\n";
        $errors++;
    } catch (\Exception $e) {
        echo "  Status: ERROR - " . $e->getMessage() . "\n\n";
        $errors++;
    }

    // Small delay to avoid rate limiting
    usleep(100000); // 100ms
}

echo "=== Summary ===\n";
echo "Total: {$total}\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
echo "Errors: {$errors}\n";

if ($dryRun) {
    echo "\nDRY-RUN: No data was sent. Run without --dry-run to apply.\n";
}

exit($errors > 0 ? 1 : 0);
