#!/usr/bin/php
<?php
/**
 * Sync calls from MikoPBX CDR to AutoCRM
 * Run via cron: * * * * * (every minute)
 *
 * Logic:
 * - Get CDR records grouped by linkedid (one call can have multiple CDR records)
 * - Filter: only calls involving AutoCRM users
 * - Filter: skip internal calls (both src and dst are internal)
 * - For answered calls: require recording file
 * - Send to AutoCRM API
 */

require_once('Globals.php');

use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleAutoCRM\Lib\AutoCrmApiClient;
use Modules\ModuleAutoCRM\Lib\AutoCrmApiException;
use Modules\ModuleAutoCRM\Lib\HistoryParser;
use Modules\ModuleAutoCRM\Lib\Logger;
use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;
use Modules\ModuleAutoCRM\Models\AutoCrmUsers;
use Modules\ModuleAutoCRM\Models\AutoCrmCalls;

$scriptName = 'sync-calls';

// Initialize file logger for detailed logging
$logger = new Logger('SyncCalls', 'ModuleAutoCRM');

// Get module settings
$settings = ModuleAutoCRM::findFirst();
if (!$settings) {
    SystemMessages::sysLogMsg($scriptName, 'Module settings not found', LOG_ERR);
    exit(1);
}

// Check API configuration (needed for both collection and sending)
if (empty($settings->api_url) || empty($settings->api_token)) {
    SystemMessages::sysLogMsg($scriptName, 'API not configured', LOG_ERR);
    exit(1);
}

SystemMessages::sysLogMsg($scriptName, 'Starting calls sync (collection phase)...', LOG_INFO);

// Note: sync_calls_enabled is checked only before API send phase (Phase 2)
// Collection from CDR always runs to keep AutoCrmCalls table up to date

/**
 * Find ALL AutoCRM users involved in call
 * Logic:
 * - Skip is_app records (queues, IVR)
 * - Skip internal calls (both src and dst are CRM users with short extensions)
 * - Record only ANSWERED calls (billsec > 0)
 * - If anyone answered in linkedid, skip all missed calls
 *
 * @param array $callData Call data with 'rows' array
 * @return array Array of ['user' => AutoCrmUsers, 'foundIn' => 'src'|'dst', 'foundNumber' => string, 'maxBillsec' => int]
 */
