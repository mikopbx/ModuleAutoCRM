#!/usr/bin/php
<?php
/**
 * Test script for ModuleAutoCRM models
 * Run on MikoPBX server: php /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoCRM/tests/test-models.php
 */

require_once('Globals.php');

use MikoPBX\Common\Models\PbxExtensionModules;
use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;
use Modules\ModuleAutoCRM\Models\AutoCrmUsers;
use Modules\ModuleAutoCRM\Models\AutoCrmCalls;
use Modules\ModuleAutoCRM\Models\AutoCrmSalons;

echo "=== ModuleAutoCRM Models Test ===\n\n";

$passed = 0;
$failed = 0;

function test($name, $condition, $message = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $name\n";
        $passed++;
    } else {
        echo "[FAIL] $name" . ($message ? ": $message" : "") . "\n";
        $failed++;
    }
}

// ==========================================
// Test 1: ModuleAutoCRM (Settings)
// ==========================================
echo "--- Test ModuleAutoCRM ---\n";

// Create settings
$settings = ModuleAutoCRM::findFirst();
if (!$settings) {
    $settings = new ModuleAutoCRM();
}
$settings->api_url = 'https://example.autocrm.ru/yii/api';
$settings->api_token = 'test_token_12345';
$settings->autosalon_id = 1;
$settings->pbx_host = 'pbx.example.com';
$settings->sync_users_enabled = '1';
$settings->sync_calls_enabled = '1';
$settings->cdr_offset = 100;
$result = $settings->save();
test("ModuleAutoCRM: save settings", $result, implode(', ', $settings->getMessages()));

// Read settings
$settings2 = ModuleAutoCRM::findFirst();
test("ModuleAutoCRM: read api_url", $settings2->api_url === 'https://example.autocrm.ru/yii/api');
test("ModuleAutoCRM: read api_token", $settings2->api_token === 'test_token_12345');
test("ModuleAutoCRM: read autosalon_id", $settings2->autosalon_id == 1);
test("ModuleAutoCRM: read sync_users_enabled", $settings2->sync_users_enabled === '1');
test("ModuleAutoCRM: read cdr_offset", $settings2->cdr_offset == 100);

echo "\n";

// ==========================================
// Test 2: AutoCrmUsers
// ==========================================
echo "--- Test AutoCrmUsers ---\n";

// Clean up old test data
$oldUsers = AutoCrmUsers::find("crm_user_id >= 90000");
foreach ($oldUsers as $u) {
    $u->delete();
}

// Create user
$user = new AutoCrmUsers();
$user->crm_user_id = 90001;
$user->person = 'Иванов Иван Иванович';
$user->first_name = 'Иван';
$user->middle_name = 'Иванович';
$user->last_name = 'Иванов';
$user->salon = 1;
$user->phone = '9091234567';
$user->work_phone = '4951234567';
$user->asterisk_extension = '101';
$user->user_phone_numbers = json_encode(['9091234567', '4951234567', '101']);
$user->status = 1;
$user->blocked = 0;
$user->last_sync = date('Y-m-d H:i:s');
$result = $user->save();
test("AutoCrmUsers: create user", $result, implode(', ', $user->getMessages()));

// Test normalizePhone
test("AutoCrmUsers: normalizePhone +7", AutoCrmUsers::normalizePhone('+7 909 123-45-67') === '9091234567');
test("AutoCrmUsers: normalizePhone 8", AutoCrmUsers::normalizePhone('8(909)123-45-67') === '9091234567');
test("AutoCrmUsers: normalizePhone short", AutoCrmUsers::normalizePhone('101') === '101');

// Test findByPhone
$found = AutoCrmUsers::findByPhone('9091234567');
test("AutoCrmUsers: findByPhone direct", $found !== null && $found->crm_user_id == 90001);

$found = AutoCrmUsers::findByPhone('+7 909 123-45-67');
test("AutoCrmUsers: findByPhone with +7", $found !== null && $found->crm_user_id == 90001);

$found = AutoCrmUsers::findByPhone('4951234567');
test("AutoCrmUsers: findByPhone work_phone", $found !== null && $found->crm_user_id == 90001);

$found = AutoCrmUsers::findByPhone('101');
test("AutoCrmUsers: findByPhone extension", $found !== null && $found->crm_user_id == 90001);

// Test isUserPhone
test("AutoCrmUsers: isUserPhone true", AutoCrmUsers::isUserPhone('9091234567') === true);
test("AutoCrmUsers: isUserPhone false", AutoCrmUsers::isUserPhone('9999999999') === false);

// Test getActiveUsers
$active = AutoCrmUsers::getActiveUsers();
test("AutoCrmUsers: getActiveUsers count", count($active) >= 1);

// Test user_autosalons field and methods
$userMultiSalon = new AutoCrmUsers();
$userMultiSalon->crm_user_id = 90002;
$userMultiSalon->person = 'Петров Петр';
$userMultiSalon->salon = 1;
$userMultiSalon->user_autosalons = '2,4,7';
$userMultiSalon->phone = '9095555555';
$userMultiSalon->status = 1;
$userMultiSalon->save();

test("AutoCrmUsers: getFirstAutosalonId from salon (primary)", $userMultiSalon->getFirstAutosalonId() === 1);
test("AutoCrmUsers: getAutosalonIds count", count($userMultiSalon->getAutosalonIds()) === 4); // 2,4,7 + 1

