#!/usr/bin/php
<?php
/**
 * Sync users from AutoCRM API to local cache
 * Run via cron: 0 3 * * * (daily at 3:00 AM)
 *
 * Logs are written to: /storage/usbdisk1/mikopbx/logs/ModuleAutoCRM/UserSyncer.log
 */

require_once('Globals.php');

use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleAutoCRM\Lib\UserSyncer;
use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;

$scriptName = 'sync-users';

// Get module settings
$settings = ModuleAutoCRM::findFirst();
if (!$settings) {
    SystemMessages::sysLogMsg($scriptName, 'Module settings not found', LOG_ERR);
    exit(1);
}

// Check if sync is enabled
if ($settings->sync_users_enabled !== '1') {
    SystemMessages::sysLogMsg($scriptName, 'Users sync is disabled', LOG_INFO);
    exit(0);
}

// Check API configuration
if (empty($settings->api_url) || empty($settings->api_token)) {
    SystemMessages::sysLogMsg($scriptName, 'API not configured', LOG_ERR);
    exit(1);
}

SystemMessages::sysLogMsg($scriptName, 'Starting users sync...', LOG_INFO);

try {
    $syncer = new UserSyncer();
    $stats = $syncer->sync();

    SystemMessages::sysLogMsg(
        $scriptName,
        sprintf(
            'Users sync completed: total=%d, created=%d, updated=%d, deleted=%d, skipped=%d, errors=%d',
            $stats['total'],
            $stats['created'],
            $stats['updated'],
            $stats['deleted'] ?? 0,
            $stats['skipped'],
            $stats['errors']
        ),
        LOG_INFO
    );

    if ($stats['errors'] > 0) {
        exit(1);
    }

} catch (\Exception $e) {
    SystemMessages::sysLogMsg($scriptName, 'Error: ' . $e->getMessage(), LOG_ERR);
    exit(1);
}

exit(0);
