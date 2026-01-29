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

namespace Modules\ModuleAutoCRM\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleAutoCRM\App\Forms\ModuleAutoCRMForm;
use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;
use Modules\ModuleAutoCRM\Models\AutoCrmUsers;
use Modules\ModuleAutoCRM\Models\AutoCrmSalons;
use Modules\ModuleAutoCRM\Models\AutoCrmCalls;
use Modules\ModuleAutoCRM\Lib\CallUploader;

class ModuleAutoCRMController extends BaseController
{
    private string $moduleUniqueID = 'ModuleAutoCRM';
    private string $moduleDir;

    /**
     * Basic initial class
     */
    public function initialize(): void
    {
        $this->moduleDir = PbxExtensionUtils::getModuleDir($this->moduleUniqueID);
        $this->view->logoImagePath = $this->url->get() . 'assets/img/cache/' . $this->moduleUniqueID . '/logo.svg';
        $this->view->submitMode = null;
        parent::initialize();
    }

    /**
     * Index page controller - main page with tabs
     */
    public function indexAction(): void
    {
        // JS assets
        $footerCollection = $this->assets->collection('footerJS');
        $footerCollection->addJs('js/pbx/main/form.js', true);
        $footerCollection->addJs('js/vendor/datatable/dataTables.semanticui.js', true);
        $footerCollection->addJs('js/vendor/semantic/tab.min.js', true);
        $footerCollection->addJs("js/cache/{$this->moduleUniqueID}/moduleautocrm-index.js", true);

        // CSS assets
        $headerCollectionCSS = $this->assets->collection('headerCSS');
        $headerCollectionCSS->addCss('css/vendor/datatable/dataTables.semanticui.min.css', true);
        $headerCollectionCSS->addCss('css/vendor/semantic/tab.min.css', true);
        $headerCollectionCSS->addCss('css/vendor/semantic/statistic.min.css', true);
        $headerCollectionCSS->addCss("css/cache/{$this->moduleUniqueID}/moduleautocrm-index.css", true);

        // Get or create settings
        $settings = ModuleAutoCRM::findFirst();
        if ($settings === null) {
            $settings = new ModuleAutoCRM();
        }

        // Get salons for dropdown
        $salons = AutoCrmSalons::getListForDropdown();

        // Form options
        $options = [
            'salons' => $salons,
        ];

        $this->view->form = new ModuleAutoCRMForm($settings, $options);

        // Pick the view template
        $this->view->pick("{$this->moduleDir}/App/Views/index");

        // Get salons for tab
        $salonsData = AutoCrmSalons::find([
            'order' => 'name ASC'
        ]);
        $this->view->salons = $salonsData;

        // Create salons map (crm_salon_id => name) for displaying in users table
        $salonsMap = [];
        foreach ($salonsData as $salon) {
            $salonsMap[$salon->crm_salon_id] = $salon->name;
        }

        // Get users for tab with salon names
        $users = AutoCrmUsers::find([
            'order' => 'status ASC, person ASC'
        ]);

        // Prepare users data with salon names
        $usersData = [];
        foreach ($users as $user) {
            // Get primary salon ID (salon field first, then first from user_autosalons)
            $primarySalonId = $user->getFirstAutosalonId();
            $primarySalonName = '';
            if ($primarySalonId !== null && isset($salonsMap[$primarySalonId])) {
                $primarySalonName = $salonsMap[$primarySalonId];
            }

            $usersData[] = [
                'crm_user_id' => $user->crm_user_id,
                'person' => $user->person,
                'phone' => $user->phone,
                'work_phone' => $user->work_phone,
                'asterisk_extension' => $user->asterisk_extension,
                'status' => $user->status,
                'salonName' => $primarySalonName,
            ];
        }
        $this->view->users = $usersData;

        // Get calls stats
        $this->view->callsStats = AutoCrmCalls::getStats();

        // Get recent calls
        $recentCalls = AutoCrmCalls::find([
            'order' => 'created_at DESC',
            'limit' => 50
        ]);
        $this->view->recentCalls = $recentCalls;

        // Counts for tabs
        $this->view->usersCount = AutoCrmUsers::count();
        $this->view->salonsCount = AutoCrmSalons::count();
        $this->view->callsCount = AutoCrmCalls::count();
    }

