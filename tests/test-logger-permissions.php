#!/usr/bin/php
<?php
/**
 * Test script for Logger permissions
 * Verifies that log files and directories are created with correct ownership (www:www)
 * Run on MikoPBX server: php /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoCRM/tests/test-logger-permissions.php
 */

require_once('Globals.php');

use MikoPBX\Core\System\System;
use Modules\ModuleAutoCRM\Lib\Logger;

echo "=== ModuleAutoCRM Logger Permissions Test ===\n\n";

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

function getOwner($path) {
    if (!file_exists($path)) {
        return null;
    }
    $stat = stat($path);
    if ($stat === false) {
        return null;
    }
    $userInfo = posix_getpwuid($stat['uid']);
    return $userInfo ? $userInfo['name'] : $stat['uid'];
}

function getGroup($path) {
    if (!file_exists($path)) {
        return null;
    }
    $stat = stat($path);
    if ($stat === false) {
        return null;
    }
    $groupInfo = posix_getgrgid($stat['gid']);
    return $groupInfo ? $groupInfo['name'] : $stat['gid'];
}

// ==========================================
// Test 1: Log directory permissions
// ==========================================
echo "--- Test Log Directory ---\n";

$logDir = System::getLogDir() . '/ModuleAutoCRM/';
test("Log directory exists", is_dir($logDir), "Directory: $logDir");

if (is_dir($logDir)) {
    $owner = getOwner($logDir);
    $group = getGroup($logDir);
    test("Log directory owner is www", $owner === 'www', "Owner: $owner");
    test("Log directory group is www", $group === 'www', "Group: $group");
    test("Log directory is writable", is_writable($logDir), "Not writable");
}

// ==========================================
// Test 2: Create new logger and check file permissions
// ==========================================
echo "\n--- Test New Logger Creation ---\n";

// Generate unique test logger name to ensure fresh file creation
$testLoggerName = 'TestLogger_' . time();
$testLogFile = $logDir . $testLoggerName . '.log';

// Remove test file if it exists from previous run
if (file_exists($testLogFile)) {
    unlink($testLogFile);
}

test("Test log file does not exist before creation", !file_exists($testLogFile));

// Create logger - this should create the file with correct permissions
$logger = new Logger($testLoggerName, 'ModuleAutoCRM');

test("Test log file created after Logger init", file_exists($testLogFile), "File: $testLogFile");

if (file_exists($testLogFile)) {
    $owner = getOwner($testLogFile);
    $group = getGroup($testLogFile);
    test("New log file owner is www", $owner === 'www', "Owner: $owner");
    test("New log file group is www", $group === 'www', "Group: $group");
    test("New log file is writable", is_writable($testLogFile), "Not writable");
}

// Write to logger and verify
$logger->writeInfo('Test message');
test("Log file has content after write", filesize($testLogFile) > 0, "File is empty");

// Check permissions after write
if (file_exists($testLogFile)) {
    $owner = getOwner($testLogFile);
    $group = getGroup($testLogFile);
    test("Log file owner still www after write", $owner === 'www', "Owner: $owner");
    test("Log file group still www after write", $group === 'www', "Group: $group");
}

// Cleanup test file
if (file_exists($testLogFile)) {
    unlink($testLogFile);
}

// ==========================================
// Test 3: Existing log files permissions
// ==========================================
echo "\n--- Test Existing Log Files ---\n";

$expectedLogFiles = [
    'UserSyncer.log',
    'SalonSyncer.log',
    'CallUploader.log',
];

foreach ($expectedLogFiles as $logFileName) {
    $logFilePath = $logDir . $logFileName;
    if (file_exists($logFilePath)) {
        $owner = getOwner($logFilePath);
        $group = getGroup($logFilePath);
        test("$logFileName owner is www", $owner === 'www', "Owner: $owner");
        test("$logFileName group is www", $group === 'www', "Group: $group");
    } else {
        echo "[SKIP] $logFileName does not exist\n";
    }
}

// ==========================================
// Test 4: Logger creates directory if missing
// ==========================================
echo "\n--- Test Logger Creates Directory ---\n";

// Test with a subdirectory that doesn't exist
$testSubModule = 'TestSubModule_' . time();
$testSubDir = System::getLogDir() . '/' . $testSubModule . '/';
$testSubLogFile = $testSubDir . 'Test.log';

test("Test subdirectory does not exist", !is_dir($testSubDir));

// Create logger for non-existent module directory
$subLogger = new Logger('Test', $testSubModule);

test("Test subdirectory created", is_dir($testSubDir), "Dir: $testSubDir");

if (is_dir($testSubDir)) {
    $owner = getOwner($testSubDir);
    $group = getGroup($testSubDir);
    test("Created subdirectory owner is www", $owner === 'www', "Owner: $owner");
    test("Created subdirectory group is www", $group === 'www', "Group: $group");
}

if (file_exists($testSubLogFile)) {
    $owner = getOwner($testSubLogFile);
    $group = getGroup($testSubLogFile);
    test("Created log file owner is www", $owner === 'www', "Owner: $owner");
    test("Created log file group is www", $group === 'www', "Group: $group");
}

// Cleanup
if (file_exists($testSubLogFile)) {
    unlink($testSubLogFile);
}
if (is_dir($testSubDir)) {
    rmdir($testSubDir);
}

// ==========================================
// Summary
// ==========================================
echo "\n=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total: " . ($passed + $failed) . "\n";

exit($failed > 0 ? 1 : 0);
