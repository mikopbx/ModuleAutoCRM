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

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerCdr;

/**
 * History parser for AutoCRM module
 * Based on ModuleExtendedCDRs HistoryParser
 */
class HistoryParser
{
    public const LIMIT_CDR = 50;

    // Call types
    public const CALL_TYPE_INCOMING = 1;
    public const CALL_TYPE_OUTGOING = 2;
    public const CALL_TYPE_MISSED = 3;
    public const CALL_TYPE_INNER = 4;

    /**
     * Get CDR data via Beanstalk
     *
     * @param array $filter
     * @return array
     */
    public static function getCdr(array $filter = []): array
    {
        if (empty($filter)) {
            $filter = [
                'work_completed<>1 AND endtime<>""',
                'miko_tmp_db' => true,
                'limit' => 2000
            ];
        }
        $filter['miko_result_in_file'] = true;
        if (!isset($filter['order'])) {
            $filter['order'] = 'answer';
        }
        if (!isset($filter['columns'])) {
            $filter['columns'] = 'id,start,answer,src_num,dst_num,dst_chan,src_chan,endtime,linkedid,recordingfile,dialstatus,UNIQUEID,billsec,is_app,from_account,to_account,disposition';
        }

        $client = new BeanstalkClient(WorkerCdr::SELECT_CDR_TUBE);
        $filename = '';
        try {
            list($result, $message) = $client->sendRequest(json_encode($filter), 30);
            if ($result !== false) {
                $filename = json_decode($message, true);
            }
        } catch (\Throwable $e) {
            $filename = '';
        }

        $resultData = [];
        if (is_string($filename) && file_exists($filename)) {
            try {
                $resultData = json_decode(file_get_contents($filename), true);
            } catch (\Throwable $e) {
                SystemMessages::sysLogMsg('HistoryParser', 'Error parse CDR response');
            }

            $di = MikoPBXVersion::getDefaultDi();
            if ($di !== null) {
                $findPath = Util::which('find');
                $downloadCacheDir = $di->getShared('config')->path('www.downloadCacheDir');
                shell_exec("$findPath -L $downloadCacheDir -samefile $filename -delete");
            }
            unlink($filename);
        }

        return $resultData;
    }

    /**
     * Get internal extension numbers
     *
     * @return array
     */
    public static function getInnerNumbers(): array
    {
        $filter = [
            "type = :extType:",
            'columns' => 'number',
            'bind' => [
                'extType' => Extensions::TYPE_SIP
            ]
        ];
        return array_column(Extensions::find($filter)->toArray(), 'number');
    }