function findAllCrmUsers(array $callData): array
{
    $usersCdrs = []; // userId => ['user' => ..., 'foundIn' => ..., 'foundNumber' => ..., 'maxBillsec' => int, 'cdrUniqueid' => ..., 'answerTime' => ..., 'recordingFile' => ...]
    $hasAnyAnswered = false;

    foreach ($callData['rows'] as $cdr) {
        // Skip app records (queues, IVR, etc.)
        $isApp = (int)($cdr['is_app'] ?? 0) === 1;
        if ($isApp) {
            continue;
        }

        $srcNum = $cdr['src_num'] ?? '';
        $dstNum = $cdr['dst_num'] ?? '';
        $billsec = (int)($cdr['billsec'] ?? 0);
        $cdrUniqueid = $cdr['UNIQUEID'] ?? '';
        $answerTime = $cdr['answer'] ?? '';
        $recordingFile = $cdr['recordingfile'] ?? '';

        $srcUser = !empty($srcNum) ? AutoCrmUsers::findByAnyNumber($srcNum) : null;
        $dstUser = !empty($dstNum) ? AutoCrmUsers::findByAnyNumber($dstNum) : null;

        // Skip internal calls (both parties are CRM users with short extensions)
        if ($srcUser && $dstUser && strlen($srcNum) <= 4 && strlen($dstNum) <= 4) {
            continue;
        }

        // Track if any call was answered
        if ($billsec > 0) {
            $hasAnyAnswered = true;
        }

        // For incoming calls: collect CDR for dst user
        if ($dstUser) {
            $userId = $dstUser->id;
            if (!isset($usersCdrs[$userId])) {
                $usersCdrs[$userId] = [
                    'user' => $dstUser,
                    'foundIn' => 'dst',
                    'foundNumber' => $dstNum,
                    'maxBillsec' => 0,
                    'cdrUniqueid' => $cdrUniqueid,
                    'answerTime' => '',
                    'recordingFile' => ''
                ];
            }
            // Update with CDR that has highest billsec (the answered one)
            // This CDR has the recording file for this specific user's conversation
            if ($billsec > $usersCdrs[$userId]['maxBillsec']) {
                $usersCdrs[$userId]['maxBillsec'] = $billsec;
                $usersCdrs[$userId]['cdrUniqueid'] = $cdrUniqueid;
                $usersCdrs[$userId]['answerTime'] = $answerTime;
                $usersCdrs[$userId]['recordingFile'] = $recordingFile;
            }
        }

        // For outgoing calls from mobile: collect CDR for src user
        if ($srcUser && !$dstUser) {
            $userId = $srcUser->id;
            if (!isset($usersCdrs[$userId])) {
                $usersCdrs[$userId] = [
                    'user' => $srcUser,
                    'foundIn' => 'src',
                    'foundNumber' => $srcNum,
                    'maxBillsec' => 0,
                    'cdrUniqueid' => $cdrUniqueid,
                    'answerTime' => '',
                    'recordingFile' => ''
                ];
            }
            // Update with CDR that has highest billsec (the answered one)
            // This CDR has the recording file for this specific user's conversation
            if ($billsec > $usersCdrs[$userId]['maxBillsec']) {
                $usersCdrs[$userId]['maxBillsec'] = $billsec;
                $usersCdrs[$userId]['cdrUniqueid'] = $cdrUniqueid;
                $usersCdrs[$userId]['answerTime'] = $answerTime;
                $usersCdrs[$userId]['recordingFile'] = $recordingFile;
            }
        }
    }

    // If anyone answered, filter out users who didn't answer (missed)
    if ($hasAnyAnswered) {
        $usersCdrs = array_filter($usersCdrs, function($data) {
            return $data['maxBillsec'] > 0;
        });
    }

    return array_values($usersCdrs);
}

/**
 * Determine call direction based on CRM user position in call
 *
 * @param array $callData Call data from HistoryParser
 * @param string $foundIn Where CRM user was found ('src' or 'dst')
 * @param string $foundNumber The number that matched CRM user
 * @return string 'incoming' or 'outgoing'
 */
function determineDirection(array $callData, string $foundIn, string $foundNumber): string
{
    // If CRM user found in src_num - it's outgoing (employee calls client)
    // This handles both internal extension and mobile phone calls
    if ($foundIn === 'src') {
        return AutoCrmCalls::DIRECTION_OUTGOING;
    }

    // If CRM user found in dst_num - it's incoming (client calls employee)
    return AutoCrmCalls::DIRECTION_INCOMING;
}

/**
 * Get client phone number (external number, not internal)
 *
 * @param array $callData
 * @param int $typeCall
 * @return string
 */
function getClientPhone(array $callData, int $typeCall): string
{
    $firstRow = $callData['rows'][0] ?? [];

    if ($typeCall === HistoryParser::CALL_TYPE_INCOMING || $typeCall === HistoryParser::CALL_TYPE_MISSED) {
        // Incoming: client is src_num
        return $firstRow['src_num'] ?? '';
    } else {
        // Outgoing: client is dst_num (find first external number)
        foreach ($callData['rows'] as $cdr) {
            $dstNum = $cdr['dst_num'] ?? '';
            // External number is longer than 4 digits
            if (strlen($dstNum) > 4) {
                return $dstNum;
            }
        }
        return $firstRow['dst_num'] ?? '';
    }
}

