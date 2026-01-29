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
use Modules\ModuleAutoCRM\Models\AutoCrmUsers;

/**
 * Service for synchronizing users from AutoCRM with detailed logging
 *
 * @package Modules\ModuleAutoCRM\Lib
 */
class UserSyncer
{
    /** @var Logger */
    private Logger $logger;

    /** @var AutoCrmApiClient|null */
    private ?AutoCrmApiClient $apiClient = null;

    /** @var ModuleAutoCRM|null */
    private ?ModuleAutoCRM $settings = null;

    /** @var array Sync statistics */
    private array $stats = [
        'total' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new Logger('UserSyncer', 'ModuleAutoCRM');
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
     * Check if sync is enabled
     *
     * @return bool
     */
    public function isSyncEnabled(): bool
    {
        if ($this->settings === null) {
            $this->settings = ModuleAutoCRM::findFirst();
        }
        return $this->settings !== null && $this->settings->sync_users_enabled === '1';
    }

    /**
     * Run full user synchronization
     *
     * @return array Sync statistics
     */
    public function sync(): array
    {
        $this->logger->writeInfo('========================================');
        $this->logger->writeInfo('=== Starting user synchronization ===');
        $this->logger->writeInfo('========================================');

        $this->stats = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'deleted' => 0,
            'errors' => 0,
        ];

        // Initialize API client
        if (!$this->initApiClient()) {
            $this->logger->writeError('Failed to initialize - aborting sync');
            return $this->stats;
        }

        // Check if sync is enabled
        if ($this->settings->sync_users_enabled !== '1') {
            $this->logger->writeInfo('User sync is disabled in settings - skipping');
            return $this->stats;
        }

        // Fetch users from API
        $this->logger->writeInfo('Fetching users from AutoCRM API...');

        try {
            $users = $this->apiClient->getUsers();
            $this->stats['total'] = count($users);
            $this->logger->writeInfo("Received {$this->stats['total']} users from API");
        } catch (AutoCrmApiException $e) {
            $this->logger->writeError('API error while fetching users: ' . $e->getMessage());
            return $this->stats;
        } catch (\Exception $e) {
            $this->logger->writeError('Exception while fetching users: ' . $e->getMessage());
            return $this->stats;
        }

        if (empty($users)) {
            $this->logger->writeWarning('No users received from API');
            return $this->stats;
        }

        // Process each user and collect CRM IDs
        $syncTime = date('Y-m-d H:i:s');
        $this->logger->writeInfo("Sync timestamp: {$syncTime}");
        $this->logger->writeInfo('----------------------------------------');

        $activeCrmUserIds = [];
        foreach ($users as $index => $userData) {
            $crmUserId = (int)($userData['id'] ?? 0);
            if ($crmUserId > 0) {
                $activeCrmUserIds[] = $crmUserId;
            }
            $this->processUser($userData, $syncTime, $index + 1);
        }

        // Remove users not in API response (fired employees)
        $this->logger->writeInfo('----------------------------------------');
        $this->removeInactiveUsers($activeCrmUserIds);

        // Log summary
        $this->logger->writeInfo('----------------------------------------');
        $this->logger->writeInfo('=== User sync completed ===');
        $this->logger->writeInfo([
            'total' => $this->stats['total'],
            'created' => $this->stats['created'],
            'updated' => $this->stats['updated'],
            'skipped' => $this->stats['skipped'],
            'deleted' => $this->stats['deleted'],
            'errors' => $this->stats['errors'],
        ], 'Statistics');
        $this->logger->writeInfo('========================================');

        return $this->stats;
    }

