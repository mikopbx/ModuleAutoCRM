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
use Modules\ModuleAutoCRM\Models\AutoCrmSalons;

/**
 * Service for synchronizing autosalons from AutoCRM with detailed logging
 *
 * @package Modules\ModuleAutoCRM\Lib
 */
class SalonSyncer
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
        $this->logger = new Logger('SalonSyncer', 'ModuleAutoCRM');
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
     * Run full salon synchronization
     *
     * @return array Sync statistics
     */
    public function sync(): array
    {
        $this->logger->writeInfo('========================================');
        $this->logger->writeInfo('=== Starting salon synchronization ===');
        $this->logger->writeInfo('========================================');

        $this->stats = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // Initialize API client
        if (!$this->initApiClient()) {
            $this->logger->writeError('Failed to initialize - aborting sync');
            return $this->stats;
        }

        // Fetch salons from API
        $this->logger->writeInfo('Fetching autosalons from AutoCRM API...');

        try {
            $salons = $this->apiClient->getAutosalons();
            $this->stats['total'] = count($salons);
            $this->logger->writeInfo("Received {$this->stats['total']} salons from API");
        } catch (AutoCrmApiException $e) {
            $this->logger->writeError('API error while fetching salons: ' . $e->getMessage());
            return $this->stats;
        } catch (\Exception $e) {
            $this->logger->writeError('Exception while fetching salons: ' . $e->getMessage());
            return $this->stats;
        }

        if (empty($salons)) {
            $this->logger->writeWarning('No salons received from API');
            return $this->stats;
        }

        // Process each salon
        $syncTime = date('Y-m-d H:i:s');
        $this->logger->writeInfo("Sync timestamp: {$syncTime}");
        $this->logger->writeInfo('----------------------------------------');

        foreach ($salons as $index => $salonData) {
            $this->processSalon($salonData, $syncTime, $index + 1);
        }

        // Log summary
        $this->logger->writeInfo('----------------------------------------');
        $this->logger->writeInfo('=== Salon sync completed ===');
        $this->logger->writeInfo([
            'total' => $this->stats['total'],
            'created' => $this->stats['created'],
            'updated' => $this->stats['updated'],
            'skipped' => $this->stats['skipped'],
            'errors' => $this->stats['errors'],
        ], 'Statistics');
        $this->logger->writeInfo('========================================');

        return $this->stats;
    }

    /**
     * Process a single salon
     *
     * @param array $salonData Salon data from API
     * @param string $syncTime Current sync timestamp
     * @param int $index Salon index for logging
     */
    private function processSalon(array $salonData, string $syncTime, int $index): void
    {
        $crmSalonId = (int)($salonData['id'] ?? 0);
        $salonName = $salonData['name'] ?? 'Unknown';

        if ($crmSalonId <= 0) {
            $this->logger->writeWarning("[{$index}] Skipping salon with invalid ID: " . json_encode($salonData));
            $this->stats['skipped']++;
            return;
        }

        $this->logger->writeInfo("[{$index}] Processing salon: {$salonName} (CRM ID: {$crmSalonId})");

        try {
            // Find existing or create new
            $salon = AutoCrmSalons::findByCrmId($crmSalonId);

            $isNew = false;
            if (!$salon) {
                $salon = new AutoCrmSalons();
                $salon->crm_salon_id = $crmSalonId;
                $isNew = true;
                $this->logger->writeInfo("  -> Creating new salon record");
            } else {
                $this->logger->writeInfo("  -> Updating existing salon (local ID: {$salon->id})");
            }

            // Log changes
            $changes = [];

            // Update fields
            $newName = $salonData['name'] ?? '';
            if ($salon->name !== $newName) {
                $changes['name'] = [$salon->name, $newName];
            }
            $salon->name = $newName;

            $newAddress = $salonData['address'] ?? '';
            if ($salon->address !== $newAddress) {
                $changes['address'] = [$salon->address, $newAddress];
            }
            $salon->address = $newAddress;

            $newPhone = $salonData['phone'] ?? '';
            if ($salon->phone !== $newPhone) {
                $changes['phone'] = [$salon->phone, $newPhone];
            }
            $salon->phone = $newPhone;

            $salon->last_sync = $syncTime;

            // Log changes
            if (!empty($changes) && !$isNew) {
                $this->logger->writeInfo("  -> Changes detected:");
                foreach ($changes as $field => $values) {
                    $this->logger->writeInfo("     {$field}: '{$values[0]}' -> '{$values[1]}'");
                }
            }

            // Save
            if ($salon->save()) {
                if ($isNew) {
                    $this->stats['created']++;
                    $this->logger->writeInfo("  -> SUCCESS: Salon created (local ID: {$salon->id})");
                } else {
                    $this->stats['updated']++;
                    $this->logger->writeInfo("  -> SUCCESS: Salon updated");
                }
            } else {
                $this->stats['errors']++;
                $errorMsg = implode(', ', $salon->getMessages());
                $this->logger->writeError("  -> FAILED to save salon: {$errorMsg}");
            }

        } catch (\Exception $e) {
            $this->stats['errors']++;
            $this->logger->writeError("  -> EXCEPTION: " . $e->getMessage());
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
