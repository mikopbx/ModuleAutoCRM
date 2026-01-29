#!/usr/bin/php
<?php
/**
 * Test CallUploader service
 *
 * Run: cd /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoCRM/tests && php test-call-uploader.php
 */

require_once('Globals.php');

use Modules\ModuleAutoCRM\Models\AutoCrmCalls;
use Modules\ModuleAutoCRM\Models\AutoCrmUsers;
use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;
use Modules\ModuleAutoCRM\Lib\CallUploader;
use Modules\ModuleAutoCRM\Lib\Logger;

echo "=== CallUploader Test ===\n\n";

// Check settings
$settings = ModuleAutoCRM::findFirst();
if ($settings === null) {
    echo "[ERROR] Module settings not found\n";
    exit(1);
}

echo "Settings:\n";
echo "  API URL: " . $settings->api_url . "\n";
echo "  API Token: " . (empty($settings->api_token) ? '(empty)' : '***' . substr($settings->api_token, -4)) . "\n";
echo "  PBX Host: " . $settings->pbx_host . "\n";
echo "\n";

// Get pending calls
$pendingCalls = AutoCrmCalls::find([
    'conditions' => 'sync_status = :status:',
    'bind' => ['status' => AutoCrmCalls::STATUS_PENDING],
    'order' => 'call_datetime DESC',
    'limit' => 5
]);

echo "Pending calls count: " . count($pendingCalls) . "\n";

if (count($pendingCalls) === 0) {
    echo "[INFO] No pending calls to test\n";

    // Show last 3 calls regardless of status
    $lastCalls = AutoCrmCalls::find([
        'order' => 'call_datetime DESC',
        'limit' => 3
    ]);

    echo "\nLast 3 calls:\n";
    foreach ($lastCalls as $call) {
        echo "  ID: {$call->id}, LINKEDID: {$call->linkedid}\n";
        echo "    Status: {$call->sync_status}, CRM ID: " . ($call->crm_call_id ?: '-') . "\n";
        echo "    From: {$call->from_number}, To: {$call->to_number}\n";
        echo "    Date: {$call->call_datetime}\n\n";
    }

    exit(0);
}

echo "\n";

// Get first pending call for test
$testCall = $pendingCalls[0];

echo "Test call:\n";
echo "  ID: {$testCall->id}\n";
echo "  LinkedID: {$testCall->linkedid}\n";
echo "  UniqueID: {$testCall->uniqueid}\n";
echo "  Direction: {$testCall->direction}\n";
echo "  From: {$testCall->from_number}\n";
echo "  To: {$testCall->to_number}\n";
echo "  Call Status: {$testCall->call_status}\n";
echo "  Date: {$testCall->call_datetime}\n";
echo "  Record Path: " . ($testCall->record_path ?: '(none)') . "\n";
echo "\n";

// Find CRM user
$internalNumber = $testCall->direction === AutoCrmCalls::DIRECTION_INCOMING
    ? $testCall->to_number
    : $testCall->from_number;

echo "Looking for CRM user by internal number: {$internalNumber}\n";

$user = AutoCrmUsers::findFirst([
    'conditions' => 'asterisk_extension = :ext: AND status = 1',
    'bind' => ['ext' => $internalNumber]
]);

if ($user === null) {
    $user = AutoCrmUsers::findByPhone($internalNumber);
}

if ($user !== null) {
    echo "  Found user: {$user->person} (CRM ID: {$user->crm_user_id})\n";
    $autosalonId = $user->getFirstAutosalonId();
    echo "  Autosalon ID: " . ($autosalonId ?: 'none') . "\n";
} else {
    echo "  [WARNING] No CRM user found for this number\n";
}

echo "\n";

// Ask user before uploading
echo "=== WARNING ===\n";
echo "This will upload the call to AutoCRM!\n";
echo "Call ID: {$testCall->id}\n";
echo "\n";
echo "Do you want to proceed? (yes/no): ";

$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim($line) !== 'yes') {
    echo "Aborted.\n";
    exit(0);
}

echo "\n=== Uploading call... ===\n\n";

$uploader = new CallUploader();
$result = $uploader->uploadCall($testCall->id);

echo "Result:\n";
echo "  Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
echo "  Message: " . $result['message'] . "\n";
if (isset($result['crm_call_id'])) {
    echo "  CRM Call ID: " . $result['crm_call_id'] . "\n";
}

echo "\nCheck log file: /storage/usbdisk1/mikopbx/log/ModuleAutoCRM/CallUploader.log\n";
