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

namespace Modules\ModuleAutoCRM\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Module settings model
 *
 * @package Modules\ModuleAutoCRM\Models
 */
class ModuleAutoCRM extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * AutoCRM API URL
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $api_url = '';

    /**
     * AutoCRM API Bearer token
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $api_token = '';

    /**
     * Autosalon ID for calls binding
     *
     * @Column(type="integer", nullable=true)
     */
    public ?int $autosalon_id = null;

    /**
     * Public PBX host protocol (http or https)
     *
     * @Column(type="string", length=5, default="https", nullable=true)
     */
    public ?string $pbx_protocol = 'https';

    /**
     * Public PBX host for record URLs
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $pbx_host = '';

    /**
     * Enable users synchronization (0/1)
     *
     * @Column(type="string", length=1, default="0", nullable=true)
     */
    public ?string $sync_users_enabled = '0';

    /**
     * Enable calls synchronization (0/1)
     *
     * @Column(type="string", length=1, default="0", nullable=true)
     */
    public ?string $sync_calls_enabled = '0';

    /**
     * CDR offset for incremental sync
     *
     * @Column(type="integer", default="0", nullable=true)
     */
    public ?int $cdr_offset = 0;

    /**
     * Last users sync timestamp
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $last_users_sync = '';

    /**
     * Last calls sync timestamp
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $last_calls_sync = '';

    /**
     * Initialize model
     */
    public function initialize(): void
    {
        $this->setSource('m_ModuleAutoCRM');
        parent::initialize();
    }
}
