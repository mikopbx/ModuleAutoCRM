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

/* global globalRootUrl, globalTranslate, Form, Config, SemanticLocalization, UserMessage */
const ModuleAutoCRMIndex = {
    $formObj: $('#moduleautocrm-form'),
    $checkBoxes: $('#moduleautocrm-form .ui.checkbox'),
    $dropDowns: $('#moduleautocrm-form .ui.dropdown'),
    $tabMenu: $('#moduleautocrm-tabs .item'),
    $usersTable: $('#users-table'),
    $salonsTable: $('#salons-table'),
    $callsTable: $('#calls-table'),
    callsDataTable: null,
    $callsDateFilter: null,

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
        ModuleAutoCRMIndex.$checkBoxes.checkbox();
        ModuleAutoCRMIndex.$dropDowns.dropdown();

        // Initialize tabs with callback to handle hidden tables
        ModuleAutoCRMIndex.$tabMenu.tab({
            onVisible: function(tabPath) {
                if (tabPath === 'calls' && ModuleAutoCRMIndex.callsDataTable) {
                    // Recalculate columns and apply filter when calls tab becomes visible
                    ModuleAutoCRMIndex.callsDataTable.columns.adjust();
                    ModuleAutoCRMIndex.filterCallsByDate();
                }
            }
        });

        // Initialize tooltips
        $('[data-tooltip]').popup();

        // Initialize DataTables
        ModuleAutoCRMIndex.initUsersTable();
        ModuleAutoCRMIndex.initSalonsTable();
        ModuleAutoCRMIndex.initCallsTable();

        // Initialize form
        ModuleAutoCRMIndex.initializeForm();
    },

    /**
     * Initialize users DataTable
     */
    initUsersTable() {
        if (ModuleAutoCRMIndex.$usersTable.length) {
            const usersLang = $.extend({}, SemanticLocalization.dataTableLocalisation);
            usersLang.lengthMenu = globalTranslate.module_autocrm_ShowRecords + ' _MENU_';
            ModuleAutoCRMIndex.$usersTable.DataTable({
                paging: true,
                pageLength: 25,
                ordering: true,
                searching: true,
                language: usersLang,
                order: [[1, 'asc']],
            });
        }
    },

    /**
     * Initialize salons DataTable
     */
    initSalonsTable() {
        if (ModuleAutoCRMIndex.$salonsTable.length) {
            const salonsLang = $.extend({}, SemanticLocalization.dataTableLocalisation);
            salonsLang.lengthMenu = globalTranslate.module_autocrm_ShowRecords + ' _MENU_';
            ModuleAutoCRMIndex.$salonsTable.DataTable({
                paging: true,
                pageLength: 25,
                ordering: true,
                searching: true,
                language: salonsLang,
                order: [[1, 'asc']],
            });
        }
    },

    /**
     * Initialize calls DataTable with row grouping
     */
    initCallsTable() {
        if (ModuleAutoCRMIndex.$callsTable.length) {
            const callsLang = $.extend({}, SemanticLocalization.dataTableLocalisation);
            callsLang.lengthMenu = globalTranslate.module_autocrm_ShowRecords + ' _MENU_';
            ModuleAutoCRMIndex.callsDataTable = ModuleAutoCRMIndex.$callsTable.DataTable({
                paging: true,
                pageLength: 25,
                ordering: true,
                searching: true,
                language: callsLang,
                order: [[0, 'asc'], [2, 'desc']], // Group by linkedid, then by date desc
                rowGroup: {
                    dataSrc: 0, // Column 0 is linkedid
                    startRender: function(rows, group) {
                        // Count records in group
                        const count = rows.count();
                        if (count <= 1) {
                            return null; // Don't show group header for single records
                        }
                        return $('<tr class="group-header"/>')
                            .append('<td colspan="9" style="background: #e8f4f8; font-weight: bold; padding: 5px 10px;"><i class="linkify icon"></i> ' + group + ' <span class="ui tiny blue circular label">' + count + ' users</span></td>');
                    }
                }
            });

            // Insert date filter into DataTables toolbar (after length selector)
            const today = new Date().toISOString().split('T')[0];
            const dateFilterHtml = `<div class="ui input" style="margin-left: 10px;"><input type="date" id="calls-date-filter" value="${today}" style="width: 140px;"></div>`;
            $('#calls-table_length label').append(dateFilterHtml);

            ModuleAutoCRMIndex.$callsDateFilter = $('#calls-date-filter');

            // Update statistics on every table draw
            ModuleAutoCRMIndex.callsDataTable.on('draw', function() {
                ModuleAutoCRMIndex.updateCallsStatistics();
            });

            // Apply initial filter
            ModuleAutoCRMIndex.filterCallsByDate();

            // Date filter handler
            ModuleAutoCRMIndex.$callsDateFilter.on('change', function() {
                ModuleAutoCRMIndex.filterCallsByDate();
            });
        }
    },

    /**
     * Filter calls table by date
     */
    filterCallsByDate() {
        const filterDate = ModuleAutoCRMIndex.$callsDateFilter.val();
        if (filterDate) {
            // Column 2 is the date column (0=linkedid, 1=direction, 2=date)
            ModuleAutoCRMIndex.callsDataTable.column(2).search(filterDate).draw();
        } else {
            ModuleAutoCRMIndex.callsDataTable.column(2).search('').draw();
        }
        // Update statistics based on filtered rows
        ModuleAutoCRMIndex.updateCallsStatistics();
    },

    /**
     * Update calls statistics based on filtered DataTable rows
     */
    updateCallsStatistics() {
        let total = 0;
        let sent = 0;
        let pending = 0;
        let error = 0;

        // Iterate over filtered rows only
        ModuleAutoCRMIndex.callsDataTable.rows({ search: 'applied' }).every(function() {
            const rowData = this.data();
            total++;
            // Column 6 contains sync status with label (0=linkedid, 1=direction, 2=date, 3=from, 4=to, 5=call_status, 6=sync_status)
            const syncStatusHtml = rowData[6];
            if (syncStatusHtml.indexOf('green') !== -1) {
                sent++;
            } else if (syncStatusHtml.indexOf('red') !== -1) {
                error++;
            } else if (syncStatusHtml.indexOf('yellow') !== -1) {
                pending++;
            }
        });

        // Update statistics display
        $('#stat-total').text(total);
        $('#stat-sent').text(sent);
        $('#stat-pending').text(pending);
        $('#stat-error').text(error);

        // Update tab badge
        $('#calls-tab-count').text(total);
    },

    /**
     * Callback before form send
     */
    cbBeforeSendForm(settings) {
        const result = settings;
        result.data = ModuleAutoCRMIndex.$formObj.form('get values');
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
        Form.$formObj = ModuleAutoCRMIndex.$formObj;
        Form.url = `${globalRootUrl}module-auto-c-r-m/module-auto-c-r-m/save`;
        Form.validateRules = ModuleAutoCRMIndex.validateRules;
        Form.cbBeforeSendForm = ModuleAutoCRMIndex.cbBeforeSendForm;
        Form.cbAfterSendForm = ModuleAutoCRMIndex.cbAfterSendForm;
        Form.initialize();
    },
};

$(document).ready(() => {
    ModuleAutoCRMIndex.initialize();
});
