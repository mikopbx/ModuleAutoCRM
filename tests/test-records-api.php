#!/usr/bin/php
<?php
/**
 * Test script for ModuleAutoCRM records API
 * Run on MikoPBX server: php /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoCRM/tests/test-records-api.php
 */

require_once('Globals.php');

use Modules\ModuleAutoCRM\Models\AutoCrmCalls;

echo "=== ModuleAutoCRM Records API Test ===\n\n";

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

/**
 * Make HTTP request to local API
 *
 * @param string $url
 * @param array $options ['headers' => [], 'output_file' => '']
 * @return array ['code' => int, 'headers' => string, 'body' => string]
 */
function httpRequest($url, $options = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    // Add custom headers
    if (!empty($options['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
    }

    if (!empty($options['output_file'])) {
        $fp = fopen($options['output_file'], 'w');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, false);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if (!empty($options['output_file'])) {
        fclose($fp);
        return ['code' => $httpCode, 'headers' => '', 'body' => ''];
    }

    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    return [
        'code' => $httpCode,
        'headers' => $headers,
        'body' => $body
    ];
}

$baseUrl = 'http://127.0.0.1/pbxcore/api/modules/ModuleAutoCRM/records';

// ==========================================
// Test 1: Request without parameters - should return 404
// ==========================================
echo "--- Test: Empty request ---\n";

$response = httpRequest($baseUrl);
test("Empty request returns 404", $response['code'] === 404);

// ==========================================
// Test 2: Request with non-existent uniqueid - should return 404
// ==========================================
echo "\n--- Test: Non-existent uniqueid ---\n";

$response = httpRequest($baseUrl . '?id=non-existent-id-12345');
test("Non-existent uniqueid returns 404", $response['code'] === 404);

// ==========================================
// Test 3: Request with non-existent file path - should return 404
// ==========================================
echo "\n--- Test: Non-existent file path ---\n";

$response = httpRequest($baseUrl . '?view=/tmp/non-existent-file.mp3');
test("Non-existent file returns 404", $response['code'] === 404);

// ==========================================
// Test 4: Request with unsupported file extension - should return 415
// ==========================================
echo "\n--- Test: Unsupported file extension ---\n";

// Create a temporary txt file
$tmpTxtFile = '/tmp/test-autocrm-' . time() . '.txt';
file_put_contents($tmpTxtFile, 'test content');

$response = httpRequest($baseUrl . '?view=' . urlencode($tmpTxtFile));
test("Unsupported extension returns 415", $response['code'] === 415);

unlink($tmpTxtFile);

// ==========================================
// Test 5: Request with valid mp3 file - should return 200 with inline disposition
// ==========================================
echo "\n--- Test: Valid mp3 file (streaming) ---\n";

// Create a temporary mp3 file (fake, just for testing headers)
$tmpMp3File = '/tmp/test-autocrm-' . time() . '.mp3';
file_put_contents($tmpMp3File, 'fake mp3 content for testing');

$response = httpRequest($baseUrl . '?view=' . urlencode($tmpMp3File));
test("Valid mp3 file returns 200", $response['code'] === 200);
test("Content-Type is audio/mpeg", strpos($response['headers'], 'audio/mpeg') !== false);
test("Content-Disposition is inline (streaming)", strpos($response['headers'], 'inline') !== false);
test("Accept-Ranges is bytes", strpos($response['headers'], 'Accept-Ranges: bytes') !== false);

// Test force download
$response = httpRequest($baseUrl . '?view=' . urlencode($tmpMp3File) . '&download=1');
test("Force download returns 200", $response['code'] === 200);
test("Content-Disposition is attachment (download)", strpos($response['headers'], 'attachment') !== false);

unlink($tmpMp3File);

// ==========================================
// Test 6: Request with valid wav file - should return 200
// ==========================================
echo "\n--- Test: Valid wav file ---\n";

$tmpWavFile = '/tmp/test-autocrm-' . time() . '.wav';
file_put_contents($tmpWavFile, 'fake wav content for testing');

$response = httpRequest($baseUrl . '?view=' . urlencode($tmpWavFile));
test("Valid wav file returns 200", $response['code'] === 200);
test("Content-Type is audio/wav", strpos($response['headers'], 'audio/wav') !== false);

unlink($tmpWavFile);

// ==========================================
// Test 7: Request by uniqueid from database
// ==========================================
echo "\n--- Test: Request by uniqueid from DB ---\n";

// Create test call record with record_path
$testUniqueid = 'test-uniqueid-' . time() . '_abc123';
$tmpTestFile = '/tmp/test-autocrm-record-' . time() . '.mp3';
file_put_contents($tmpTestFile, 'fake recording content');

// Clean up old test records
$oldCalls = AutoCrmCalls::find([
    'conditions' => 'uniqueid LIKE :pattern:',
    'bind' => ['pattern' => 'test-uniqueid-%']
]);
foreach ($oldCalls as $c) {
    $c->delete();
}

// Create test call
$call = new AutoCrmCalls();
$call->uniqueid = $testUniqueid;
$call->linkedid = 'test-linkedid-' . time();
$call->call_datetime = date('Y-m-d H:i:s');
$call->from_number = '9001234567';
$call->to_number = '101';
$call->direction = AutoCrmCalls::DIRECTION_INCOMING;
$call->call_status = AutoCrmCalls::CALL_STATUS_ANSWERED;
$call->record_path = $tmpTestFile;
$call->sync_status = AutoCrmCalls::STATUS_PENDING;
$saved = $call->save();

test("Test call record created", $saved, implode(', ', $call->getMessages()));

if ($saved) {
    // Test API with uniqueid
    $response = httpRequest($baseUrl . '?id=' . urlencode($testUniqueid));
    test("Uniqueid lookup returns 200", $response['code'] === 200);
    test("Returns correct file content", strpos($response['body'], 'fake recording content') !== false);

    // Clean up
    $call->delete();
}

unlink($tmpTestFile);

// ==========================================
// Test 8: Request by uniqueid with missing record_path
// ==========================================
echo "\n--- Test: Uniqueid with empty record_path ---\n";

$testUniqueid2 = 'test-uniqueid-empty-' . time() . '_xyz789';

$call2 = new AutoCrmCalls();
$call2->uniqueid = $testUniqueid2;
$call2->linkedid = 'test-linkedid-empty-' . time();
$call2->call_datetime = date('Y-m-d H:i:s');
$call2->from_number = '9001234567';
$call2->to_number = '101';
$call2->direction = AutoCrmCalls::DIRECTION_INCOMING;
$call2->call_status = AutoCrmCalls::CALL_STATUS_MISSED;
$call2->record_path = ''; // Empty path
$call2->sync_status = AutoCrmCalls::STATUS_PENDING;
$saved2 = $call2->save();

if ($saved2) {
    $response = httpRequest($baseUrl . '?id=' . urlencode($testUniqueid2));
    test("Uniqueid with empty record_path returns 404", $response['code'] === 404);

    $call2->delete();
}

// ==========================================
// Test 9: Range request - partial content
// ==========================================
echo "\n--- Test: HTTP Range request (partial content) ---\n";

// Create test file with known content
$tmpRangeFile = '/tmp/test-autocrm-range-' . time() . '.mp3';
$testContent = '0123456789ABCDEFGHIJ'; // 20 bytes
file_put_contents($tmpRangeFile, $testContent);

// Test Range: bytes=0-9 (first 10 bytes)
$response = httpRequest(
    $baseUrl . '?view=' . urlencode($tmpRangeFile),
    ['headers' => ['Range: bytes=0-9']]
);
test("Range request returns 206", $response['code'] === 206);
test("Content-Range header present", strpos($response['headers'], 'Content-Range: bytes 0-9/20') !== false);
test("Partial content is correct (first 10 bytes)", $response['body'] === '0123456789');

// Test Range: bytes=10-19 (last 10 bytes)
$response = httpRequest(
    $baseUrl . '?view=' . urlencode($tmpRangeFile),
    ['headers' => ['Range: bytes=10-19']]
);
test("Range 10-19 returns 206", $response['code'] === 206);
test("Content-Range for 10-19", strpos($response['headers'], 'Content-Range: bytes 10-19/20') !== false);
test("Partial content is correct (last 10 bytes)", $response['body'] === 'ABCDEFGHIJ');

// Test Range: bytes=5- (from byte 5 to end)
$response = httpRequest(
    $baseUrl . '?view=' . urlencode($tmpRangeFile),
    ['headers' => ['Range: bytes=5-']]
);
test("Range 5- returns 206", $response['code'] === 206);
test("Content-Range for 5-end", strpos($response['headers'], 'Content-Range: bytes 5-19/20') !== false);
test("Partial content from byte 5", $response['body'] === '56789ABCDEFGHIJ');

unlink($tmpRangeFile);

// ==========================================
// Test 10: Invalid Range request - 416 Range Not Satisfiable
// ==========================================
echo "\n--- Test: Invalid Range request ---\n";

$tmpRangeFile2 = '/tmp/test-autocrm-range2-' . time() . '.mp3';
file_put_contents($tmpRangeFile2, '0123456789'); // 10 bytes

// Test invalid range (start > file size)
$response = httpRequest(
    $baseUrl . '?view=' . urlencode($tmpRangeFile2),
    ['headers' => ['Range: bytes=100-200']]
);
test("Invalid range returns 416", $response['code'] === 416);

// Test invalid range (start > end)
$response = httpRequest(
    $baseUrl . '?view=' . urlencode($tmpRangeFile2),
    ['headers' => ['Range: bytes=8-5']]
);
test("Start > end returns 416", $response['code'] === 416);

// Test malformed range header
$response = httpRequest(
    $baseUrl . '?view=' . urlencode($tmpRangeFile2),
    ['headers' => ['Range: invalid']]
);
test("Malformed range returns 416", $response['code'] === 416);

unlink($tmpRangeFile2);

// ==========================================
// Summary
// ==========================================
echo "\n=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total: " . ($passed + $failed) . "\n";

exit($failed > 0 ? 1 : 0);
