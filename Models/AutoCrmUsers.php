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
 * AutoCRM users cache model
 *
 * @package Modules\ModuleAutoCRM\Models
 *
 * @Indexes(
 *     [name='crm_user_id', columns=['crm_user_id'], type=''],
 *     [name='phone', columns=['phone'], type=''],
 *     [name='work_phone', columns=['work_phone'], type=''],
 *     [name='asterisk_extension', columns=['asterisk_extension'], type='']
 * )
 */
class AutoCrmUsers extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * User ID in AutoCRM
     *
     * @Column(type="integer", nullable=false)
     */
    public int $crm_user_id;

    /**
     * Full name (ФИО)
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $person = '';

    /**
     * First name
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $first_name = '';

    /**
     * Middle name
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $middle_name = '';

    /**
     * Last name
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $last_name = '';

    /**
     * Primary autosalon ID
     *
     * @Column(type="integer", nullable=true)
     */
    public ?int $salon = null;

    /**
     * List of autosalon IDs (comma-separated, e.g. "1,2,4")
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $user_autosalons = '';

    /**
     * Mobile phone (normalized)
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $phone = '';

    /**
     * Work phone (normalized)
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $work_phone = '';

    /**
     * Asterisk extension number
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $asterisk_extension = '';

    /**
     * All phone numbers (JSON array)
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $user_phone_numbers = '';

    /**
     * Status: 1 - active, 2 - fired
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public ?int $status = 1;

    /**
     * Blocked flag (0/1)
     *
     * @Column(type="integer", default="0", nullable=true)
     */
    public ?int $blocked = 0;

    /**
     * Local/test user flag - not synced from CRM (0/1)
     * These users are protected from deletion during sync
     *
     * @Column(type="integer", default="0", nullable=true)
     */
    public ?int $is_local = 0;

    /**
     * Last sync timestamp
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $last_sync = '';

    /**
     * Initialize model
     */
    public function initialize(): void
    {
        $this->setSource('m_AutoCrmUsers');
        parent::initialize();
    }

    /**
     * Find user by phone number
     *
     * @param string $phone Phone number to search
     * @return AutoCrmUsers|null
     */
    public static function findByPhone(string $phone): ?AutoCrmUsers
    {
        $normalized = self::normalizePhone($phone);
        if (empty($normalized)) {
            return null;
        }

        // Search in main phone fields
        $user = self::findFirst([
            'conditions' => 'phone = :phone: OR work_phone = :phone: OR asterisk_extension = :phone:',
            'bind' => ['phone' => $normalized]
        ]);

        if ($user) {
            return $user;
        }

        // Search in user_phone_numbers JSON field
        $users = self::find([
            'conditions' => 'user_phone_numbers LIKE :phone:',
            'bind' => ['phone' => '%' . $normalized . '%']
        ]);

        foreach ($users as $user) {
            $phones = json_decode($user->user_phone_numbers, true);
            if (is_array($phones)) {
                foreach ($phones as $p) {
                    if (self::normalizePhone($p) === $normalized) {
                        return $user;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if phone belongs to any CRM user
     *
     * @param string $phone Phone number
     * @return bool
     */
    public static function isUserPhone(string $phone): bool
    {
        return self::findByPhone($phone) !== null;
    }

    /**
     * Normalize phone number
     * Returns last 10 digits (right-aligned)
     * 74952290001, +74952290001, 84952290001 -> 4952290001
     *
     * @param string $phone
     * @return string
     */
    public static function normalizePhone(string $phone): string
    {
        // Remove all non-digit characters
        $phone = preg_replace('/[^\d]/', '', $phone);

        // Return last 10 digits for long numbers
        if (strlen($phone) > 10) {
            $phone = substr($phone, -10);
        }

        return $phone;
    }

    /**
     * Get all active users
     *
     * @return \Phalcon\Mvc\Model\ResultsetInterface
     */
    public static function getActiveUsers()
    {
        return self::find([
            'conditions' => 'status = 1 AND blocked = 0'
        ]);
    }

    /**
     * Get first autosalon ID for this user
     * Returns salon field (primary), or first ID from user_autosalons, or null
     *
     * @return int|null
     */
    public function getFirstAutosalonId(): ?int
    {
        // First try salon field (primary autosalon)
        if (!empty($this->salon)) {
            return $this->salon;
        }

        // Fallback to first from user_autosalons (comma-separated list)
        if (!empty($this->user_autosalons)) {
            $ids = explode(',', $this->user_autosalons);
            $firstId = trim($ids[0]);
            if (is_numeric($firstId)) {
                return (int)$firstId;
            }
        }

        return null;
    }

    /**
     * Get all autosalon IDs for this user
     *
     * @return array
     */
    public function getAutosalonIds(): array
    {
        $ids = [];

        if (!empty($this->user_autosalons)) {
            $parts = explode(',', $this->user_autosalons);
            foreach ($parts as $part) {
                $id = trim($part);
                if (is_numeric($id)) {
                    $ids[] = (int)$id;
                }
            }
        }

        // Add salon if not already in list
        if (!empty($this->salon) && !in_array($this->salon, $ids)) {
            $ids[] = $this->salon;
        }

        return $ids;
    }

    /**
     * Find user by internal extension number
     *
     * @param string $extension Extension number (e.g. "201")
     * @return AutoCrmUsers|null
     */
    public static function findByExtension(string $extension): ?AutoCrmUsers
    {
        if (empty($extension)) {
            return null;
        }

        return self::findFirst([
            'conditions' => 'asterisk_extension = :ext:',
            'bind' => ['ext' => $extension]
        ]);
    }

    /**
     * Find user by any phone number (phone, work_phone, extension)
     *
     * @param string $number Phone or extension number
     * @return AutoCrmUsers|null
     */
    public static function findByAnyNumber(string $number): ?AutoCrmUsers
    {
        if (empty($number)) {
            return null;
        }

        // First try as extension (short number)
        if (strlen($number) <= 4) {
            $user = self::findByExtension($number);
            if ($user) {
                return $user;
            }
        }

        // Then try as phone number
        return self::findByPhone($number);
    }
}
