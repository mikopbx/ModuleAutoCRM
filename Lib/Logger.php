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

use MikoPBX\Core\System\System;
use MikoPBX\Core\System\Util;
use Phalcon\Logger\Adapter\Stream;
use Phalcon\Logger\Formatter\Line as LineFormatter;

class Logger
{
    public bool $debug;
    private $logger;
    private string $module_name;
    private string $logFile;

    /**
     * Logger constructor.
     *
     * @param string $class
     * @param string $module_name
     */
    public function __construct(string $class, string $module_name = 'ModuleAutoCRM')
    {
        $this->module_name = $module_name;
        $this->debug = true;
        $logPath = System::getLogDir() . '/' . $this->module_name . '/';
        if (!is_dir($logPath)) {
            Util::mwMkdir($logPath);
        }
        // Always ensure correct permissions on log directory
        Util::addRegularWWWRights($logPath);

        $this->logFile = $logPath . $class . '.log';

        // Create log file if it doesn't exist and set permissions BEFORE initializing logger
        if (!file_exists($this->logFile)) {
            touch($this->logFile);
        }
        Util::addRegularWWWRights($this->logFile);

        $this->init();
    }

    /**
     * Инициализация логгера.
     * @return void
     */
    private function init(): void
    {
        $adapter = new Stream($this->logFile);
        // Phalcon 5 uses %level%, Phalcon 4 uses %type%
        if (MikoPBXVersion::isPhalcon5Version()) {
            $lineFormatter = new LineFormatter("[%date%][%level%] %message%", "Y-m-d H:i:s");
        } else {
            $lineFormatter = new LineFormatter("[%date%][%type%] %message%", "Y-m-d H:i:s");
        }
        $adapter->setFormatter($lineFormatter);
        $loggerClass = MikoPBXVersion::getLoggerClass();
        $this->logger = new $loggerClass(
            'messages',
            [
                'main' => $adapter,
            ]
        );
    }

    /**
     * Записать в лог ошибку.
     * @param $data
     * @param string $preMessage
     * @return void
     */
    public function writeError($data, string $preMessage = ''): void
    {
        if ($this->debug) {
            if (!empty($preMessage)) {
                $preMessage .= ': ';
            }
            $this->logger->error('[' . getmypid() . '] ' . $preMessage . $this->getDecodedString($data));
        }
    }

    /**
     * Записать в лог информационное сообщение.
     * @param $data
     * @param string $preMessage
     * @return void
     */
    public function writeInfo($data, string $preMessage = ''): void
    {
        if ($this->debug) {
            if (!empty($preMessage)) {
                $preMessage .= ': ';
            }
            $this->logger->info('[' . getmypid() . '] ' . $preMessage . $this->getDecodedString($data));
        }
    }

    /**
     * Записать в лог предупреждение.
     * @param $data
     * @param string $preMessage
     * @return void
     */
    public function writeWarning($data, string $preMessage = ''): void
    {
        if ($this->debug) {
            if (!empty($preMessage)) {
                $preMessage .= ': ';
            }
            $this->logger->warning('[' . getmypid() . '] ' . $preMessage . $this->getDecodedString($data));
        }
    }

    /**
     * Кодирование данных в виде json.
     * @param $data
     * @return string
     */
    private function getDecodedString($data): string
    {
        try {
            $printedData = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            $printedData = print_r($data, true);
        }
        if (is_bool($printedData)) {
            $result = '';
        } else {
            $result = urldecode($printedData);
        }
        return $result;
    }
}
