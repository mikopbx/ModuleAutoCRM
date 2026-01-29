#!/usr/bin/php
<?php
/**
 * Test script for AutoCrmApiClient
 * Run on MikoPBX server: php /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoCRM/tests/test-api-client.php
 */

require_once('Globals.php');

use Modules\ModuleAutoCRM\Lib\AutoCrmApiClient;
use Modules\ModuleAutoCRM\Lib\AutoCrmApiException;
use Modules\ModuleAutoCRM\Models\ModuleAutoCRM;

echo "=== AutoCrmApiClient Test ===\n\n";

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
// Setup: Check settings exist
// ==========================================
echo "--- Setup ---\n";

$settings = ModuleAutoCRM::findFirst();
if (!$settings || empty($settings->api_url) || empty($settings->api_token)) {
    echo "ERROR: Module settings not configured.\n";
    echo "Please configure api_url and api_token in module settings before running tests.\n";
    echo "You can do this via web interface or directly in database:\n";
    echo "  sqlite3 /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoCRM/db/module.db\n";
    echo "  UPDATE m_ModuleAutoCRM SET api_url='...', api_token='...' WHERE id=1;\n";
    exit(1);
}
test("Settings configured", !empty($settings->api_url) && !empty($settings->api_token));

echo "\n";

// ==========================================
// Test 1: Client initialization
// ==========================================
echo "--- Test Client Initialization ---\n";

$client = new AutoCrmApiClient();
test("Client created", $client !== null);
test("Client configured", $client->isConfigured());
test("API URL loaded from settings", $client->getApiUrl() === $settings->api_url);
echo "  API URL: " . $client->getApiUrl() . "\n";

// Test with explicit params
$client2 = new AutoCrmApiClient('https://test.api.com', 'test_token');
test("Client with explicit params", $client2->getApiUrl() === 'https://test.api.com');

echo "\n";

// ==========================================
// Test 2: Test connection
// ==========================================
echo "--- Test Connection ---\n";

$connected = $client->testConnection();
test("Test connection", $connected, "Could not connect to API");

echo "\n";

// ==========================================
// Test 3: Get users
// ==========================================
echo "--- Test getUsers() ---\n";

try {
    $users = $client->getUsers();
    test("getUsers returns array", is_array($users));
    test("getUsers not empty", count($users) > 0);

    if (count($users) > 0) {
        $firstUser = $users[0];
        test("User has id", isset($firstUser['id']));
        test("User has person", isset($firstUser['person']));
        echo "  Found " . count($users) . " users\n";
        echo "  First user: " . ($firstUser['person'] ?? 'N/A') . " (ID: " . ($firstUser['id'] ?? 'N/A') . ")\n";
    }
} catch (AutoCrmApiException $e) {
    test("getUsers", false, $e->getMessage());
}

echo "\n";

// ==========================================
// Test 4: Get autosalons
// ==========================================
echo "--- Test getAutosalons() ---\n";

try {
    $salons = $client->getAutosalons();
    test("getAutosalons returns array", is_array($salons));
    test("getAutosalons not empty", count($salons) > 0);

    if (count($salons) > 0) {
        $firstSalon = $salons[0];
        test("Salon has id", isset($firstSalon['id']));
        test("Salon has name", isset($firstSalon['name']));
        echo "  Found " . count($salons) . " salons\n";
        foreach ($salons as $salon) {
            echo "  - " . ($salon['name'] ?? 'N/A') . " (ID: " . ($salon['id'] ?? 'N/A') . ")\n";
        }
    }
} catch (AutoCrmApiException $e) {
    test("getAutosalons", false, $e->getMessage());
}

echo "\n";

// ==========================================
// Test 5: Create call (dry run - commented out)
// ==========================================
echo "--- Test createCall() ---\n";
echo "  [SKIP] createCall test skipped to avoid creating real data\n";
echo "  Uncomment the code below to test:\n";

/*
try {
    $callData = [
        'datetime' => date('Y-m-d H:i:s'),
        'datetime_end' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
        'from' => '79091234567',
        'to' => '101',
        'status' => 'answered',
        'direction' => 'incoming',
        'autosalon_id' => 1,
    ];

    $result = $client->createCall($callData);
    test("createCall returns data", !empty($result));
    test("createCall has id", isset($result['id']));

    if (isset($result['id'])) {
        echo "  Created call ID: " . $result['id'] . "\n";

        // Test getCall
        $call = $client->getCall($result['id']);
        test("getCall returns data", !empty($call));
    }
} catch (AutoCrmApiException $e) {
    test("createCall", false, $e->getMessage());
}
*/

echo "\n";

// ==========================================
// Test 6: Error handling
// ==========================================
echo "--- Test Error Handling ---\n";

// Test with invalid credentials
$badClient = new AutoCrmApiClient('https://example.autocrm.ru/yii/api', 'invalid_token');
try {
    $badClient->getUsers();
    test("Invalid token rejected", false, "Should have thrown exception");
} catch (AutoCrmApiException $e) {
    test("Invalid token rejected", true);
    echo "  Error: " . $e->getMessage() . "\n";
}

// Test with invalid URL
$badClient2 = new AutoCrmApiClient('https://invalid.domain.test/api', 'token');
try {
    $badClient2->getUsers();
    test("Invalid URL handled", false, "Should have thrown exception");
} catch (AutoCrmApiException $e) {
    test("Invalid URL handled", true);
    echo "  Error: " . $e->getMessage() . "\n";
}

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
