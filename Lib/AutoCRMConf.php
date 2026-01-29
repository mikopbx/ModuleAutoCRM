<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright © 2017-2023 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace Modules\ModuleAutoCRM\Lib;

use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleAutoCRM\Lib\RestAPI\Controllers\AutoCRMApiController;
use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;

class AutoCRMConf extends ConfigClass
{
    /**
     * Adds cron tasks for the module
     *
     * @param array $tasks Reference to the tasks array
     * @return void
     */
    public function createCronTasks(array &$tasks): void
    {
        // Check if module is enabled and configured
        $settings = ModuleAutoCRM::findFirst();
        if ($settings === null) {
            return;
        }

        $phpPath = Util::which('php');
        $binPath = $this->moduleDir . '/bin';

        // Sync calls every minute (always collect from CDR, send only if sync_calls_enabled)
        // Note: sync_calls_enabled is checked inside sync-calls.php for the send phase only
        $tasks[] = "*/1 * * * * cd {$binPath} && {$phpPath} -f sync-calls.php > /dev/null 2>&1\n";

        // Sync users every 5 minutes
        $tasks[] = "*/5 * * * * cd {$binPath} && {$phpPath} -f sync-users.php > /dev/null 2>&1\n";

        // Sync salons once a day at 03:05
        $tasks[] = "5 3 * * * cd {$binPath} && {$phpPath} -f sync-salons.php > /dev/null 2>&1\n";
    }

    /**
     * Process CoreAPI requests under root rights
     *
     * @param array $request
     *
     * @return PBXApiResult An object containing the result of the API call.
     */
    public function moduleRestAPICallback(array $request): PBXApiResult
    {
        $res = new PBXApiResult();
        $res->processor = __METHOD__;
        $action = strtoupper($request['action']);

        switch ($action) {
            case 'CHECK':
                $res->success = true;
                break;
            case 'SYNC-USERS':
                // Manual sync users
                $phpPath = Util::which('php');
                $binPath = $this->moduleDir . '/bin';
                shell_exec("cd {$binPath} && {$phpPath} -f sync-users.php > /dev/null 2>&1 &");
                $res->success = true;
                $res->messages[] = 'Users sync started';
                break;
            case 'SYNC-SALONS':
                // Manual sync salons
                $phpPath = Util::which('php');
                $binPath = $this->moduleDir . '/bin';
                shell_exec("cd {$binPath} && {$phpPath} -f sync-salons.php > /dev/null 2>&1 &");
                $res->success = true;
                $res->messages[] = 'Salons sync started';
                break;
            case 'SYNC-CALLS':
                // Manual sync calls
                $phpPath = Util::which('php');
                $binPath = $this->moduleDir . '/bin';
                shell_exec("cd {$binPath} && {$phpPath} -f sync-calls.php > /dev/null 2>&1 &");
                $res->success = true;
                $res->messages[] = 'Calls sync started';
                break;
            default:
                $res->success = false;
                $res->messages[] = 'API action not found in moduleRestAPICallback ModuleAutoCRM';
        }

        return $res;
    }

    /**
     * Returns array of additional routes for PBXCoreREST interface
     *
     * @return array
     */
    public function getPBXCoreRESTAdditionalRoutes(): array
    {
        return [
            [AutoCRMApiController::class, 'recordsAction', '/pbxcore/api/modules/ModuleAutoCRM/records', 'get', '/', true],
        ];
    }
}