    /**
     * Process a single user
     *
     * @param array $userData User data from API
     * @param string $syncTime Current sync timestamp
     * @param int $index User index for logging
     */
    private function processUser(array $userData, string $syncTime, int $index): void
    {
        $crmUserId = (int)($userData['id'] ?? 0);
        $userName = $userData['person'] ?? 'Unknown';

        if ($crmUserId <= 0) {
            $this->logger->writeWarning("[{$index}] Skipping user with invalid ID: " . json_encode($userData));
            $this->stats['skipped']++;
            return;
        }

        $this->logger->writeInfo("[{$index}] Processing user: {$userName} (CRM ID: {$crmUserId})");

        try {
            // Find existing or create new
            $user = AutoCrmUsers::findFirst([
                'conditions' => 'crm_user_id = :id:',
                'bind' => ['id' => $crmUserId]
            ]);

            $isNew = false;
            if (!$user) {
                $user = new AutoCrmUsers();
                $user->crm_user_id = $crmUserId;
                $user->is_local = 0;
                $isNew = true;
                $this->logger->writeInfo("  -> Creating new user record");
            } elseif ($user->is_local == 1) {
                $this->logger->writeInfo("  -> Skipping local user (is_local=1)");
                $this->stats['skipped']++;
                return;
            } else {
                $this->logger->writeInfo("  -> Updating existing user (local ID: {$user->id})");
            }

            // Log changes
            $changes = [];

            // Update fields
            $newPerson = $userData['person'] ?? '';
            if ($user->person !== $newPerson) {
                $changes['person'] = [$user->person, $newPerson];
            }
            $user->person = $newPerson;

            $user->first_name = $userData['first_name'] ?? $userData['firstname'] ?? '';
            $user->middle_name = $userData['middle_name'] ?? $userData['middlename'] ?? '';
            $user->last_name = $userData['last_name'] ?? $userData['lastname'] ?? '';

            $newSalon = !empty($userData['salon']) ? (int)$userData['salon'] : null;
            if ($user->salon != $newSalon) {
                $changes['salon'] = [$user->salon, $newSalon];
            }
            $user->salon = $newSalon;

            $newAutosalons = $userData['user_autosalons'] ?? '';
            if ($user->user_autosalons !== $newAutosalons) {
                $changes['user_autosalons'] = [$user->user_autosalons, $newAutosalons];
            }
            $user->user_autosalons = $newAutosalons;

            // Normalize phones
            $newPhone = AutoCrmUsers::normalizePhone($userData['phone'] ?? '');
            if ($user->phone !== $newPhone) {
                $changes['phone'] = [$user->phone, $newPhone];
            }
            $user->phone = $newPhone;

            $newWorkPhone = AutoCrmUsers::normalizePhone($userData['work_phone'] ?? '');
            if ($user->work_phone !== $newWorkPhone) {
                $changes['work_phone'] = [$user->work_phone, $newWorkPhone];
            }
            $user->work_phone = $newWorkPhone;

            $newExtension = $userData['asterisk_extension'] ?? '';
            if ($user->asterisk_extension !== $newExtension) {
                $changes['asterisk_extension'] = [$user->asterisk_extension, $newExtension];
            }
            $user->asterisk_extension = $newExtension;

            // Store all phone numbers as JSON for extended search
            $allPhones = [];
            if (!empty($userData['phone'])) {
                $allPhones[] = $userData['phone'];
            }
            if (!empty($userData['work_phone'])) {
                $allPhones[] = $userData['work_phone'];
            }
            if (!empty($userData['asterisk_extension'])) {
                $allPhones[] = $userData['asterisk_extension'];
            }
            $user->user_phone_numbers = json_encode($allPhones);

            // Status fields
            $newStatus = (int)($userData['status'] ?? 1);
            if ($user->status != $newStatus) {
                $statusNames = [1 => 'active', 2 => 'fired'];
                $changes['status'] = [
                    $statusNames[$user->status] ?? $user->status,
                    $statusNames[$newStatus] ?? $newStatus
                ];
            }
            $user->status = $newStatus;

            $newBlocked = (int)($userData['blocked'] ?? 0);
            if ($user->blocked != $newBlocked) {
                $changes['blocked'] = [$user->blocked, $newBlocked];
            }
            $user->blocked = $newBlocked;

            $user->last_sync = $syncTime;

            // Log changes
            if (!empty($changes) && !$isNew) {
                $this->logger->writeInfo("  -> Changes detected:");
                foreach ($changes as $field => $values) {
                    $this->logger->writeInfo("     {$field}: '{$values[0]}' -> '{$values[1]}'");
                }
            }

            // Save
            if ($user->save()) {
                if ($isNew) {
                    $this->stats['created']++;
                    $this->logger->writeInfo("  -> SUCCESS: User created (local ID: {$user->id})");
                } else {
                    $this->stats['updated']++;
                    $this->logger->writeInfo("  -> SUCCESS: User updated");
                }
            } else {
                $this->stats['errors']++;
                $errorMsg = implode(', ', $user->getMessages());
                $this->logger->writeError("  -> FAILED to save user: {$errorMsg}");
            }

        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->writeError("  -> EXCEPTION: " . $e->getMessage());
        }
    }

    /**
     * Remove users that are no longer in API response (fired employees)
     *
     * @param array $activeCrmUserIds List of active CRM user IDs from API
     */
    private function removeInactiveUsers(array $activeCrmUserIds): void
    {
        if (empty($activeCrmUserIds)) {
            $this->logger->writeWarning('No active user IDs provided - skipping removal');
            return;
        }

        $this->logger->writeInfo('Checking for inactive users to remove...');

        // Find users not in the active list (excluding local users)
        $usersToDelete = AutoCrmUsers::find([
            'conditions' => 'is_local = 0 AND crm_user_id NOT IN ({ids:array})',
            'bind' => ['ids' => $activeCrmUserIds]
        ]);

        if ($usersToDelete->count() === 0) {
            $this->logger->writeInfo('No inactive users to remove');
            return;
        }

        $this->logger->writeInfo("Found {$usersToDelete->count()} inactive users to remove");

        foreach ($usersToDelete as $user) {
            $userName = $user->person ?: "ID:{$user->crm_user_id}";
            try {
                if ($user->delete()) {
                    $this->stats['deleted']++;
                    $this->logger->writeInfo("  -> Deleted user: {$userName} (CRM ID: {$user->crm_user_id})");
                } else {
                    $this->stats['errors']++;
                    $errorMsg = implode(', ', $user->getMessages());
                    $this->logger->writeError("  -> FAILED to delete user {$userName}: {$errorMsg}");
                }
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->logger->writeError("  -> EXCEPTION deleting user {$userName}: " . $e->getMessage());
            }
        }
    }

    /**
     * Get sync statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
