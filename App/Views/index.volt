<form method="post" role="form" class="ui large grey form" id="moduleautocrm-form">
{{ form.render('id') }}

<div class="ui top attached tabular menu" id="moduleautocrm-tabs">
    <a class="item active" data-tab="settings">
        <i class="cog icon"></i>
        {{ t._('module_autocrm_TabSettings') }}
    </a>
    <a class="item" data-tab="users">
        <i class="users icon"></i>
        {{ t._('module_autocrm_TabUsers') }}
        <span class="ui tiny blue circular label">{{ usersCount }}</span>
    </a>
    <a class="item" data-tab="salons">
        <i class="building icon"></i>
        {{ t._('module_autocrm_TabSalons') }}
        <span class="ui tiny teal circular label">{{ salonsCount }}</span>
    </a>
    <a class="item" data-tab="calls">
        <i class="phone icon"></i>
        {{ t._('module_autocrm_TabCalls') }}
        <span class="ui tiny orange circular label" id="calls-tab-count">{{ callsCount }}</span>
    </a>
</div>

<!-- Settings Tab -->
<div class="ui bottom attached tab segment active" data-tab="settings">
    <h4 class="ui dividing header">{{ t._('module_autocrm_ApiSettings') }}</h4>

    <div class="field">
        <label>{{ t._('module_autocrm_ApiUrl') }}</label>
        {{ form.render('api_url') }}
        <div class="ui pointing label">{{ t._('module_autocrm_ApiUrlHint') }}</div>
    </div>

    <div class="field">
        <label>{{ t._('module_autocrm_ApiToken') }}</label>
        {{ form.render('api_token') }}
    </div>

    <div class="field">
        <label>{{ t._('module_autocrm_Autosalon') }}</label>
        {{ form.render('autosalon_id') }}
        <div class="ui pointing label">{{ t._('module_autocrm_AutosalonHint') }}</div>
    </div>

    <h4 class="ui dividing header">{{ t._('module_autocrm_PbxSettings') }}</h4>

    <div class="field">
        <label>{{ t._('module_autocrm_PbxHost') }}</label>
        <div class="ui action input">
            {{ form.render('pbx_protocol') }}
            {{ form.render('pbx_host') }}
        </div>
        <div class="ui pointing label">{{ t._('module_autocrm_PbxHostHint') }}</div>
    </div>

    <h4 class="ui dividing header">{{ t._('module_autocrm_SyncSettings') }}</h4>

    <div class="field">
        <div class="ui toggle checkbox">
            {{ form.render('sync_users_enabled') }}
            <label>{{ t._('module_autocrm_SyncUsersEnabled') }}</label>
        </div>
    </div>

    <div class="field">
        <div class="ui toggle checkbox">
            {{ form.render('sync_calls_enabled') }}
            <label>{{ t._('module_autocrm_SyncCallsEnabled') }}</label>
        </div>
    </div>

    <div class="field">
        <label>{{ t._('module_autocrm_CdrOffset') }}</label>
        {{ form.render('cdr_offset') }}
        <div class="ui pointing label">{{ t._('module_autocrm_CdrOffsetHint') }}</div>
    </div>

    {{ partial("partials/submitbutton", ['indexurl': '']) }}
</div>

<!-- Users Tab -->
<div class="ui bottom attached tab segment" data-tab="users">
    <h4 class="ui header">
        <i class="users icon"></i>
        {{ t._('module_autocrm_UsersTitle') }}
    </h4>

    <table id="users-table" class="ui small very compact single line table">
        <thead>
            <tr>
                <th>ID CRM</th>
                <th>{{ t._('module_autocrm_UserPerson') }}</th>
                <th>{{ t._('module_autocrm_UserPhone') }}</th>
                <th>{{ t._('module_autocrm_UserWorkPhone') }}</th>
                <th>{{ t._('module_autocrm_UserExtension') }}</th>
                <th>{{ t._('module_autocrm_UserSalons') }}</th>
            </tr>
        </thead>
        <tbody>
            {% for user in users %}
            <tr>
                <td>{{ user['crm_user_id'] }}</td>
                <td>{{ user['person'] }}</td>
                <td>{{ user['phone'] }}</td>
                <td>{{ user['work_phone'] }}</td>
                <td>{{ user['asterisk_extension'] }}</td>
                <td>
                    {% if user['salonName'] is empty %}
                        -
                    {% else %}
                        {{ user['salonName'] }}
                    {% endif %}
                </td>
            </tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<!-- Salons Tab -->
