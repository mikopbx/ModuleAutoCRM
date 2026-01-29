#!/usr/bin/php
<?php
/**
 * Sync autosalons from AutoCRM API to local cache
 * Run via cron: 5 3 * * * (daily at 3:05 AM)
 *
 * Logs are written to: /storage/usbdisk1/mikopbx/logs/ModuleAutoCRM/SalonSyncer.log
 */

require_once('Globals.php');

use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleAutoCRM\Lib\SalonSyncer;
use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;

$scriptName = 'sync-salons';

// Get module settings
$settings = ModuleAutoCRM::findFirst();
if (!$settings) {
    SystemMessages::sysLogMsg($scriptName, 'Module settings not found', LOG_ERR);
    exit(1);
}

// Check API configuration
if (empty($settings->api_url) || empty($settings->api_token)) {
    SystemMessages::sysLogMsg($scriptName, 'API not configured', LOG_INFO);
    exit(0);
}

SystemMessages::sysLogMsg($scriptName, 'Starting salons sync...', LOG_INFO);

try {
    $syncer = new SalonSyncer();
    $stats = $syncer->sync();

    SystemMessages::sysLogMsg(
        $scriptName,
        sprintf(
            'Salons sync completed: total=%d, created=%d, updated=%d, skipped=%d, errors=%d',
            $stats['total'],
            $stats['created'],
            $stats['updated'],
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
