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
 * AutoCRM autosalons cache model
 *
 * @package Modules\ModuleAutoCRM\Models
 *
 * @Indexes(
 *     [name='crm_salon_id', columns=['crm_salon_id'], type='']
 * )
 */
class AutoCrmSalons extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Autosalon ID in AutoCRM
     *
     * @Column(type="integer", nullable=false)
     */
    public int $crm_salon_id;

    /**
     * Autosalon name
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $name = '';

    /**
     * Autosalon address
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $address = '';

    /**
     * Autosalon phone
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $phone = '';

    /**
     * Last sync timestamp
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $last_sync = '';

    /**
     * Initialize model
     */
    public function initialize(): void
    {
        $this->setSource('m_AutoCrmSalons');
        parent::initialize();
    }

    /**
     * Get all salons for dropdown
     *
     * @return array [id => name]
     */
    public static function getListForDropdown(): array
    {
        $result = [];
        $salons = self::find(['order' => 'name ASC']);
        foreach ($salons as $salon) {
            $result[$salon->crm_salon_id] = $salon->name;
        }
        return $result;
    }

    /**
     * Find salon by CRM ID
     *
     * @param int $crmSalonId
     * @return AutoCrmSalons|null
     */
    public static function findByCrmId(int $crmSalonId): ?AutoCrmSalons
    {
        return self::findFirst([
            'conditions' => 'crm_salon_id = :id:',
            'bind' => ['id' => $crmSalonId]
        ]);
    }
}
