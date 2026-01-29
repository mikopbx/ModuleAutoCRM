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

return [
    'repModuleAutoCRM'         => 'AutoCRM Module - %repesent%',
    'mo_ModuleModuleAutoCRM'   => 'AutoCRM Integration',
    'BreadcrumbModuleAutoCRM'  => 'AutoCRM Integration',
    'SubHeaderModuleAutoCRM'   => 'Synchronize calls and users with AutoCRM',

    // Tabs
    'module_autocrm_TabSettings'    => 'Settings',
    'module_autocrm_TabUsers'       => 'Users',
    'module_autocrm_TabSalons'      => 'Autosalons',
    'module_autocrm_TabCalls'       => 'Calls',

    // Settings - API
    'module_autocrm_ApiSettings'    => 'AutoCRM API Settings',
    'module_autocrm_ApiUrl'         => 'AutoCRM Server Address',
    'module_autocrm_ApiUrlHint'     => 'Example: test.autocrm.ru',
    'module_autocrm_ApiToken'       => 'Authorization Token',
    'module_autocrm_Autosalon'      => 'Default Autosalon',
    'module_autocrm_AutosalonHint'  => 'Used when user has no autosalon assigned',

    // Settings - PBX
    'module_autocrm_PbxSettings'    => 'PBX Settings',
    'module_autocrm_PbxProtocol'    => 'Protocol',
    'module_autocrm_PbxHost'        => 'Public PBX Address',
    'module_autocrm_PbxHostHint'    => 'Used to generate links to call recordings',

    // Settings - Sync
    'module_autocrm_SyncSettings'       => 'Synchronization Settings',
    'module_autocrm_SyncUsersEnabled'   => 'Sync users (daily at 03:00)',
    'module_autocrm_SyncCallsEnabled'   => 'Upload calls to CRM (every minute)',
    'module_autocrm_CdrOffset'          => 'CDR Offset',
    'module_autocrm_CdrOffsetHint'      => 'ID of last processed CDR record',

    // Users tab
    'module_autocrm_UsersTitle'     => 'AutoCRM Users',
    'module_autocrm_UsersInfo'      => 'Total users',
    'module_autocrm_Active'         => 'active',
    'module_autocrm_UserPerson'     => 'Name',
    'module_autocrm_UserPhone'      => 'Mobile',
    'module_autocrm_UserWorkPhone'  => 'Work Phone',
    'module_autocrm_UserExtension'  => 'Extension',
    'module_autocrm_UserSalons'     => 'Autosalons',
    'module_autocrm_UserStatus'     => 'Status',
    'module_autocrm_StatusActive'   => 'Active',
    'module_autocrm_StatusFired'    => 'Fired',

    // Salons tab
    'module_autocrm_SalonsTitle'    => 'Autosalons',
    'module_autocrm_SalonsInfo'     => 'Total autosalons',
    'module_autocrm_SalonName'      => 'Name',
    'module_autocrm_SalonAddress'   => 'Address',
    'module_autocrm_SalonPhone'     => 'Phone',
    'module_autocrm_LastSync'       => 'Last Sync',

    // Calls tab
    'module_autocrm_CallsTitle'     => 'Calls Sync History',
    'module_autocrm_CallDate'       => 'Date/Time',
    'module_autocrm_CallFrom'       => 'From',
    'module_autocrm_CallTo'         => 'To',
    'module_autocrm_CallStatus'     => 'Call Status',
    'module_autocrm_SyncStatus'     => 'Sync Status',
    'module_autocrm_SyncError'      => 'Error',

    // Stats
    'module_autocrm_StatsTotal'     => 'Total',
    'module_autocrm_StatsSent'      => 'Sent',
    'module_autocrm_StatsPending'   => 'Pending',
    'module_autocrm_StatsError'     => 'Errors',

    // Sync statuses
    'module_autocrm_SyncStatusSent'     => 'Sent',
    'module_autocrm_SyncStatusPending'  => 'Pending',
    'module_autocrm_SyncStatusError'    => 'Error',

    // Call statuses
    'module_autocrm_CallStatusAnswered' => 'Answered',
    'module_autocrm_CallStatusMissed'   => 'Missed',

    // Buttons
    'module_autocrm_SyncNow'        => 'Sync Now',
    'module_autocrm_ShowRecords'    => 'Show',

    // Validation
    'module_autocrm_ValidateApiUrlEmpty'    => 'Please enter API URL',
    'module_autocrm_ValidateApiTokenEmpty'  => 'Please enter authorization token',
];
