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

namespace Modules\ModuleAutoCRM\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * AutoCRM calls sync model
 *
 * @package Modules\ModuleAutoCRM\Models
 *
 * @Indexes(
 *     [name='uniqueid', columns=['uniqueid'], type=''],
 *     [name='sync_status', columns=['sync_status'], type=''],
 *     [name='crm_call_id', columns=['crm_call_id'], type=''],
 *     [name='linkedid', columns=['linkedid'], type='']
 * )
 */
class AutoCrmCalls extends ModulesModelsBase
{
    // Sync statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_ERROR = 'error';

    // Call statuses for CRM
    public const CALL_STATUS_MISSED = 'missed';     // Missed call (red)
    public const CALL_STATUS_ANSWERED = 'answered'; // Answered call (green)

    // Call directions
    public const DIRECTION_INCOMING = 'incoming';
    public const DIRECTION_OUTGOING = 'outgoing';

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Call ID in AutoCRM (response from API)
     *
     * @Column(type="integer", nullable=true)
     */
    public ?int $crm_call_id = null;

    /**
     * UNIQUEID from MikoPBX CDR
     *
     * @Column(type="string", nullable=false)
     */
    public string $uniqueid;

    /**
     * linkedid from MikoPBX CDR (call group)
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $linkedid = '';

    /**
     * Call start datetime (yyyy-MM-dd HH:mm:ss)
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $call_datetime = '';

    /**
     * Call end datetime (yyyy-MM-dd HH:mm:ss)
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $call_datetime_end = '';

    /**
     * Source phone number
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $from_number = '';

    /**
     * Destination phone number
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $to_number = '';

    /**
     * Call status: new (missed) / answered
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $call_status = '';

    /**
     * Call direction: incoming / outgoing
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $direction = '';

    /**
     * Local path to recording file
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $record_path = '';

    /**
     * Public URL for recording
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $record_url = '';

    /**
     * Sync status: pending / sent / error
     *
     * @Column(type="string", default="pending", nullable=true)
     */
    public ?string $sync_status = self::STATUS_PENDING;

    /**
     * Error message if sync failed
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $sync_error = '';

    /**
     * Retry attempts count
     *
     * @Column(type="integer", default="0", nullable=true)
     */
    public ?int $retry_count = 0;

    /**
     * Record creation timestamp
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $created_at = '';

    /**
     * Record update timestamp
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $updated_at = '';

    /**
     * Initialize model
     */
    public function initialize(): void
    {
        $this->setSource('m_AutoCrmCalls');
        parent::initialize();
    }

    /**
     * Before create hook
     */
    public function beforeCreate(): void
    {
        $this->created_at = date('Y-m-d H:i:s');
        $this->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * Before update hook
     */
    public function beforeUpdate(): void
    {
        $this->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * Check if call already synced by UNIQUEID
     *
     * @param string $uniqueid
     * @return bool
     */
    public static function isAlreadySynced(string $uniqueid): bool
    {
        $call = self::findFirst([
            'conditions' => 'uniqueid = :uniqueid:',
            'bind' => ['uniqueid' => $uniqueid]
        ]);
        return $call !== null;
    }

    /**
     * Clean identifier for API by removing "mikopbx-" prefix
     * Used for entry_id and call_id when sending to AutoCRM API
     *
     * @param string $identifier Original identifier (linkedid or uniqueid)
     * @return string Cleaned identifier without "mikopbx-" prefix
     */
    public static function cleanIdForApi(string $identifier): string
    {
        if (strpos($identifier, 'mikopbx-') === 0) {
            return substr($identifier, 8); // Remove "mikopbx-" (8 chars)
        }
        return $identifier;
    }

    /**
     * Get pending calls for sync
     *
     * @param int $limit
     * @return \Phalcon\Mvc\Model\ResultsetInterface
     */
    public static function getPendingCalls(int $limit = 100)
    {
        return self::find([
            'conditions' => 'sync_status = :status:',
            'bind' => ['status' => self::STATUS_PENDING],
            'limit' => $limit,
            'order' => 'id ASC'
        ]);
    }

    /**
     * Get failed calls for retry
     *
     * @param int $maxRetries Maximum retry attempts
     * @param int $limit
     * @return \Phalcon\Mvc\Model\ResultsetInterface
     */
    public static function getFailedCalls(int $maxRetries = 3, int $limit = 50)
    {
        return self::find([
            'conditions' => 'sync_status = :status: AND retry_count < :max:',
            'bind' => [
                'status' => self::STATUS_ERROR,
                'max' => $maxRetries
            ],
            'limit' => $limit,
            'order' => 'id ASC'
        ]);
    }

    /**
     * Mark call as sent
     *
     * @param int $crmCallId ID from CRM response
     * @return bool
     */
    public function markAsSent(int $crmCallId): bool
    {
        $this->crm_call_id = $crmCallId;
        $this->sync_status = self::STATUS_SENT;
        $this->sync_error = '';
        return $this->save();
    }

    /**
     * Mark call as error
     *
     * @param string $error Error message
     * @return bool
     */
    public function markAsError(string $error): bool
    {
        $this->sync_status = self::STATUS_ERROR;
        $this->sync_error = $error;
        $this->retry_count++;
        return $this->save();
    }

    /**
     * Get statistics
     *
     * @return array
     */
    public static function getStats(): array
    {
        return [
            'total' => self::count(),
            'sent' => self::count(['conditions' => 'sync_status = "sent"']),
            'pending' => self::count(['conditions' => 'sync_status = "pending"']),
            'error' => self::count(['conditions' => 'sync_status = "error"']),
        ];
    }
}
