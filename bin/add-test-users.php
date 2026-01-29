#!/usr/bin/php
<?php
/**
 * Add test/local users for development and testing
 * These users are protected from sync (is_local = 1)
 */

require_once('Globals.php');

use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleAutoCRM\Models\AutoCrmUsers;

$scriptName = 'add-test-users';

// Test users with internal extensions from CDR
$testUsers = [
    [
        'crm_user_id' => 99901,
        'person' => 'Тестовый Менеджер 201',
        'first_name' => 'Тестовый',
        'last_name' => 'Менеджер',
        'asterisk_extension' => '201',
        'phone' => '9001234501',
        'salon' => 1, // Тестовый автосалон
        'user_autosalons' => '1',
    ],
    [
        'crm_user_id' => 99902,
        'person' => 'Тестовый Менеджер 202',
        'first_name' => 'Тестовый',
        'last_name' => 'Менеджер',
        'asterisk_extension' => '202',
        'phone' => '9001234502',
        'salon' => 1,
        'user_autosalons' => '1',
    ],
    [
        'crm_user_id' => 99903,
        'person' => 'Тестовый Менеджер 203',
        'first_name' => 'Тестовый',
        'last_name' => 'Менеджер',
        'asterisk_extension' => '203',
        'phone' => '9001234503',
        'salon' => 2, // Тестовый автосалон 2
        'user_autosalons' => '2',
    ],
    [
        'crm_user_id' => 99904,
        'person' => 'Тестовый Менеджер 204',
        'first_name' => 'Тестовый',
        'last_name' => 'Менеджер',
        'asterisk_extension' => '204',
        'phone' => '9001234504',
        'salon' => 2,
        'user_autosalons' => '2',
    ],
    [
        'crm_user_id' => 99905,
        'person' => 'Тестовый Менеджер 2001',
        'first_name' => 'Тестовый',
        'last_name' => 'Менеджер',
        'asterisk_extension' => '2001',
        'phone' => '9001234505',
        'salon' => 1,
        'user_autosalons' => '1,2',
    ],
];

$created = 0;
$skipped = 0;

foreach ($testUsers as $userData) {
    // Check if already exists
    $existing = AutoCrmUsers::findFirst([
        'conditions' => 'crm_user_id = :id: OR asterisk_extension = :ext:',
        'bind' => [
            'id' => $userData['crm_user_id'],
            'ext' => $userData['asterisk_extension']
        ]
    ]);

    if ($existing) {
        echo "Skipped: {$userData['person']} (ext: {$userData['asterisk_extension']}) - already exists\n";
        $skipped++;
        continue;
    }

    $user = new AutoCrmUsers();
    $user->crm_user_id = $userData['crm_user_id'];
    $user->person = $userData['person'];
    $user->first_name = $userData['first_name'];
    $user->last_name = $userData['last_name'];
    $user->asterisk_extension = $userData['asterisk_extension'];
    $user->phone = $userData['phone'];
    $user->salon = $userData['salon'];
    $user->user_autosalons = $userData['user_autosalons'];
    $user->status = 1;
    $user->blocked = 0;
    $user->is_local = 1; // Protected from sync deletion
    $user->last_sync = date('Y-m-d H:i:s');
    $user->user_phone_numbers = json_encode([$userData['phone'], $userData['asterisk_extension']]);

    if ($user->save()) {
        echo "Created: {$userData['person']} (ext: {$userData['asterisk_extension']})\n";
        $created++;
    } else {
        $errors = implode(', ', $user->getMessages());
        echo "Error creating {$userData['person']}: {$errors}\n";
    }
}

echo "\nSummary: created={$created}, skipped={$skipped}\n";

SystemMessages::sysLogMsg($scriptName, "Test users added: created={$created}, skipped={$skipped}", LOG_INFO);