/**
 * Get employee number who answered the call
 * For incoming calls - find dst_num from ANSWERED CDR record
 * For outgoing calls - find src_num (internal extension)
 *
 * @param array $callData
 * @param int $typeCall
 * @return string
 */
function getEmployeeNumber(array $callData, int $typeCall): string
{
    // For answered calls - find the CDR with billsec > 0 (actual conversation)
    if ($callData['answered'] === 1) {
        foreach ($callData['rows'] as $cdr) {
            $billsec = (int)($cdr['billsec'] ?? 0);
            if ($billsec > 0) {
                if ($typeCall === HistoryParser::CALL_TYPE_INCOMING) {
                    // Incoming answered: employee is dst_num
                    return $cdr['dst_num'] ?? '';
                } else {
                    // Outgoing answered: employee is src_num
                    return $cdr['src_num'] ?? '';
                }
            }
        }
    }

    // For missed/unanswered - find first internal number
    foreach ($callData['rows'] as $cdr) {
        if ($typeCall === HistoryParser::CALL_TYPE_INCOMING || $typeCall === HistoryParser::CALL_TYPE_MISSED) {
            $dstNum = $cdr['dst_num'] ?? '';
            // Internal number is 3-4 digits and not a queue
            if (strlen($dstNum) <= 4 && strpos($cdr['dst_chan'] ?? '', 'PJSIP/') !== false) {
                return $dstNum;
            }
        } else {
            $srcNum = $cdr['src_num'] ?? '';
            if (strlen($srcNum) <= 4) {
                return $srcNum;
            }
        }
    }

    return '';
}

/**
 * Build record URL
 *
 * @param string $recordingFile Full path to recording file
 * @param string $pbxHost PBX host (e.g. miko.mlgcorp.ru)
 * @return string
 */
function buildRecordUrl(string $recordingFile, string $pbxHost): string
{
    if (empty($recordingFile) || empty($pbxHost)) {
        return '';
    }

    // Build URL using ModuleAutoCRM records endpoint with full path
    $pbxHost = rtrim($pbxHost, '/');
    if (strpos($pbxHost, 'http') !== 0) {
        $pbxHost = 'https://' . $pbxHost;
    }
    return "{$pbxHost}/pbxcore/api/modules/ModuleAutoCRM/records?view=" . urlencode($recordingFile);
}

