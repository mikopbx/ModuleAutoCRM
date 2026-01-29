#!/usr/bin/php
<?php
/**
 * Rebuild record_url for all calls based on record_path
 * Use this to fix/update record URLs after changing PBX host or URL format
 *
 * Usage: php rebuild-record-urls.php [--dry-run]
 *
 * Options:
 *   --dry-run    Show what would be changed without saving
 */

require_once('Globals.php');

use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;
use Modules\ModuleAutoCRM\Models\AutoCrmCalls;

$scriptName = 'rebuild-record-urls';

// Check for dry-run mode
$dryRun = in_array('--dry-run', $argv);
if ($dryRun) {
    echo "DRY-RUN MODE: No changes will be saved\n\n";
}

// Get module settings
$settings = ModuleAutoCRM::findFirst();
if (!$settings) {
    echo "ERROR: Module settings not found\n";
    exit(1);
}

$pbxHost = $settings->pbx_host ?? '';
$pbxProtocol = $settings->pbx_protocol ?? 'https';

if (empty($pbxHost)) {
    echo "ERROR: PBX host not configured in module settings\n";
    exit(1);
}

// Ensure protocol is valid
if (!in_array($pbxProtocol, ['http', 'https'])) {
    $pbxProtocol = 'https';
}

echo "Settings:\n";
echo "  PBX Host: {$pbxHost}\n";
echo "  Protocol: {$pbxProtocol}\n\n";

/**
 * Build record URL from record path
 *
 * @param string $recordPath Full path to recording file
 * @param string $pbxHost PBX host
 * @param string $protocol Protocol (http/https)
 * @return string
 */
function buildRecordUrl(string $recordPath, string $pbxHost, string $protocol): string
{
    if (empty($recordPath)) {
        return '';
    }

    $baseUrl = $protocol . '://' . ltrim($pbxHost, '/');
    return rtrim($baseUrl, '/') . '/pbxcore/api/modules/ModuleAutoCRM/records?view=' . urlencode($recordPath);
}

// Get all calls with record_path
$calls = AutoCrmCalls::find([
    'conditions' => 'record_path IS NOT NULL AND record_path != ""',
    'order' => 'id ASC'
]);

$total = count($calls);
$updated = 0;
$skipped = 0;
$errors = 0;

echo "Found {$total} calls with recordings\n\n";

foreach ($calls as $call) {
    $oldUrl = $call->record_url;
    $newUrl = buildRecordUrl($call->record_path, $pbxHost, $pbxProtocol);

    // Check if URL changed
    if ($oldUrl === $newUrl) {
        $skipped++;
        continue;
    }

    echo "ID: {$call->id}\n";
    echo "  Path: {$call->record_path}\n";
    echo "  Old URL: {$oldUrl}\n";
    echo "  New URL: {$newUrl}\n";

    if (!$dryRun) {
        $call->record_url = $newUrl;
        if ($call->save()) {
            echo "  Status: UPDATED\n";
            $updated++;
        } else {
            $errorMsg = implode(', ', $call->getMessages());
            echo "  Status: ERROR - {$errorMsg}\n";
            $errors++;
        }
    } else {
        echo "  Status: WOULD UPDATE\n";
        $updated++;
    }
    echo "\n";
}

echo "=== Summary ===\n";
echo "Total: {$total}\n";
echo "Updated: {$updated}\n";
echo "Skipped (unchanged): {$skipped}\n";
echo "Errors: {$errors}\n";

if ($dryRun) {
    echo "\nDRY-RUN: No changes were saved. Run without --dry-run to apply changes.\n";
}

exit($errors > 0 ? 1 : 0);
