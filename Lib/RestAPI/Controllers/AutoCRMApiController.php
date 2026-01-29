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

namespace Modules\ModuleAutoCRM\Lib\RestAPI\Controllers;

use MikoPBX\PBXCoreREST\Controllers\Modules\ModulesControllerBase;
use Modules\ModuleAutoCRM\Models\AutoCrmCalls;

/**
 * REST API controller for ModuleAutoCRM
 *
 * @package Modules\ModuleAutoCRM\Lib\RestAPI\Controllers
 */
class AutoCRMApiController extends ModulesControllerBase
{
    /**
     * Allowed MIME types for audio files
     *
     * @var array
     */
    private $allowedMimeTypes = [
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'webm' => 'audio/webm',
    ];

    /**
     * Stream or download call recording file with Range support
     *
     * Supports HTTP Range requests for browser audio playback (seeking).
     *
     * Usage:
     * # Download by uniqueid
     * curl -o test.mp3 'http://127.0.0.1/pbxcore/api/modules/ModuleAutoCRM/records?id=mikopbx-1742368258.4_a9Gp8D'
     *
     * # Stream by file path (for browser playback)
     * <audio src="http://127.0.0.1/pbxcore/api/modules/ModuleAutoCRM/records?view=/path/to/file.mp3"></audio>
     *
     * # Force download
     * curl -O 'http://127.0.0.1/pbxcore/api/modules/ModuleAutoCRM/records?view=/path/to/file.mp3&download=1'
     *
     * @return void
     */
    public function recordsAction(): void
    {
        $uniqueid = (string)$this->request->get('id');
        $filename = (string)$this->request->get('view');
        $forceDownload = (bool)$this->request->get('download');

        // Try to find recording by uniqueid
        if (!file_exists($filename) && !empty($uniqueid)) {
            $call = AutoCrmCalls::findFirst([
                'conditions' => 'uniqueid = :uniqueid:',
                'bind' => ['uniqueid' => $uniqueid]
            ]);
            if ($call !== null && !empty($call->record_path)) {
                $filename = $call->record_path;
            }
        }

        if (!file_exists($filename)) {
            $this->sendError(404);
            return;
        }

        // Validate file extension
        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        if (!array_key_exists($ext, $this->allowedMimeTypes)) {
            $this->sendError(415);
            return;
        }

        $fileSize = filesize($filename);
        $contentType = $this->allowedMimeTypes[$ext];
        $rangeHeader = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;

        // Handle Range request for streaming
        if ($rangeHeader !== null) {
            $this->handleRangeRequest($filename, $fileSize, $contentType, $rangeHeader, $forceDownload);
        } else {
            $this->handleFullRequest($filename, $fileSize, $contentType, $forceDownload);
        }
    }

    /**
     * Handle HTTP Range request for partial content (audio seeking)
     *
     * @param string $filename File path
     * @param int $fileSize Total file size
     * @param string $contentType MIME type
     * @param string $rangeHeader HTTP Range header value
     * @param bool $forceDownload Force download instead of inline
     * @return void
     */
    private function handleRangeRequest(
        string $filename,
        int $fileSize,
        string $contentType,
        string $rangeHeader,
        bool $forceDownload
    ): void {
        // Parse Range header (e.g., "bytes=0-1023" or "bytes=1024-")
        if (!preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
            $this->sendRangeNotSatisfiable($fileSize);
            return;
        }

        $start = $matches[1] !== '' ? (int)$matches[1] : 0;
        $end = $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;

        // Validate range
        if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
            $this->sendRangeNotSatisfiable($fileSize);
            return;
        }

        $contentLength = $end - $start + 1;

        $fp = fopen($filename, 'rb');
        if (!$fp) {
            $this->sendError(500);
            return;
        }

        // Seek to start position
        fseek($fp, $start);

        // Send 206 Partial Content
        http_response_code(206);
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $contentLength);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
        header('Accept-Ranges: bytes');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        if ($forceDownload) {
            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        } else {
            header('Content-Disposition: inline; filename="' . basename($filename) . '"');
        }

        // Output the requested range
        $bytesRemaining = $contentLength;
        $bufferSize = 8192;
        while ($bytesRemaining > 0 && !feof($fp)) {
            $readSize = min($bufferSize, $bytesRemaining);
            echo fread($fp, $readSize);
            $bytesRemaining -= $readSize;
            flush();
        }

        fclose($fp);
    }

    /**
     * Handle full file request (no Range header)
     *
     * @param string $filename File path
     * @param int $fileSize Total file size
     * @param string $contentType MIME type
     * @param bool $forceDownload Force download instead of inline
     * @return void
     */
    private function handleFullRequest(
        string $filename,
        int $fileSize,
        string $contentType,
        bool $forceDownload
    ): void {
        $fp = fopen($filename, 'rb');
        if (!$fp) {
            $this->sendError(500);
            return;
        }

        // Send 200 OK with full content
        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $fileSize);
        header('Accept-Ranges: bytes');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        if ($forceDownload) {
            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        } else {
            header('Content-Disposition: inline; filename="' . basename($filename) . '"');
        }

        fpassthru($fp);
        fclose($fp);
    }

    /**
     * Send 416 Range Not Satisfiable response
     *
     * @param int $fileSize Total file size
     * @return void
     */
    private function sendRangeNotSatisfiable(int $fileSize): void
    {
        $this->response->setStatusCode(416, 'Range Not Satisfiable');
        $this->response->setHeader('Content-Range', 'bytes */' . $fileSize);
        $this->response->send();
    }
}