try {
    // Get offset from settings or calculate for last 10 linkedids
    $offset = (int)$settings->cdr_offset;
    if ($offset <= 0) {
        $offset = HistoryParser::getOffsetForLastRecords(10);
        SystemMessages::sysLogMsg($scriptName, "Calculated initial offset: {$offset}", LOG_INFO);
    }

    $minOffset = HistoryParser::getMinCdrId();
    $offset = max($minOffset, $offset);

    SystemMessages::sysLogMsg($scriptName, "Using offset: {$offset}", LOG_INFO);

    // Get history data
    $historyData = HistoryParser::getHistoryData($offset, HistoryParser::LIMIT_CDR);

    $processed = 0;
    $skipped = 0;
    $queued = 0;
    $errors = 0;

    if (empty($historyData)) {
        SystemMessages::sysLogMsg($scriptName, 'No new calls to collect from CDR', LOG_INFO);
    } else {
        foreach ($historyData as $linkedId => $callData) {
            $processed++;

            // Skip internal calls
            if ($callData['typeCall'] === HistoryParser::CALL_TYPE_INNER) {
                $skipped++;
                continue;
            }

            // Find ALL CRM users involved in this call
            $crmUsersData = findAllCrmUsers($callData);
            if (empty($crmUsersData)) {
                // No CRM user involved - skip
                $skipped++;
                continue;
            }

            // Get client phone (external number)
            $typeCallForHelpers = ($callData['typeCall'] === HistoryParser::CALL_TYPE_OUTGOING)
                ? HistoryParser::CALL_TYPE_OUTGOING
                : HistoryParser::CALL_TYPE_INCOMING;
            $clientPhone = getClientPhone($callData, $typeCallForHelpers);

            // Create a record for EACH CRM user involved
            foreach ($crmUsersData as $crmUserData) {
                $crmUser = $crmUserData['user'];
                $foundIn = $crmUserData['foundIn'];
                $foundNumber = $crmUserData['foundNumber'];
                $maxBillsec = $crmUserData['maxBillsec'] ?? 0;
                $cdrUniqueid = $crmUserData['cdrUniqueid'] ?? '';
                $answerTime = $crmUserData['answerTime'] ?? '';

                // Get recording file specific to THIS user's conversation segment
                // This ensures each user gets their own recording, not a shared one
                $userRecordingFile = $crmUserData['recordingFile'] ?? '';
                $userRecordUrl = buildRecordUrl($userRecordingFile, $settings->pbx_host);

                // Use real CDR UNIQUEID, fallback to generated if not available
                $employeeNumber = $crmUser->asterisk_extension ?: $crmUser->work_phone ?: $crmUser->phone;
                $uniqueid = !empty($cdrUniqueid) ? $cdrUniqueid : ($linkedId . '_' . $employeeNumber);
                $legacyUniqueid = $linkedId . '_' . $employeeNumber; // Old format for backwards compatibility

                // Check if already synced (check both new and old uniqueid formats)
                if (AutoCrmCalls::isAlreadySynced($uniqueid) || AutoCrmCalls::isAlreadySynced($legacyUniqueid)) {
                    $skipped++;
                    continue;
                }

                // Determine direction based on where CRM user was found
                $direction = determineDirection($callData, $foundIn, $foundNumber);

                // Determine call status for this specific user based on max billsec across all their CDRs
                // If any CDR has billsec > 0, the user answered the call
                if ($maxBillsec > 0) {
                    $callStatus = AutoCrmCalls::CALL_STATUS_ANSWERED;
                } else {
                    $callStatus = AutoCrmCalls::CALL_STATUS_MISSED;
                }

                // Set from/to numbers based on direction
                if ($direction === AutoCrmCalls::DIRECTION_INCOMING) {
                    $fromNumber = $clientPhone;
                    $toNumber = $employeeNumber;
                } else {
                    $fromNumber = $employeeNumber;
                    $toNumber = $clientPhone;
                }

                // Skip calls with empty from_number or to_number (invalid CDR data)
                if (empty($fromNumber) || empty($toNumber)) {
                    $skipped++;
                    SystemMessages::sysLogMsg(
                        $scriptName,
                        "Skipping call with empty number: linkedid={$linkedId}, from={$fromNumber}, to={$toNumber}",
                        LOG_DEBUG
                    );
                    continue;
                }

                // Create call record
                $call = new AutoCrmCalls();
                $call->uniqueid = $uniqueid;
                $call->linkedid = $linkedId;
                $call->call_datetime = $callData['q_start'];
                $call->call_datetime_end = $callData['q_endtime'];
                $call->from_number = $fromNumber;
                $call->to_number = $toNumber;
                $call->call_status = $callStatus;
                $call->direction = $direction;
                $call->record_path = $userRecordingFile;
                $call->record_url = $userRecordUrl;
                $call->sync_status = AutoCrmCalls::STATUS_PENDING;

                if ($call->save()) {
                    $queued++;
                    SystemMessages::sysLogMsg(
                        $scriptName,
                        "Queued call: linkedid={$linkedId}, status={$callStatus}, direction={$direction}, user={$crmUser->person}",
                        LOG_DEBUG
                    );
                } else {
                    $errors++;
                    $errorMsg = implode(', ', $call->getMessages());
                    SystemMessages::sysLogMsg($scriptName, "Error saving call {$linkedId}: {$errorMsg}", LOG_WARNING);
                }
            }
        }
    } // end if has historyData

    // Save new offset
    $settings->cdr_offset = $offset;
    $settings->save();

    SystemMessages::sysLogMsg(
        $scriptName,
        "Calls collection completed: processed={$processed}, queued={$queued}, skipped={$skipped}, errors={$errors}, new_offset={$offset}",
        LOG_INFO
    );

} catch (\Exception $e) {
    SystemMessages::sysLogMsg($scriptName, 'Error in collection phase: ' . $e->getMessage(), LOG_ERR);
    exit(1);
}

