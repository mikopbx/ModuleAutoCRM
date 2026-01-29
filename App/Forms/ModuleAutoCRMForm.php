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

namespace Modules\ModuleAutoCRM\App\Forms;

use MikoPBX\AdminCabinet\Forms\BaseForm;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Password;
use Phalcon\Forms\Element\Numeric;
use Phalcon\Forms\Element\Check;
use Phalcon\Forms\Element\Hidden;
use Phalcon\Forms\Element\Select;

class ModuleAutoCRMForm extends BaseForm
{
    public function initialize($entity = null, $options = null): void
    {
        // id
        $this->add(new Hidden('id', ['value' => $entity->id]));

        // api_url
        $this->add(new Text('api_url', [
            'placeholder' => 'https://example.autocrm.ru/yii/api'
        ]));

        // api_token
        $this->add(new Password('api_token'));

        // autosalon_id - dropdown
        $salons = $options['salons'] ?? [];
        $autosalonSelect = new Select('autosalon_id', $salons, [
            'useEmpty' => true,
            'emptyText' => '-',
            'emptyValue' => '',
            'class' => 'ui selection dropdown',
        ]);
        $this->add($autosalonSelect);

        // pbx_protocol - dropdown
        $protocols = [
            'https' => 'https://',
            'http' => 'http://',
        ];
        $protocolSelect = new Select('pbx_protocol', $protocols, [
            'useEmpty' => false,
            'class' => 'ui selection dropdown pbx-protocol-dropdown',
        ]);
        $this->add($protocolSelect);

        // pbx_host
        $this->add(new Text('pbx_host', [
            'placeholder' => 'pbx.example.com'
        ]));

        // sync_users_enabled
        $this->addCheckBox('sync_users_enabled', ($entity->sync_users_enabled ?? '0') === '1');

        // sync_calls_enabled
        $this->addCheckBox('sync_calls_enabled', ($entity->sync_calls_enabled ?? '0') === '1');

        // cdr_offset
        $this->add(new Numeric('cdr_offset', [
            'maxlength' => 10,
            'style' => 'width: 150px;',
            'value' => $entity->cdr_offset ?? 0,
        ]));
    }

    /**
     * Adds a checkbox to the form field with the given name.
     *
     * @param string $fieldName The name of the form field.
     * @param bool $checked Indicates whether the checkbox is checked by default.
     * @param string $checkedValue The value assigned to the checkbox when it is checked.
     * @return void
     */
    public function addCheckBox(string $fieldName, bool $checked, string $checkedValue = 'on'): void
    {
        $checkAr = ['value' => null];
        if ($checked) {
            $checkAr = ['checked' => $checkedValue, 'value' => $checkedValue];
        }
        $this->add(new Check($fieldName, $checkAr));
    }
}