    /**
     * Saves the form data to the database.
     */
    public function saveAction(): void
    {
        // Debug log
        \MikoPBX\Core\System\SystemMessages::sysLogMsg(__METHOD__, 'saveAction called', LOG_DEBUG);

        if (!$this->request->isPost()) {
            \MikoPBX\Core\System\SystemMessages::sysLogMsg(__METHOD__, 'Not POST request', LOG_DEBUG);
            return;
        }

        $data = $this->request->getPost();
        \MikoPBX\Core\System\SystemMessages::sysLogMsg(__METHOD__, 'POST data: ' . json_encode($data), LOG_DEBUG);

        $record = ModuleAutoCRM::findFirstById($data['id']);
        if ($record === null) {
            $record = new ModuleAutoCRM();
            \MikoPBX\Core\System\SystemMessages::sysLogMsg(__METHOD__, 'Creating new record', LOG_DEBUG);
        } else {
            \MikoPBX\Core\System\SystemMessages::sysLogMsg(__METHOD__, 'Found existing record id=' . $record->id, LOG_DEBUG);
        }

        // Map form fields to model
        $record->api_url = $data['api_url'] ?? '';
        $record->api_token = $data['api_token'] ?? '';
        $record->autosalon_id = !empty($data['autosalon_id']) ? (int)$data['autosalon_id'] : null;
        $record->pbx_protocol = $data['pbx_protocol'] ?? 'https';
        $record->pbx_host = $data['pbx_host'] ?? '';
        $record->sync_users_enabled = isset($data['sync_users_enabled']) && $data['sync_users_enabled'] === 'on' ? '1' : '0';
        $record->sync_calls_enabled = isset($data['sync_calls_enabled']) && $data['sync_calls_enabled'] === 'on' ? '1' : '0';
        $record->cdr_offset = (int)($data['cdr_offset'] ?? 0);

        \MikoPBX\Core\System\SystemMessages::sysLogMsg(__METHOD__, 'Saving: api_url=' . $record->api_url, LOG_DEBUG);

        if ($record->save()) {
            \MikoPBX\Core\System\SystemMessages::sysLogMsg(__METHOD__, 'Save successful', LOG_DEBUG);
            $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
            $this->view->success = true;
        } else {
            $errors = implode(', ', $record->getMessages());
            \MikoPBX\Core\System\SystemMessages::sysLogMsg(__METHOD__, 'Save failed: ' . $errors, LOG_ERR);
            $this->flash->error(implode('<br>', $record->getMessages()));
            $this->view->success = false;
        }
    }

    /**
     * AJAX: Get users data for DataTable
     */
    public function getUsersDataAction(): void
    {
        $this->view->disable();

        // Build salons map for name lookup
        $salonsMap = [];
        $salons = AutoCrmSalons::find();
        foreach ($salons as $salon) {
            $salonsMap[$salon->crm_salon_id] = $salon->name;
        }

        $users = AutoCrmUsers::find([
            'order' => 'status ASC, person ASC'
        ]);

        $data = [];
        foreach ($users as $user) {
            $statusLabel = $user->status == 1
                ? '<span class="ui green label">Активный</span>'
                : '<span class="ui grey label">Уволен</span>';

            // Get primary salon name
            $primarySalonId = $user->getFirstAutosalonId();
            $salonName = '';
            if ($primarySalonId !== null && isset($salonsMap[$primarySalonId])) {
                $salonName = $salonsMap[$primarySalonId];
            }

            $data[] = [
                'DT_RowId' => $user->id,
                'crm_user_id' => $user->crm_user_id,
                'person' => $user->person,
                'phone' => $user->phone,
                'work_phone' => $user->work_phone,
                'asterisk_extension' => $user->asterisk_extension,
                'salonName' => $salonName,
                'status' => $statusLabel,
                'last_sync' => $user->last_sync,
            ];
        }

        echo json_encode(['data' => $data]);
        exit();
    }

    /**
     * AJAX: Get salons data for DataTable
     */
    public function getSalonsDataAction(): void
    {
        $this->view->disable();

        $salons = AutoCrmSalons::find([
            'order' => 'name ASC'
        ]);

        $data = [];
        foreach ($salons as $salon) {
            $data[] = [
                'DT_RowId' => $salon->id,
                'crm_salon_id' => $salon->crm_salon_id,
                'name' => $salon->name,
                'address' => $salon->address,
                'phone' => $salon->phone,
                'last_sync' => $salon->last_sync,
            ];
        }

        echo json_encode(['data' => $data]);
        exit();
    }

    /**
     * AJAX: Get calls data for DataTable
     */
    public function getCallsDataAction(): void
    {
        $this->view->disable();

        $calls = AutoCrmCalls::find([
            'order' => 'created_at DESC',
            'limit' => 100
        ]);

        $data = [];
        foreach ($calls as $call) {
            // Make status label clickable for manual upload/update
            $statusClass = 'upload-call';
            switch ($call->sync_status) {
                case AutoCrmCalls::STATUS_SENT:
                    $statusLabel = '<a href="#" class="ui green label ' . $statusClass . '" data-call-id="' . $call->id . '" data-action="update" title="Click to update in CRM">Отправлен</a>';
                    break;
                case AutoCrmCalls::STATUS_ERROR:
                    $statusLabel = '<a href="#" class="ui red label ' . $statusClass . '" data-call-id="' . $call->id . '" data-action="upload" title="Click to retry upload">' . htmlspecialchars($call->sync_error ?: 'Ошибка') . '</a>';
                    break;
                default:
                    $statusLabel = '<a href="#" class="ui yellow label ' . $statusClass . '" data-call-id="' . $call->id . '" data-action="upload" title="Click to upload to CRM">Ожидает</a>';
            }

            $directionIcon = $call->direction === AutoCrmCalls::DIRECTION_INCOMING
                ? '<i class="phone volume icon green"></i>'
                : '<i class="phone icon blue"></i>';

            $callStatusLabel = $call->call_status === AutoCrmCalls::CALL_STATUS_ANSWERED
                ? '<span class="ui green label">Отвечен</span>'
                : '<span class="ui red label">Пропущен</span>';

            $data[] = [
                'DT_RowId' => $call->id,
                'linkedid' => $call->linkedid,
                'direction' => $directionIcon,
                'call_datetime' => $call->call_datetime,
                'from_number' => $call->from_number,
                'to_number' => $call->to_number,
                'call_status' => $callStatusLabel,
                'sync_status' => $statusLabel,
                'crm_call_id' => $call->crm_call_id ?: '-',
                'sync_error' => $call->sync_error ?: '',
            ];
        }

        echo json_encode(['data' => $data]);
        exit();
    }