// ============================================
// PHASE 2: Send pending calls to AutoCRM API
// ============================================

// CRITICAL: Only send if sync_calls_enabled = '1'
if ($settings->sync_calls_enabled !== '1') {
    SystemMessages::sysLogMsg($scriptName, 'API sending is DISABLED (sync_calls_enabled != 1), skipping send phase', LOG_INFO);
    exit(0);
}

SystemMessages::sysLogMsg($scriptName, 'Starting API send phase...', LOG_INFO);

try {
    $client = new AutoCrmApiClient();

    // Build users cache for quick lookup by extension
    $usersCache = buildUsersCacheByExtension();

    $sent = 0;
    $sendErrors = 0;
    $sendSkipped = 0;

    // Process pending calls
    $pendingCalls = AutoCrmCalls::getPendingCalls(100);
    SystemMessages::sysLogMsg($scriptName, 'Found ' . count($pendingCalls) . ' pending calls to send', LOG_INFO);

    foreach ($pendingCalls as $call) {
        $result = sendCallToApi($call, $client, $usersCache, $settings);

        switch ($result) {
            case 'sent':
                $sent++;
                break;
            case 'error':
                $sendErrors++;
                break;
            case 'skipped':
                $sendSkipped++;
                break;
        }
    }

    // Retry failed calls (up to 3 attempts)
    $failedCalls = AutoCrmCalls::getFailedCalls(3, 50);
    if (count($failedCalls) > 0) {
        SystemMessages::sysLogMsg($scriptName, 'Retrying ' . count($failedCalls) . ' failed calls', LOG_INFO);

        foreach ($failedCalls as $call) {
            $result = sendCallToApi($call, $client, $usersCache, $settings);

            switch ($result) {
                case 'sent':
                    $sent++;
                    break;
                case 'error':
                    $sendErrors++;
                    break;
                case 'skipped':
                    $sendSkipped++;
                    break;
            }
        }
    }

    SystemMessages::sysLogMsg(
        $scriptName,
        "API send completed: sent={$sent}, errors={$sendErrors}, skipped={$sendSkipped}",
        LOG_INFO
    );

} catch (AutoCrmApiException $e) {
    SystemMessages::sysLogMsg($scriptName, 'API error in send phase: ' . $e->getMessage(), LOG_ERR);
    exit(1);
} catch (\Exception $e) {
    SystemMessages::sysLogMsg($scriptName, 'Error in send phase: ' . $e->getMessage(), LOG_ERR);
    exit(1);
}

exit(0);

/**
 * Build users cache indexed by extension for quick lookup
 *
 * @return array [extension => AutoCrmUsers]
 */
function buildUsersCacheByExtension(): array
{
    $cache = [];
    $users = AutoCrmUsers::getActiveUsers();

    foreach ($users as $user) {
        if (!empty($user->asterisk_extension)) {
            $cache[$user->asterisk_extension] = $user;
        }
    }

    return $cache;
}

/**
 * Send single call to AutoCRM API
 *
 * @param AutoCrmCalls $call
 * @param AutoCrmApiClient $client
 * @param array $usersCache
 * @param ModuleAutoCRM $settings
 * @return string 'sent'|'error'|'skipped'
 */
