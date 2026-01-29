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

use Exception;

/**
 * AutoCRM API Exception
 *
 * @package Modules\ModuleAutoCRM\Lib
 */
class AutoCrmApiException extends Exception
{
    /**
     * Check if error is retriable
     *
     * @return bool
     */
    public function isRetriable(): bool
    {
        // Server errors (5xx) and connection errors (code 0) are retriable
        return $this->code === 0 || $this->code >= 500;
    }

    /**
     * Check if error is client error
     *
     * @return bool
     */
    public function isClientError(): bool
    {
        return $this->code >= 400 && $this->code < 500;
    }
}