<div class="ui bottom attached tab segment" data-tab="salons">
    <h4 class="ui header">
        <i class="building icon"></i>
        {{ t._('module_autocrm_SalonsTitle') }}
    </h4>

    <div class="ui info message">
        <i class="info circle icon"></i>
        {{ t._('module_autocrm_SalonsInfo') }}: <strong>{{ salonsCount }}</strong>
    </div>

    <table id="salons-table" class="ui small very compact single line table">
        <thead>
            <tr>
                <th>ID CRM</th>
                <th>{{ t._('module_autocrm_SalonName') }}</th>
                <th>{{ t._('module_autocrm_SalonAddress') }}</th>
                <th>{{ t._('module_autocrm_SalonPhone') }}</th>
                <th>{{ t._('module_autocrm_LastSync') }}</th>
            </tr>
        </thead>
        <tbody>
            {% for salon in salons %}
            <tr>
                <td>{{ salon.crm_salon_id }}</td>
                <td>{{ salon.name }}</td>
                <td>{{ salon.address }}</td>
                <td>{{ salon.phone }}</td>
                <td>{{ salon.last_sync }}</td>
            </tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<!-- Calls Tab -->
<div class="ui bottom attached tab segment" data-tab="calls">
    <h4 class="ui header">
        <i class="phone icon"></i>
        {{ t._('module_autocrm_CallsTitle') }}
    </h4>

    <!-- Stats -->
    <div class="ui four statistics" id="calls-statistics">
        <div class="statistic">
            <div class="value" id="stat-total">{{ callsStats['total'] }}</div>
            <div class="label">{{ t._('module_autocrm_StatsTotal') }}</div>
        </div>
        <div class="statistic green">
            <div class="value" id="stat-sent">{{ callsStats['sent'] }}</div>
            <div class="label">{{ t._('module_autocrm_StatsSent') }}</div>
        </div>
        <div class="statistic yellow">
            <div class="value" id="stat-pending">{{ callsStats['pending'] }}</div>
            <div class="label">{{ t._('module_autocrm_StatsPending') }}</div>
        </div>
        <div class="statistic red">
            <div class="value" id="stat-error">{{ callsStats['error'] }}</div>
            <div class="label">{{ t._('module_autocrm_StatsError') }}</div>
        </div>
    </div>

    <div class="ui divider"></div>

    <table id="calls-table" class="ui small very compact single line table">
        <thead>
            <tr>
                <th>LinkedID</th>
                <th style="width: 30px;"></th>
                <th>{{ t._('module_autocrm_CallDate') }}</th>
                <th>{{ t._('module_autocrm_CallFrom') }}</th>
                <th>{{ t._('module_autocrm_CallTo') }}</th>
                <th>{{ t._('module_autocrm_CallStatus') }}</th>
                <th>{{ t._('module_autocrm_SyncStatus') }}</th>
                <th>CRM ID</th>
                <th>{{ t._('module_autocrm_SyncError') }}</th>
            </tr>
        </thead>
        <tbody>
            {% for record in recentCalls %}
            <tr>
                <td>{{ record.linkedid }}</td>
                <td>
                    {% if record.direction == 'incoming' %}
                        <i class="phone volume icon green"></i>
                    {% else %}
                        <i class="phone icon blue"></i>
                    {% endif %}
                </td>
                <td>{{ record.call_datetime }}</td>
                <td>{{ record.from_number }}</td>
                <td>{{ record.to_number }}</td>
                <td>
                    {% if record.call_status == 'answered' %}
                        <span class="ui green label">{{ t._('module_autocrm_CallStatusAnswered') }}</span>
                    {% else %}
                        <span class="ui red label">{{ t._('module_autocrm_CallStatusMissed') }}</span>
                    {% endif %}
                </td>
                <td>
                    {% if record.sync_status == 'sent' %}
                        <a href="#" class="ui green label upload-call" data-call-id="{{ record.id }}" data-action="update" title="Click to update in CRM">{{ t._('module_autocrm_SyncStatusSent') }}</a>
                    {% elseif record.sync_status == 'error' %}
                        <a href="#" class="ui red label upload-call" data-call-id="{{ record.id }}" data-action="upload" title="{{ record.sync_error }}">{{ t._('module_autocrm_SyncStatusError') }}</a>
                    {% else %}
                        <a href="#" class="ui yellow label upload-call" data-call-id="{{ record.id }}" data-action="upload" title="Click to upload to CRM">{{ t._('module_autocrm_SyncStatusPending') }}</a>
                    {% endif %}
                </td>
                <td>{{ record.crm_call_id ? record.crm_call_id : '-' }}</td>
                <td>{{ record.sync_error }}</td>
            </tr>
            {% endfor %}
        </tbody>
    </table>
</div>

</form>
