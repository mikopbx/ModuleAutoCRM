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

return [
    'repModuleAutoCRM'         => 'Модуль AutoCRM - %repesent%',
    'mo_ModuleModuleAutoCRM'   => 'Интеграция с AutoCRM',
    'BreadcrumbModuleAutoCRM'  => 'Интеграция с AutoCRM',
    'SubHeaderModuleAutoCRM'   => 'Синхронизация звонков и пользователей с AutoCRM',

    // Tabs
    'module_autocrm_TabSettings'    => 'Настройки',
    'module_autocrm_TabUsers'       => 'Пользователи',
    'module_autocrm_TabSalons'      => 'Автосалоны',
    'module_autocrm_TabCalls'       => 'Звонки',

    // Settings - API
    'module_autocrm_ApiSettings'    => 'Настройки API AutoCRM',
    'module_autocrm_ApiUrl'         => 'Адрес сервера AutoCRM',
    'module_autocrm_ApiUrlHint'     => 'Например: test.autocrm.ru',
    'module_autocrm_ApiToken'       => 'Токен авторизации',
    'module_autocrm_Autosalon'      => 'Автосалон по умолчанию',
    'module_autocrm_AutosalonHint'  => 'Используется если у пользователя не указан автосалон',

    // Settings - PBX
    'module_autocrm_PbxSettings'    => 'Настройки АТС',
    'module_autocrm_PbxProtocol'    => 'Протокол',
    'module_autocrm_PbxHost'        => 'Публичный адрес АТС',
    'module_autocrm_PbxHostHint'    => 'Используется для формирования ссылок на записи разговоров',

    // Settings - Sync
    'module_autocrm_SyncSettings'       => 'Настройки синхронизации',
    'module_autocrm_SyncUsersEnabled'   => 'Синхронизировать пользователей (ежедневно в 03:00)',
    'module_autocrm_SyncCallsEnabled'   => 'Выгружать звонки в CRM (каждую минуту)',
    'module_autocrm_CdrOffset'          => 'Смещение CDR',
    'module_autocrm_CdrOffsetHint'      => 'ID последней обработанной записи CDR',

    // Users tab
    'module_autocrm_UsersTitle'     => 'Пользователи AutoCRM',
    'module_autocrm_UsersInfo'      => 'Всего пользователей',
    'module_autocrm_Active'         => 'активных',
    'module_autocrm_UserPerson'     => 'ФИО',
    'module_autocrm_UserPhone'      => 'Мобильный',
    'module_autocrm_UserWorkPhone'  => 'Рабочий',
    'module_autocrm_UserExtension'  => 'Внутренний',
    'module_autocrm_UserSalons'     => 'Автосалоны',
    'module_autocrm_UserStatus'     => 'Статус',
    'module_autocrm_StatusActive'   => 'Активный',
    'module_autocrm_StatusFired'    => 'Уволен',

    // Salons tab
    'module_autocrm_SalonsTitle'    => 'Автосалоны',
    'module_autocrm_SalonsInfo'     => 'Всего автосалонов',
    'module_autocrm_SalonName'      => 'Название',
    'module_autocrm_SalonAddress'   => 'Адрес',
    'module_autocrm_SalonPhone'     => 'Телефон',
    'module_autocrm_LastSync'       => 'Последняя синхронизация',

    // Calls tab
    'module_autocrm_CallsTitle'     => 'История синхронизации звонков',
    'module_autocrm_CallDate'       => 'Дата/время',
    'module_autocrm_CallFrom'       => 'Откуда',
    'module_autocrm_CallTo'         => 'Куда',
    'module_autocrm_CallStatus'     => 'Статус звонка',
    'module_autocrm_SyncStatus'     => 'Синхронизация',
    'module_autocrm_SyncError'      => 'Ошибка',

    // Stats
    'module_autocrm_StatsTotal'     => 'Всего',
    'module_autocrm_StatsSent'      => 'Отправлено',
    'module_autocrm_StatsPending'   => 'В ожидании',
    'module_autocrm_StatsError'     => 'Ошибки',

    // Call statuses
    'module_autocrm_CallStatusAnswered' => 'Отвечен',
    'module_autocrm_CallStatusMissed'   => 'Пропущен',

    // Sync statuses
    'module_autocrm_SyncStatusSent'     => 'Отправлен',
    'module_autocrm_SyncStatusPending'  => 'Ожидает',
    'module_autocrm_SyncStatusError'    => 'Ошибка',

    // Buttons
    'module_autocrm_SyncNow'        => 'Синхронизировать',
    'module_autocrm_ShowRecords'    => 'Показать',

    // Validation
    'module_autocrm_ValidateApiUrlEmpty'    => 'Укажите URL API',
    'module_autocrm_ValidateApiTokenEmpty'  => 'Укажите токен авторизации',
];