    /**
     * Get history data grouped by linkedid
     *
     * @param int $offset Starting CDR ID
     * @param int $limit Maximum unique linkedid to process
     * @return array
     */
    public static function getHistoryData(int &$offset = 1, int $limit = 100): array
    {
        $innerNumbers = self::getInnerNumbers();

        $addQuery = [
            'linkedid IN ({linkedid:array})',
            'bind' => [
                'linkedid' => null,
            ],
            'order' => 'start,id',
        ];

        $filter = [
            'id>:id: AND linkedid <> :linkedid:',
            'bind' => [
                'id' => $offset,
                'linkedid' => '',
            ],
            'order' => 'id ASC',
            'group' => 'linkedid',
            'columns' => 'linkedid',
            'limit' => $limit,
            'add_pack_query' => $addQuery,
        ];

        $cdrData = self::getCdr($filter);
        $resultRows = [];

        if (count($cdrData) > 0) {
            $newOffset = 0;
            $minNewOffset = 0;

            foreach ($cdrData as $cdr) {
                // Convert to integers
                foreach (['id', 'is_app', 'billsec'] as $key) {
                    $cdr[$key] = intval($cdr[$key]);
                }

                if ($minNewOffset === 0) {
                    $minNewOffset = $cdr['id'];
                } else {
                    $minNewOffset = min($cdr['id'], $minNewOffset);
                }
                $newOffset = max($cdr['id'], $newOffset);

                $srcInner = self::isInnerCdr($cdr, 'src', $innerNumbers);
                $dstInner = self::isInnerCdr($cdr, 'dst', $innerNumbers);

                $linkedId = $cdr['linkedid'];

                if (!isset($resultRows[$linkedId])) {
                    // Determine call type
                    if (($srcInner && !$dstInner) || (stripos($cdr['src_chan'], 'local/') !== false
                            && stripos($cdr['dst_chan'], 'pjsip/sip') !== false)) {
                        $typeCall = self::CALL_TYPE_OUTGOING;
                    } elseif ($srcInner && ($cdr['is_app'] === 1 || $dstInner)) {
                        $typeCall = self::CALL_TYPE_INNER;
                    } else {
                        $typeCall = self::CALL_TYPE_INCOMING;
                    }

                    $resultRows[$linkedId] = [
                        'typeCall' => $typeCall,
                        'answered' => 0,
                        'q_start' => strtotime($cdr['start']),
                        'q_endtime' => strtotime($cdr['endtime']),
                        'q_answer' => strtotime($cdr['answer']),
                        'line' => '',
                        'recordingfile' => '',
                        'rows' => [],
                    ];

                    // Determine line (account)
                    if ($typeCall === self::CALL_TYPE_OUTGOING) {
                        $resultRows[$linkedId]['line'] = $cdr['to_account'];
                    } elseif ($typeCall !== self::CALL_TYPE_INNER) {
                        $resultRows[$linkedId]['line'] = $cdr['from_account'];
                    }
                } else {
                    $resultRows[$linkedId]['q_start'] = min(strtotime($cdr['start']), $resultRows[$linkedId]['q_start']);
                    $resultRows[$linkedId]['q_endtime'] = max(strtotime($cdr['endtime']), $resultRows[$linkedId]['q_endtime']);
                }

                // Check if call was answered
                if ($cdr['is_app'] !== 1 && $cdr['billsec'] > 0) {
                    $resultRows[$linkedId]['answered'] = 1;
                    $resultRows[$linkedId]['q_answer'] = max(strtotime($cdr['answer']), $resultRows[$linkedId]['q_answer']);

                    // Save recording file from answered segment
                    if (!empty($cdr['recordingfile'])) {
                        $resultRows[$linkedId]['recordingfile'] = $cdr['recordingfile'];
                    }

                    if (empty($resultRows[$linkedId]['line']) && $typeCall === self::CALL_TYPE_OUTGOING) {
                        $resultRows[$linkedId]['line'] = $cdr['to_account'];
                    }
                }

                $resultRows[$linkedId]['rows'][] = $cdr;
            }

            // Use the actual max ID processed as new offset
            // This ensures we don't re-process the same records
            $offset = $newOffset;
        }

        // Post-process: mark missed calls and format dates
        foreach ($resultRows as $linkedId => $data) {
            if ($data['answered'] === 0 && $data['typeCall'] === self::CALL_TYPE_INCOMING) {
                $resultRows[$linkedId]['typeCall'] = self::CALL_TYPE_MISSED;
            }

            // Format timestamps to datetime strings
            foreach (['q_start', 'q_endtime', 'q_answer'] as $key) {
                if (!empty($data[$key]) && is_numeric($data[$key])) {
                    $resultRows[$linkedId][$key] = date('Y-m-d H:i:s', $data[$key]);
                }
            }
        }

        return $resultRows;
    }

    /**
     * Check if CDR record is from internal number
     *
     * @param array $cdr
     * @param string $fieldName 'src' or 'dst'
     * @param array $innerNumbers
     * @return bool
     */
    public static function isInnerCdr(array $cdr, string $fieldName, array $innerNumbers): bool
    {
        $number = $cdr["{$fieldName}_num"];
        $channel = $cdr["{$fieldName}_chan"];

        if (empty($channel) && in_array($number, $innerNumbers, true)) {
            return true;
        }
        if (mb_strlen($number) > 4 && !in_array($number, $innerNumbers, true)) {
            return false;
        }
        return is_numeric($number) && strpos($channel, "/{$number}-") !== false;
    }

    /**
     * Get minimum CDR ID
     *
     * @return int
     */
    public static function getMinCdrId(): int
    {
        $filter = [
            'columns' => 'id',
            'limit' => 1,
            'order' => 'id ASC'
        ];
        $cdrData = self::getCdr($filter);
        $id = (int)($cdrData[0]['id'] ?? 0);
        if ($id > 0) {
            $id--;
        }
        return $id;
    }

    /**
     * Get maximum CDR ID
     *
     * @return int
     */
    public static function getMaxCdrId(): int
    {
        $filter = [
            'columns' => 'id',
            'limit' => 1,
            'order' => 'id DESC'
        ];
        $cdrData = self::getCdr($filter);
        return (int)($cdrData[0]['id'] ?? 0);
    }

    /**
     * Calculate offset to get last N unique linkedid records
     *
     * @param int $countLinkedIds Number of unique linkedids to capture
     * @return int
     */
    public static function getOffsetForLastRecords(int $countLinkedIds = 10): int
    {
        // Get last N unique linkedids
        $filter = [
            'columns' => 'id,linkedid',
            'order' => 'id DESC',
            'group' => 'linkedid',
            'limit' => $countLinkedIds,
        ];

        $cdrData = self::getCdr($filter);

        if (empty($cdrData)) {
            return 0;
        }

        // Find minimum ID among these linkedids
        $minId = PHP_INT_MAX;
        foreach ($cdrData as $row) {
            $minId = min($minId, (int)$row['id']);
        }

        // Return offset just before that ID
        return max(0, $minId - 1);
    }
}
