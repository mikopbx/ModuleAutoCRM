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

use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;
use Modules\ModuleAutoCRM\Models\AutoCrmCalls;
use Modules\ModuleAutoCRM\Models\AutoCrmUsers;

/**
 * Service for uploading calls to AutoCRM with detailed logging
 *
 * @package Modules\ModuleAutoCRM\Lib
 */
class CallUploader
{
    /** @var Logger */
    private Logger $logger;

    /** @var AutoCrmApiClient|null */
    private ?AutoCrmApiClient $apiClient = null;

    /** @var ModuleAutoCRM|null */
    private ?ModuleAutoCRM $settings = null;

    /** @var array Last operation result */
    private array $lastResult = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new Logger('CallUploader', 'ModuleAutoCRM');
    }

    /**
     * Initialize API client
     *
     * @return bool
     */
    private function initApiClient(): bool
    {
        $this->logger->writeInfo('Initializing API client...');

        $this->settings = ModuleAutoCRM::findFirst();
        if ($this->settings === null) {
            $this->logger->writeError('Module settings not found');
            return false;
        }

        if (empty($this->settings->api_url) || empty($this->settings->api_token)) {
            $this->logger->writeError('API URL or token not configured');
            return false;
        }

        $this->logger->writeInfo('API URL: ' . $this->settings->api_url);

        try {
            $this->apiClient = new AutoCrmApiClient(
                $this->settings->api_url,
                $this->settings->api_token
            );
            $this->logger->writeInfo('API client initialized successfully');
            return true;
        } catch (\Exception $e) {
            $this->logger->writeError('Failed to initialize API client: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Upload a single call to AutoCRM (POST /call)
     *
     * @param int $callId Internal call ID from AutoCrmCalls
     * @return array ['success' => bool, 'message' => string, 'crm_call_id' => int|null]
     */
    public function uploadCall(int $callId): array
    {
        $this->logger->writeInfo("=== Starting upload for call ID: {$callId} ===");

        // Find call record
        $call = AutoCrmCalls::findFirst($callId);
        if ($call === null) {
            $this->logger->writeError("Call not found: {$callId}");
            return $this->result(false, "Call not found: {$callId}");
        }

        $this->logger->writeInfo([
            'uniqueid' => $call->uniqueid,
            'linkedid' => $call->linkedid,
            'direction' => $call->direction,
            'call_status' => $call->call_status,
            'sync_status' => $call->sync_status,
            'from_number' => $call->from_number,
            'to_number' => $call->to_number,
        ], 'Call found');

        // Initialize API client
        if (!$this->initApiClient()) {
            return $this->result(false, 'Failed to initialize API client');
        }

        // Find CRM user
        $crmUser = $this->findCrmUser($call);
        if ($crmUser === null) {
            $error = 'No CRM user found for this call';
            $this->logger->writeError($error);
            $call->markAsError($error);
            return $this->result(false, $error);
        }

        $this->logger->writeInfo([
            'crm_user_id' => $crmUser->crm_user_id,
            'person' => $crmUser->person,
            'salon' => $crmUser->salon,
        ], 'CRM user found');

        // Get autosalon ID
        $autosalonId = $crmUser->getFirstAutosalonId();
        if ($autosalonId === null) {
            $error = 'User has no autosalon assigned';
            $this->logger->writeError($error);
            $call->markAsError($error);
            return $this->result(false, $error);
        }
        $this->logger->writeInfo("Autosalon ID: {$autosalonId}");

        // Build record URL (use view parameter with full path)
        $recordUrl = '';
        if (!empty($call->record_path)) {
            $recordUrl = $this->buildRecordUrl($call->record_path);
            $this->logger->writeInfo("Record URL: {$recordUrl}");
        } else {
            $this->logger->writeInfo('No recording path available');
        }

        // Map call status: missed -> new, answered -> answered
        $apiStatus = $call->call_status === AutoCrmCalls::CALL_STATUS_ANSWERED ? 'answered' : 'new';
        $this->logger->writeInfo("API status: {$apiStatus} (from {$call->call_status})");

        // Get answer time from CDR for answered calls
        $answerTime = null;
        if ($call->call_status === AutoCrmCalls::CALL_STATUS_ANSWERED) {
            $answerTime = $this->getAnswerTimeFromCdr($call->uniqueid, $call->linkedid, $call->to_number);
            if ($answerTime) {
                $this->logger->writeInfo("Answer time from CDR: {$answerTime}");
            }
        }

        // Build API payload
        // According to API docs:
        // 'from' = исходящий номер (caller number)
        // 'to' = входящий номер (destination number)
        // 'record' = URL записи (not 'record_url')
        $payload = [
            'entry_id' => AutoCrmCalls::cleanIdForApi($call->linkedid),
            'call_id' => AutoCrmCalls::cleanIdForApi($call->uniqueid),
            'from' => $call->from_number,
            'to' => $call->to_number,
            'datetime' => $call->call_datetime,
            'datetime_end' => $call->call_datetime_end ?: $call->call_datetime,
            'status' => $apiStatus,
            'direction' => $call->direction,
            'user_id' => $crmUser->crm_user_id,
            'autosalon_id' => $autosalonId,
        ];

        // Add record URL only if available
        if (!empty($recordUrl)) {
            $payload['record'] = $recordUrl;
        }

        // Add answer time for answered calls
        if ($answerTime !== null) {
            $payload['datetime_answer'] = $answerTime;
        }

        $this->logger->writeInfo($payload, 'API payload');

        // Send to API
        try {
            $this->logger->writeInfo('Sending POST /call request...');
            $response = $this->apiClient->createCall($payload);

            $this->logger->writeInfo($response, 'API response');

            // Check for success (API can return either 'status' => 1 or 'success' => true)
            $isSuccess = false;
            if (isset($response['status']) && $response['status'] == 1) {
                $isSuccess = true;
            } elseif (isset($response['success']) && $response['success'] === true) {
                $isSuccess = true;
            }

            if ($isSuccess && isset($response['result']['id'])) {
                $crmCallId = (int)$response['result']['id'];
                $call->markAsSent($crmCallId);
                $this->logger->writeInfo("SUCCESS! CRM call ID: {$crmCallId}");
                return $this->result(true, "Uploaded successfully. CRM ID: {$crmCallId}", $crmCallId);
            } else {
                $error = 'API returned unexpected response: ' . json_encode($response);
                $this->logger->writeError($error);
                $call->markAsError($error);
                return $this->result(false, $error);
            }
        } catch (AutoCrmApiException $e) {
            $error = 'API error: ' . $e->getMessage();
            $this->logger->writeError($error);
            $call->markAsError($e->getMessage());
            return $this->result(false, $error);
        } catch (\Exception $e) {
            $error = 'Exception: ' . $e->getMessage();
            $this->logger->writeError($error);
            $call->markAsError($e->getMessage());
            return $this->result(false, $error);
        }
    }

    /**
     * Update a call in AutoCRM (PUT /call/{id})
     *
     * @param int $callId Internal call ID from AutoCrmCalls
     * @return array ['success' => bool, 'message' => string]
     */
    public function updateCall(int $callId): array
    {
        $this->logger->writeInfo("=== Starting update for call ID: {$callId} ===");

        // Find call record
        $call = AutoCrmCalls::findFirst($callId);
        if ($call === null) {
            $this->logger->writeError("Call not found: {$callId}");
            return $this->result(false, "Call not found: {$callId}");
        }

        if (empty($call->crm_call_id)) {
            $this->logger->writeError('Call has no CRM ID - cannot update');
            return $this->result(false, 'Call has no CRM ID - upload first');
        }

        $this->logger->writeInfo([
            'uniqueid' => $call->uniqueid,
            'crm_call_id' => $call->crm_call_id,
            'sync_status' => $call->sync_status,
        ], 'Call found');

        // Initialize API client
        if (!$this->initApiClient()) {
            return $this->result(false, 'Failed to initialize API client');
        }

        // Find CRM user
        $crmUser = $this->findCrmUser($call);
        if ($crmUser === null) {
            $error = 'No CRM user found for this call';
            $this->logger->writeError($error);
            return $this->result(false, $error);
        }

        // Get autosalon ID
        $autosalonId = $crmUser->getFirstAutosalonId();

        // Build record URL (use view parameter with full path)
        $recordUrl = '';
        if (!empty($call->record_path)) {
            $recordUrl = $this->buildRecordUrl($call->record_path);
        }

        // Map call status
        $apiStatus = $call->call_status === AutoCrmCalls::CALL_STATUS_ANSWERED ? 'answered' : 'new';

        // Get answer time from CDR for answered calls
        $answerTime = null;
        if ($call->call_status === AutoCrmCalls::CALL_STATUS_ANSWERED) {
            $answerTime = $this->getAnswerTimeFromCdr($call->uniqueid, $call->linkedid, $call->to_number);
        }

        // Build API payload (same structure as POST)
        $payload = [
            'entry_id' => AutoCrmCalls::cleanIdForApi($call->linkedid),
            'call_id' => AutoCrmCalls::cleanIdForApi($call->uniqueid),
            'from' => $call->from_number,
            'to' => $call->to_number,
            'datetime' => $call->call_datetime,
            'datetime_end' => $call->call_datetime_end ?: $call->call_datetime,
            'status' => $apiStatus,
            'direction' => $call->direction,
            'user_id' => $crmUser->crm_user_id,
            'autosalon_id' => $autosalonId,
        ];

        // Add record URL only if available
        if (!empty($recordUrl)) {
            $payload['record'] = $recordUrl;
        }

        // Add answer time for answered calls
        if ($answerTime !== null) {
            $payload['datetime_answer'] = $answerTime;
        }

        $this->logger->writeInfo($payload, 'API payload for PUT');

        // Send to API
        try {
            $this->logger->writeInfo("Sending PUT /call/{$call->crm_call_id} request...");
            $response = $this->apiClient->updateCall($call->crm_call_id, $payload);

            $this->logger->writeInfo($response, 'API response');

            // Check for success (API can return either 'status' => 1 or 'success' => true)
            $isSuccess = false;
            if (isset($response['status']) && $response['status'] == 1) {
                $isSuccess = true;
            } elseif (isset($response['success']) && $response['success'] === true) {
                $isSuccess = true;
            }

            if ($isSuccess) {
                $call->sync_status = AutoCrmCalls::STATUS_SENT;
                $call->sync_error = '';
                $call->save();
                $this->logger->writeInfo('SUCCESS! Call updated in CRM');
                return $this->result(true, "Updated successfully. CRM ID: {$call->crm_call_id}");
            } else {
                $error = 'API returned unexpected response: ' . json_encode($response);
                $this->logger->writeError($error);
                return $this->result(false, $error);
            }
        } catch (AutoCrmApiException $e) {
            $error = 'API error: ' . $e->getMessage();
            $this->logger->writeError($error);
            return $this->result(false, $error);
        } catch (\Exception $e) {
            $error = 'Exception: ' . $e->getMessage();
            $this->logger->writeError($error);
            return $this->result(false, $error);
        }
    }

    /**
     * Find CRM user for a call
     *
     * @param AutoCrmCalls $call
     * @return AutoCrmUsers|null
     */
    private function findCrmUser(AutoCrmCalls $call): ?AutoCrmUsers
    {
        // For incoming calls - search by to_number (internal extension)
        // For outgoing calls - search by from_number (internal extension)
        $internalNumber = $call->direction === AutoCrmCalls::DIRECTION_INCOMING
            ? $call->to_number
            : $call->from_number;

        $this->logger->writeInfo("Searching CRM user by internal number: {$internalNumber}");

        // First try by asterisk_extension
        $user = AutoCrmUsers::findFirst([
            'conditions' => 'asterisk_extension = :ext: AND status = 1',
            'bind' => ['ext' => $internalNumber]
        ]);

        if ($user !== null) {
            $this->logger->writeInfo("Found by asterisk_extension: {$user->person}");
            return $user;
        }

        // Then try by phone
        $user = AutoCrmUsers::findByPhone($internalNumber);
        if ($user !== null) {
            $this->logger->writeInfo("Found by phone: {$user->person}");
            return $user;
        }

        $this->logger->writeWarning("No CRM user found for number: {$internalNumber}");
        return null;
    }

    /**
     * Build public URL for call recording
     *
     * @param string $recordPath Full path to recording file
     * @return string
     */
    private function buildRecordUrl(string $recordPath): string
    {
        $pbxHost = $this->settings->pbx_host ?? '';
        if (empty($pbxHost)) {
            $this->logger->writeWarning('PBX host not configured');
            return '';
        }

        // Get protocol from settings (default to https)
        $protocol = $this->settings->pbx_protocol ?? 'https';
        if (!in_array($protocol, ['http', 'https'])) {
            $protocol = 'https';
        }

        // Build full URL
        $baseUrl = $protocol . '://' . ltrim($pbxHost, '/');

        // Use view parameter with full file path
        return rtrim($baseUrl, '/') . '/pbxcore/api/modules/ModuleAutoCRM/records?view=' . urlencode($recordPath);
    }

    /**
     * Get answer time from CDR database
     *
     * @param string $uniqueid Call uniqueid (CDR UNIQUEID format)
     * @param string $linkedid Call linkedid (fallback search)
     * @param string $toNumber Destination number (fallback search)
     * @return string|null Answer time in Y-m-d H:i:s format or null
     */
    private function getAnswerTimeFromCdr(string $uniqueid, string $linkedid, string $toNumber): ?string
    {
        $cdrDbPath = '/storage/usbdisk1/mikopbx/astlogs/asterisk/cdr.db';
        if (!file_exists($cdrDbPath)) {
            return null;
        }

        try {
            $db = new \SQLite3($cdrDbPath, SQLITE3_OPEN_READONLY);

            // First try to find by exact UNIQUEID match
            $stmt = $db->prepare('SELECT answer FROM cdr_general WHERE UNIQUEID = :uniqueid AND disposition = :disposition LIMIT 1');
            $stmt->bindValue(':uniqueid', $uniqueid, SQLITE3_TEXT);
            $stmt->bindValue(':disposition', 'ANSWERED', SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);

            // Fallback: search by linkedid and destination number (for legacy records)
            if (!$row || empty($row['answer'])) {
                $stmt = $db->prepare('SELECT answer FROM cdr_general WHERE linkedid = :linkedid AND dst_num = :dst_num AND disposition = :disposition AND answer IS NOT NULL AND answer != "" ORDER BY answer ASC LIMIT 1');
                $stmt->bindValue(':linkedid', $linkedid, SQLITE3_TEXT);
                $stmt->bindValue(':dst_num', $toNumber, SQLITE3_TEXT);
                $stmt->bindValue(':disposition', 'ANSWERED', SQLITE3_TEXT);
                $result = $stmt->execute();
                $row = $result->fetchArray(SQLITE3_ASSOC);
            }

            $db->close();

            if ($row && !empty($row['answer'])) {
                // Remove milliseconds if present (2026-01-26 21:52:32.862 -> 2026-01-26 21:52:32)
                $answer = $row['answer'];
                if (strpos($answer, '.') !== false) {
                    $answer = substr($answer, 0, strpos($answer, '.'));
                }
                return $answer;
            }
        } catch (\Exception $e) {
            $this->logger->writeWarning("Failed to get answer time from CDR: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Build result array
     *
     * @param bool $success
     * @param string $message
     * @param int|null $crmCallId
     * @return array
     */
    private function result(bool $success, string $message, ?int $crmCallId = null): array
    {
        $this->lastResult = [
            'success' => $success,
            'message' => $message,
            'crm_call_id' => $crmCallId,
        ];
        return $this->lastResult;
    }

    /**
     * Get last result
     *
     * @return array
     */
    public function getLastResult(): array
    {
        return $this->lastResult;
    }
}
