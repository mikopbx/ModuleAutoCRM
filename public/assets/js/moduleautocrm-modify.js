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

/* global globalRootUrl, globalTranslate, Form, Config */
const ModuleAutoCRMModify = {
    $formObj: $('#moduleautocrm-form'),
    $checkBoxes: $('#moduleautocrm-form .ui.checkbox'),
    $dropDowns: $('#moduleautocrm-form .ui.dropdown'),
    $tabMenu: $('#moduleautocrm-tabs .item'),
    $usersTable: $('#users-table'),
    $salonsTable: $('#salons-table'),
    $callsTable: $('#calls-table'),

    /**
     * Field validation rules
     */
    validateRules: {
        api_url: {
            identifier: 'api_url',
            rules: [
                {
                    type: 'empty',
                    prompt: globalTranslate.module_autocrm_ValidateApiUrlEmpty,
                },
            ],
        },
        api_token: {
            identifier: 'api_token',
            rules: [
                {
                    type: 'empty',
                    prompt: globalTranslate.module_autocrm_ValidateApiTokenEmpty,
                },
            ],
        },
    },

    /**
     * Initialize page
     */
    initialize() {
        // Initialize Semantic UI components
        ModuleAutoCRMModify.$checkBoxes.checkbox();
        ModuleAutoCRMModify.$dropDowns.dropdown();

        // Initialize tabs
        ModuleAutoCRMModify.$tabMenu.tab();

        // Initialize DataTables
        ModuleAutoCRMModify.initUsersTable();
        ModuleAutoCRMModify.initSalonsTable();
        ModuleAutoCRMModify.initCallsTable();

        // Initialize sync buttons
        ModuleAutoCRMModify.initSyncButtons();

        // Initialize form
        ModuleAutoCRMModify.initializeForm();
    },

    /**
     * Initialize users DataTable
     */
    initUsersTable() {
        if (ModuleAutoCRMModify.$usersTable.length) {
            ModuleAutoCRMModify.$usersTable.DataTable({
                paging: true,
                pageLength: 25,
                ordering: true,
                searching: true,
                language: SemanticLocalization.dataTableLocalisation,
                order: [[6, 'asc'], [1, 'asc']],
            });
        }
    },

    /**
     * Initialize salons DataTable
     */
    initSalonsTable() {
        if (ModuleAutoCRMModify.$salonsTable.length) {
            ModuleAutoCRMModify.$salonsTable.DataTable({
                paging: true,
                pageLength: 25,
                ordering: true,
                searching: true,
                language: SemanticLocalization.dataTableLocalisation,
                order: [[1, 'asc']],
            });
        }
    },

    /**
     * Initialize calls DataTable
     */
    initCallsTable() {
        if (ModuleAutoCRMModify.$callsTable.length) {
            ModuleAutoCRMModify.$callsTable.DataTable({
                paging: true,
                pageLength: 25,
                ordering: true,
                searching: true,
                language: SemanticLocalization.dataTableLocalisation,
                order: [[1, 'desc']],
            });
        }
    },

    /**
     * Initialize sync buttons
     */
    initSyncButtons() {
        $('#sync-users-btn').on('click', function() {
            ModuleAutoCRMModify.triggerSync('users', $(this));
        });

        $('#sync-salons-btn').on('click', function() {
            ModuleAutoCRMModify.triggerSync('salons', $(this));
        });

        $('#sync-calls-btn').on('click', function() {
            ModuleAutoCRMModify.triggerSync('calls', $(this));
        });
    },

    /**
     * Trigger sync via API
     */
    triggerSync(type, $button) {
        $button.addClass('loading disabled');

        $.ajax({
            url: `${globalRootUrl}pbxcore/api/modules/ModuleAutoCRM/sync-${type}`,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    UserMessage.showInformation(globalTranslate.module_autocrm_SyncNow + ': ' + type);
                    // Reload page after 2 seconds to show updated data
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    UserMessage.showError(response.messages ? response.messages.join('<br>') : 'Error');
                }
            },
            error: function() {
                UserMessage.showError('Connection error');
            },
            complete: function() {
                $button.removeClass('loading disabled');
            }
        });
    },

    /**
     * Callback before form send
     */
    cbBeforeSendForm(settings) {
        const result = settings;
        result.data = ModuleAutoCRMModify.$formObj.form('get values');
        return result;
    },

    /**
     * Callback after form send
     */
    cbAfterSendForm() {
        // Optional: reload page to refresh data
    },

    /**
     * Initialize form
     */
    initializeForm() {
        Form.$formObj = ModuleAutoCRMModify.$formObj;
        Form.url = `${globalRootUrl}moduleautocrm/moduleautocrm/save`;
        Form.validateRules = ModuleAutoCRMModify.validateRules;
        Form.cbBeforeSendForm = ModuleAutoCRMModify.cbBeforeSendForm;
        Form.cbAfterSendForm = ModuleAutoCRMModify.cbAfterSendForm;
        Form.initialize();
    },
};

$(document).ready(() => {
    ModuleAutoCRMModify.initialize();
});