    /**
     * AJAX: Get CDR details for a call by linkedid, grouped by UNIQUEID
     */
    public function getCdrDetailsAction(): void
    {
        $this->view->disable();

        $linkedid = $this->request->get('linkedid', 'string', '');
        if (empty($linkedid)) {
            echo json_encode(['success' => false, 'message' => 'Missing linkedid']);
            exit();
        }

        // Get CDR database path
        $cdrDbPath = '/storage/usbdisk1/mikopbx/astlogs/asterisk/cdr.db';
        if (!file_exists($cdrDbPath)) {
            echo json_encode(['success' => false, 'message' => 'CDR database not found']);
            exit();
        }

        try {
            $db = new \SQLite3($cdrDbPath, SQLITE3_OPEN_READONLY);
            $stmt = $db->prepare('SELECT UNIQUEID, id, src_num, dst_num, src_chan, dst_chan, billsec, disposition, start, answer, endtime FROM cdr_general WHERE linkedid = :linkedid ORDER BY UNIQUEID, id ASC');
            $stmt->bindValue(':linkedid', $linkedid, SQLITE3_TEXT);
            $result = $stmt->execute();

            // Group by UNIQUEID
            $groups = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $uniqueid = $row['UNIQUEID'];
                if (!isset($groups[$uniqueid])) {
                    $groups[$uniqueid] = [
                        'uniqueid' => $uniqueid,
                        'rows' => [],
                        'answered' => false,
                        'total_billsec' => 0,
                    ];
                }
                $groups[$uniqueid]['rows'][] = [
                    'id' => $row['id'],
                    'src_num' => $row['src_num'],
                    'dst_num' => $row['dst_num'],
                    'src_chan' => $this->formatChannel($row['src_chan']),
                    'dst_chan' => $this->formatChannel($row['dst_chan']),
                    'billsec' => $row['billsec'],
                    'disposition' => $row['disposition'],
                    'start' => $row['start'],
                    'answer' => $row['answer'],
                    'endtime' => $row['endtime'],
                ];
                if ($row['disposition'] === 'ANSWERED') {
                    $groups[$uniqueid]['answered'] = true;
                }
                $groups[$uniqueid]['total_billsec'] += (int)$row['billsec'];
            }
            $db->close();

            echo json_encode(['success' => true, 'data' => array_values($groups)]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    /**
     * Format channel name for display (extract short name)
     */
    private function formatChannel(string $channel): string
    {
        // PJSIP/201-00000005 -> PJSIP/201
        // Queue:2001 -> Queue:2001
        if (strpos($channel, '-') !== false) {
            return substr($channel, 0, strrpos($channel, '-'));
        }
        return $channel;
    }

    /**
     * AJAX: Trigger manual sync
     */
    public function syncAction(string $type): void
    {
        $this->view->disable();

        $allowedTypes = ['users', 'salons', 'calls'];
        if (!in_array($type, $allowedTypes)) {
            echo json_encode(['success' => false, 'message' => 'Invalid sync type']);
            exit();
        }

        // Trigger sync via REST API
        $result = file_get_contents(
            'http://127.0.0.1/pbxcore/api/modules/ModuleAutoCRM/sync-' . $type,
            false,
            stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5]])
        );

        echo json_encode(['success' => true, 'message' => ucfirst($type) . ' sync started']);
        exit();
    }

    /**
     * AJAX: Manual upload/update call to AutoCRM
     *
     * For pending calls (sync_status = pending) - creates new call in CRM (POST /call)
     * For sent calls (sync_status = sent) - updates existing call in CRM (PUT /call/{id})
     */
    public function uploadCallAction(): void
    {
        $this->view->disable();

        try {
            $callId = (int)$this->request->get('id', 'int', 0);
            if ($callId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid call ID']);
                exit();
            }

            // Find call record
            $call = AutoCrmCalls::findFirst($callId);
            if ($call === null) {
                echo json_encode(['success' => false, 'message' => 'Call not found']);
                exit();
            }

            $uploader = new CallUploader();

            // Determine action: upload (POST) or update (PUT)
            if (!empty($call->crm_call_id)) {
                // Call already exists in CRM - update it
                $result = $uploader->updateCall($callId);
            } else {
                // New call - upload it
                $result = $uploader->uploadCall($callId);
            }

            echo json_encode($result);
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
        exit();
    }
}