function sendCallToApi(
    AutoCrmCalls $call,
    AutoCrmApiClient $client,
    array $usersCache,
    ModuleAutoCRM $settings
): string {
    global $scriptName, $logger;

    $logger->writeInfo("=== Starting upload for call ID: {$call->id} ===");
    $logger->writeInfo([
        'uniqueid' => $call->uniqueid,
        'linkedid' => $call->linkedid,
        'direction' => $call->direction,
        'call_status' => $call->call_status,
        'sync_status' => $call->sync_status,
        'from_number' => $call->from_number,
        'to_number' => $call->to_number,
    ], 'Call data');

    // Find user by to_number (for incoming) or from_number (for outgoing)
    $extension = ($call->direction === AutoCrmCalls::DIRECTION_INCOMING)
        ? $call->to_number
        : $call->from_number;

    $logger->writeInfo("Searching CRM user by extension: {$extension}");

    if (!isset($usersCache[$extension])) {
        $error = "No CRM user for extension {$extension}";
        $logger->writeError($error);
        $call->markAsError($error);
        return 'skipped';
    }

    $user = $usersCache[$extension];
    $logger->writeInfo([
        'crm_user_id' => $user->crm_user_id,
        'person' => $user->person,
        'salon' => $user->salon,
    ], 'CRM user found');

    // Get autosalon_id from user (primary salon)
    $autosalonId = $user->getFirstAutosalonId();
    if ($autosalonId === null) {
        $autosalonId = $settings->autosalon_id;
    }

    if (empty($autosalonId)) {
        $error = "No autosalon for user {$user->crm_user_id}";
        $logger->writeError($error);
        $call->markAsError($error);
        return 'skipped';
    }
    $logger->writeInfo("Autosalon ID: {$autosalonId}");

    // Map call status: missed -> new, answered -> answered
    $apiStatus = ($call->call_status === AutoCrmCalls::CALL_STATUS_MISSED)
        ? 'new'
        : $call->call_status;
    $logger->writeInfo("API status: {$apiStatus} (from {$call->call_status})");

    // Build record URL with https
    $recordUrl = '';
    if (!empty($call->record_url)) {
        $recordUrl = $call->record_url;
        if (strpos($recordUrl, 'http://') === 0) {
            $recordUrl = 'https://' . substr($recordUrl, 7);
        } elseif (strpos($recordUrl, 'https://') !== 0 && strpos($recordUrl, '//') !== 0) {
            $recordUrl = 'https://' . $recordUrl;
        }
        $logger->writeInfo("Record URL: {$recordUrl}");
    } else {
        $logger->writeInfo("No recording available");
    }

    // Prepare data for API
    $callData = [
        'entry_id'          => AutoCrmCalls::cleanIdForApi($call->linkedid),
        'call_id'           => AutoCrmCalls::cleanIdForApi($call->uniqueid),
        'from'              => $call->from_number,
        'to'                => $call->to_number,
        'datetime'          => $call->call_datetime,
        'datetime_end'      => $call->call_datetime_end,
        'status'            => $apiStatus,
        'direction'         => $call->direction,
        'user_id'           => $user->crm_user_id,
        'autosalon_id'      => $autosalonId,
    ];

    // Add record URL only if available
    if (!empty($recordUrl)) {
        $callData['record'] = $recordUrl;
    }

    $logger->writeInfo($callData, 'API payload');

    // Send to CRM
    try {
        $logger->writeInfo("Sending POST /call request...");
        $response = $client->createCall($callData);
        $logger->writeInfo($response, 'API response');

        // Extract CRM call ID from response (can be in response['id'] or response['result']['id'])
        $crmCallId = $response['id'] ?? $response['result']['id'] ?? null;
        if ($crmCallId === null) {
            throw new AutoCrmApiException('No id in API response: ' . json_encode($response));
        }

        // Mark as sent
        $call->markAsSent((int)$crmCallId);
        $logger->writeInfo("SUCCESS! CRM call ID: {$crmCallId}");

        return 'sent';

    } catch (AutoCrmApiException $e) {
        $error = $e->getMessage();
        $logger->writeError("API error: {$error}");
        $call->markAsError($error);
        return 'error';
    }
}