// Test user with only user_autosalons field (no salon)
$userOnlyAutosalons = new AutoCrmUsers();
$userOnlyAutosalons->crm_user_id = 90003;
$userOnlyAutosalons->person = 'Сидоров Сидор';
$userOnlyAutosalons->salon = null;
$userOnlyAutosalons->user_autosalons = '3,5,8';
$userOnlyAutosalons->phone = '9096666666';
$userOnlyAutosalons->status = 1;
$userOnlyAutosalons->save();

test("AutoCrmUsers: getFirstAutosalonId fallback to user_autosalons", $userOnlyAutosalons->getFirstAutosalonId() === 3);
test("AutoCrmUsers: getAutosalonIds with only user_autosalons", count($userOnlyAutosalons->getAutosalonIds()) === 3);

echo "\n";

// ==========================================
// Test 3: AutoCrmCalls
// ==========================================
echo "--- Test AutoCrmCalls ---\n";

// Clean up old test data
$oldCalls = AutoCrmCalls::find("uniqueid LIKE 'test-%'");
foreach ($oldCalls as $c) {
    $c->delete();
}

// Create call
$call = new AutoCrmCalls();
$call->uniqueid = 'test-' . time();
$call->linkedid = 'test-linked-' . time();
$call->call_datetime = '2025-01-26 10:00:00';
$call->call_datetime_end = '2025-01-26 10:05:00';
$call->from_number = '9091234567';
$call->to_number = '101';
$call->call_status = AutoCrmCalls::CALL_STATUS_ANSWERED;
$call->direction = AutoCrmCalls::DIRECTION_INCOMING;
$call->record_path = '/storage/usbdisk1/mikopbx/astspool/monitor/2025/01/26/test.mp3';
$call->record_url = 'https://pbx.example.com/records/test.mp3';
$call->sync_status = AutoCrmCalls::STATUS_PENDING;
$result = $call->save();
test("AutoCrmCalls: create call", $result, implode(', ', $call->getMessages()));

// Check created_at auto-fill
test("AutoCrmCalls: created_at auto-fill", !empty($call->created_at));

// Test isAlreadySynced
test("AutoCrmCalls: isAlreadySynced true", AutoCrmCalls::isAlreadySynced($call->uniqueid) === true);
test("AutoCrmCalls: isAlreadySynced false", AutoCrmCalls::isAlreadySynced('non-existent-id') === false);

// Test getPendingCalls
$pending = AutoCrmCalls::getPendingCalls(10);
test("AutoCrmCalls: getPendingCalls", count($pending) >= 1);

// Test markAsSent
$call->markAsSent(12345);
test("AutoCrmCalls: markAsSent crm_call_id", $call->crm_call_id == 12345);
test("AutoCrmCalls: markAsSent status", $call->sync_status === AutoCrmCalls::STATUS_SENT);

// Create error call for retry test
$errorCall = new AutoCrmCalls();
$errorCall->uniqueid = 'test-error-' . time();
$errorCall->linkedid = 'test-linked-error-' . time();
$errorCall->call_datetime = '2025-01-26 11:00:00';
$errorCall->from_number = '9091234567';
$errorCall->to_number = '102';
$errorCall->call_status = AutoCrmCalls::CALL_STATUS_ANSWERED;
$errorCall->direction = AutoCrmCalls::DIRECTION_INCOMING;
$errorCall->save();
$errorCall->markAsError('Test error message');
test("AutoCrmCalls: markAsError status", $errorCall->sync_status === AutoCrmCalls::STATUS_ERROR);
test("AutoCrmCalls: markAsError retry_count", $errorCall->retry_count == 1);

// Test getFailedCalls
$failed_calls = AutoCrmCalls::getFailedCalls(3, 10);
test("AutoCrmCalls: getFailedCalls", count($failed_calls) >= 1);

// Test getStats
$stats = AutoCrmCalls::getStats();
test("AutoCrmCalls: getStats keys", isset($stats['total']) && isset($stats['sent']) && isset($stats['pending']) && isset($stats['error']));

echo "\n";

// ==========================================
// Test 4: AutoCrmSalons
// ==========================================
echo "--- Test AutoCrmSalons ---\n";

// Clean up old test data
$oldSalons = AutoCrmSalons::find("crm_salon_id >= 90000");
foreach ($oldSalons as $s) {
    $s->delete();
}

// Create salons
$salon1 = new AutoCrmSalons();
$salon1->crm_salon_id = 90001;
$salon1->name = 'Тестовый автосалон 1';
$salon1->address = 'г. Москва, ул. Тестовая, 1';
$salon1->phone = '4951111111';
$salon1->last_sync = date('Y-m-d H:i:s');
$result = $salon1->save();
test("AutoCrmSalons: create salon 1", $result, implode(', ', $salon1->getMessages()));

$salon2 = new AutoCrmSalons();
$salon2->crm_salon_id = 90002;
$salon2->name = 'Тестовый автосалон 2';
$salon2->address = 'г. Москва, ул. Тестовая, 2';
$salon2->phone = '4952222222';
$salon2->last_sync = date('Y-m-d H:i:s');
$result = $salon2->save();
test("AutoCrmSalons: create salon 2", $result, implode(', ', $salon2->getMessages()));

// Test findByCrmId
$found = AutoCrmSalons::findByCrmId(90001);
test("AutoCrmSalons: findByCrmId", $found !== null && $found->name === 'Тестовый автосалон 1');

// Test getListForDropdown
$list = AutoCrmSalons::getListForDropdown();
test("AutoCrmSalons: getListForDropdown count", count($list) >= 2);
test("AutoCrmSalons: getListForDropdown content", isset($list[90001]) && $list[90001] === 'Тестовый автосалон 1');

echo "\n";

// ==========================================
// Summary
// ==========================================
echo "=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total: " . ($passed + $failed) . "\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
