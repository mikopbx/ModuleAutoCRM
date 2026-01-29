# ModuleAutoCRM - Technical Documentation

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                           MikoPBX Server                            │
├─────────────────────────────────────────────────────────────────────┤
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────────────────┐  │
│  │  Asterisk   │───▶│  CDR.db     │───▶│   ModuleAutoCRM         │  │
│  │  (Calls)    │    │  (SQLite)   │    │   (Call Processing)     │  │
│  └─────────────┘    └─────────────┘    └───────────┬─────────────┘  │
│                                                     │               │
│  ┌─────────────────────────────────────────────────┼───────────────┐│
│  │                    Module Components            │               ││
│  │  ┌──────────────┐  ┌──────────────┐  ┌─────────▼─────────┐     ││
│  │  │HistoryParser │  │ CallUploader │  │ AutoCrmApiClient  │     ││
│  │  │ (CDR Reader) │  │ (Sync Logic) │  │ (API Gateway)     │     ││
│  │  └──────┬───────┘  └──────┬───────┘  └─────────┬─────────┘     ││
│  │         │                 │                     │               ││
│  │         ▼                 ▼                     ▼               ││
│  │  ┌────────────────────────────────────────────────────────┐    ││
│  │  │              Module SQLite Database                    │    ││
│  │  │  ┌──────────────┐ ┌────────────┐ ┌──────────────────┐ │    ││
│  │  │  │AutoCrmUsers  │ │AutoCrmCalls│ │ AutoCrmSalons    │ │    ││
│  │  │  └──────────────┘ └────────────┘ └──────────────────┘ │    ││
│  │  └────────────────────────────────────────────────────────┘    ││
│  └─────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────┘
                                   │
                                   │ HTTPS
                                   ▼
                    ┌──────────────────────────────┐
                    │        AutoCRM Server        │
                    │  https://xxx.autocrm.ru/yii  │
                    │  ┌────────────────────────┐  │
                    │  │       REST API         │  │
                    │  │  GET  /user            │  │
                    │  │  GET  /autosalon       │  │
                    │  │  POST /call            │  │
                    │  │  PUT  /call/{id}       │  │
                    │  └────────────────────────┘  │
                    └──────────────────────────────┘
```

## Module Structure

```
ModuleAutoCRM/
├── module.json              # Module metadata
├── composer.json            # PHP dependencies
│
├── Setup/
│   └── PbxExtensionSetup.php   # Installation & DB schema
│
├── Models/                     # Phalcon ORM models
│   ├── ModuleAutoCRM.php       # Settings table
│   ├── AutoCrmUsers.php        # CRM users cache
│   ├── AutoCrmCalls.php        # Call sync records
│   └── AutoCrmSalons.php       # Autosalons cache
│
├── Lib/                        # Core business logic
│   ├── AutoCrmApiClient.php    # API communication
│   ├── AutoCrmApiException.php # Custom exceptions
│   ├── AutoCRMConf.php         # Config & cron setup
│   ├── AutoCRMMain.php         # Main module class
│   ├── CallUploader.php        # Call upload service
│   ├── HistoryParser.php       # CDR parsing
│   ├── Logger.php              # Logging utility
│   ├── MikoPBXVersion.php      # Phalcon compatibility
│   └── RestAPI/Controllers/
│       └── AutoCRMApiController.php  # REST endpoints
│
├── App/                        # MVC application
│   ├── Module.php              # Phalcon module init
│   ├── Controllers/
│   │   └── ModuleAutoCRMController.php
│   ├── Forms/
│   │   └── ModuleAutoCRMForm.php
│   └── Views/
│       └── ModuleAutoCRM/
│           └── index.volt
│
├── bin/                        # Cron scripts
│   ├── Globals.php             # Symlink to MikoPBX
│   ├── sync-calls.php          # Call sync (every minute)
│   ├── sync-users.php          # User sync (daily)
│   └── sync-salons.php         # Salon sync (daily)
│
├── public/assets/              # Frontend assets
│   ├── css/
│   ├── js/
│   └── img/
│
├── Messages/                   # Translations (i18n)
│   ├── en.php
│   └── ru.php
│
└── db/                         # Database directory
    └── module.db               # SQLite database
```

## Database Schema

### Entity Relationship Diagram

```
┌─────────────────────────────────────┐
│         m_ModuleAutoCRM             │
│         (Settings - 1 row)          │
├─────────────────────────────────────┤
│ id             INTEGER PK           │
│ api_url        VARCHAR(255)         │
│ api_token      VARCHAR(255)         │
│ autosalon_id   INTEGER        ──────┼──┐
│ pbx_protocol   VARCHAR(10)          │  │
│ pbx_host       VARCHAR(255)         │  │
│ sync_users_enabled  VARCHAR(1)      │  │
│ sync_calls_enabled  VARCHAR(1)      │  │
│ cdr_offset     INTEGER              │  │
│ last_users_sync    DATETIME         │  │
│ last_calls_sync    DATETIME         │  │
└─────────────────────────────────────┘  │
                                         │
┌─────────────────────────────────────┐  │
│         m_AutoCrmSalons             │◀─┘
│         (CRM Autosalons Cache)      │
├─────────────────────────────────────┤
│ id             INTEGER PK           │
│ crm_salon_id   INTEGER UNIQUE  ─────┼──┐
│ name           VARCHAR(255)         │  │
│ address        VARCHAR(500)         │  │
│ phone          VARCHAR(50)          │  │
│ last_sync      DATETIME             │  │
└─────────────────────────────────────┘  │
                                         │
┌─────────────────────────────────────┐  │
│         m_AutoCrmUsers              │  │
│         (CRM Users Cache)           │  │
├─────────────────────────────────────┤  │
│ id             INTEGER PK           │  │
│ crm_user_id    INTEGER UNIQUE  ─────┼──┼──┐
│ person         VARCHAR(255)         │  │  │
│ first_name     VARCHAR(100)         │  │  │
│ middle_name    VARCHAR(100)         │  │  │
│ last_name      VARCHAR(100)         │  │  │
│ salon          INTEGER         ─────┼──┘  │
│ user_autosalons VARCHAR(255)        │     │
│ phone          VARCHAR(20) INDEXED  │     │
│ work_phone     VARCHAR(20) INDEXED  │     │
│ asterisk_extension VARCHAR(10) IDX  │     │
│ user_phone_numbers TEXT (JSON)      │     │
│ status         INTEGER (1=active)   │     │
│ blocked        INTEGER (0/1)        │     │
│ is_local       INTEGER (0/1)        │     │
│ last_sync      DATETIME             │     │
└─────────────────────────────────────┘     │
                                            │
┌─────────────────────────────────────┐     │
│         m_AutoCrmCalls              │     │
│         (Call Sync Records)         │     │
├─────────────────────────────────────┤     │
│ id             INTEGER PK           │     │
│ crm_call_id    INTEGER INDEXED ─────┼─────┤
│ uniqueid       VARCHAR(50) INDEXED  │     │
│ linkedid       VARCHAR(50) INDEXED  │     │
│ call_datetime      DATETIME         │     │
│ call_datetime_end  DATETIME         │     │
│ from_number    VARCHAR(50)          │     │
│ to_number      VARCHAR(50)          │     │
│ call_status    VARCHAR(20)          │     │
│ direction      VARCHAR(20)          │     │
│ record_path    VARCHAR(500)         │     │
│ record_url     VARCHAR(500)         │     │
│ sync_status    VARCHAR(20) INDEXED  │     │
│ sync_error     TEXT                 │     │
│ retry_count    INTEGER              │     │
│ created_at     DATETIME             │     │
│ updated_at     DATETIME             │     │
└─────────────────────────────────────┘     │
         │                                  │
         │ References (for API payload)     │
         └──────────────────────────────────┘
```

## Call Synchronization Flow

### Phase 1: Collection from CDR

```
┌────────────────┐
│  Cron Trigger  │
│  (Every 1 min) │
└───────┬────────┘
        │
        ▼
┌────────────────────────────────────────────────────┐
│              sync-calls.php                        │
│  ┌──────────────────────────────────────────────┐  │
│  │ 1. Get CDR offset from m_ModuleAutoCRM       │  │
│  │    (or calculate for last 10 linkedids)      │  │
│  └──────────────────┬───────────────────────────┘  │
│                     │                              │
│                     ▼                              │
│  ┌──────────────────────────────────────────────┐  │
│  │ 2. Query CDR.db grouped by linkedid          │  │
│  │    SELECT * FROM cdr WHERE id > offset       │  │
│  │    ORDER BY linkedid                         │  │
│  └──────────────────┬───────────────────────────┘  │
│                     │                              │
│                     ▼                              │
│  ┌──────────────────────────────────────────────┐  │
│  │ 3. For each linkedid group:                  │  │
│  │    - Detect call type                        │  │
│  │    - Find CRM users (src/dst match)          │  │
│  │    - Skip internal calls                     │  │
│  │    - Determine direction & status            │  │
│  └──────────────────┬───────────────────────────┘  │
│                     │                              │
│                     ▼                              │
│  ┌──────────────────────────────────────────────┐  │
│  │ 4. Create AutoCrmCalls record                │  │
│  │    status = 'pending'                        │  │
│  │    Save new CDR offset                       │  │
│  └──────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────┘
```

### Phase 2: Upload to API

```
┌────────────────────────────────────────────────────┐
│         CallUploader (if sync_calls_enabled)       │
│  ┌──────────────────────────────────────────────┐  │
│  │ 1. Get pending calls from AutoCrmCalls       │  │
│  │    WHERE sync_status = 'pending'             │  │
│  └──────────────────┬───────────────────────────┘  │
│                     │                              │
│                     ▼                              │
│  ┌──────────────────────────────────────────────┐  │
│  │ 2. For each call:                            │  │
│  │    - Find CRM user by extension              │  │
│  │    - Get user's autosalon_id                 │  │
│  │    - Build recording URL                     │  │
│  │    - Build API payload                       │  │
│  └──────────────────┬───────────────────────────┘  │
│                     │                              │
│                     ▼                              │
│  ┌──────────────────────────────────────────────┐  │
│  │ 3. POST /call to AutoCRM API                 │  │
│  │                                              │  │
│  │    ┌─────────────────┐  ┌─────────────────┐  │  │
│  │    │    Success      │  │     Error       │  │  │
│  │    │ status='sent'   │  │ status='error'  │  │  │
│  │    │ crm_call_id=ID  │  │ sync_error=msg  │  │  │
│  │    │                 │  │ retry_count++   │  │  │
│  │    └─────────────────┘  └─────────────────┘  │  │
│  └──────────────────────────────────────────────┘  │
│                                                    │
│  ┌──────────────────────────────────────────────┐  │
│  │ 4. Retry failed calls (retry_count < 3)      │  │
│  └──────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────┘
```

## User Synchronization Flow

```
┌──────────────────┐
│   Cron Trigger   │
│ (Daily 3:00 AM)  │
└────────┬─────────┘
         │
         ▼
┌────────────────────────────────────────────────────┐
│              sync-users.php                        │
│  ┌──────────────────────────────────────────────┐  │
│  │ 1. Check sync_users_enabled = '1'            │  │
│  └──────────────────┬───────────────────────────┘  │
│                     │                              │
│                     ▼                              │
│  ┌──────────────────────────────────────────────┐  │
│  │ 2. GET /user from AutoCRM API                │  │
│  │    Response: [{id, person, phone, ...}, ...] │  │
│  └──────────────────┬───────────────────────────┘  │
│                     │                              │
│                     ▼                              │
│  ┌──────────────────────────────────────────────┐  │
│  │ 3. For each user:                            │  │
│  │    - Find by crm_user_id or create new       │  │
│  │    - Skip if is_local = 1                    │  │
│  │    - Normalize phone numbers (last 10 dig)   │  │
│  │    - Store all phones in JSON array          │  │
│  │    - Update last_sync timestamp              │  │
│  └──────────────────┬───────────────────────────┘  │
│                     │                              │
│                     ▼                              │
│  ┌──────────────────────────────────────────────┐  │
│  │ 4. Update m_ModuleAutoCRM.last_users_sync    │  │
│  │    Log: created/updated/error counts         │  │
│  └──────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────┘
```

## API Communication

### Request/Response Flow

```
┌─────────────────┐                    ┌─────────────────┐
│ AutoCrmApiClient│                    │   AutoCRM API   │
└────────┬────────┘                    └────────┬────────┘
         │                                      │
         │  POST /call                          │
         │  Authorization: Bearer {token}       │
         │  Content-Type: application/json      │
         │ ─────────────────────────────────────▶
         │  {                                   │
         │    "entry_id": "1742368258.4",       │
         │    "call_id": "mikopbx-xxx_201",     │
         │    "from": "74952290001",            │
         │    "to": "201",                      │
         │    "datetime": "2026-01-26 21:50:32",│
         │    "status": "answered",             │
         │    "direction": "incoming",          │
         │    "user_id": 42,                    │
         │    "autosalon_id": 5,                │
         │    "record": "https://..."           │
         │  }                                   │
         │                                      │
         │ ◀─────────────────────────────────────
         │  HTTP 200 OK                         │
         │  {                                   │
         │    "status": 1,                      │
         │    "result": {"id": 12345}           │
         │  }                                   │
         │                                      │
```

### Retry Logic

```
┌─────────────────────────────────────────────────────┐
│                  API Request                        │
└──────────────────────┬──────────────────────────────┘
                       │
                       ▼
              ┌────────────────┐
              │  HTTP Request  │
              └───────┬────────┘
                      │
         ┌────────────┴────────────┐
         │                         │
         ▼                         ▼
┌─────────────────┐      ┌─────────────────┐
│   Success (2xx) │      │     Error       │
└────────┬────────┘      └────────┬────────┘
         │                        │
         ▼               ┌────────┴────────┐
    Return result        │                 │
                         ▼                 ▼
               ┌─────────────────┐ ┌─────────────────┐
               │ Retriable Error │ │  Client Error   │
               │ (5xx, network)  │ │  (4xx)          │
               └────────┬────────┘ └────────┬────────┘
                        │                   │
                        ▼                   ▼
               ┌─────────────────┐    Throw Exception
               │ Attempt < 3?   │
               └───────┬────────┘
                       │
            ┌──────────┴──────────┐
            │ Yes                 │ No
            ▼                     ▼
    Sleep(attempt_num)     Throw Exception
    Retry request
```

## Recording Playback

```
┌────────────┐     ┌──────────────────────────┐     ┌───────────────┐
│  Browser   │     │  AutoCRMApiController    │     │  File System  │
│  (CRM)     │     │  recordsAction()         │     │  (Recordings) │
└─────┬──────┘     └────────────┬─────────────┘     └───────┬───────┘
      │                         │                           │
      │  GET /records?view=path │                           │
      │  Range: bytes=0-1024    │                           │
      │ ────────────────────────▶                           │
      │                         │                           │
      │                         │  Check file exists        │
      │                         │ ─────────────────────────▶
      │                         │                           │
      │                         │ ◀─────────────────────────
      │                         │  File metadata            │
      │                         │                           │
      │                         │  Validate format          │
      │                         │  (mp3, wav, webm)         │
      │                         │                           │
      │ ◀────────────────────────                           │
      │  HTTP 206 Partial Content                           │
      │  Content-Type: audio/mpeg                           │
      │  Content-Range: bytes 0-1024/total                  │
      │  [audio data...]                                    │
      │                         │                           │
```

## User Matching Algorithm

```
┌─────────────────────────────────────────────────────┐
│           findUserByNumber($number)                 │
└──────────────────────┬──────────────────────────────┘
                       │
                       ▼
              ┌─────────────────┐
              │ strlen($number) │
              └───────┬─────────┘
                      │
         ┌────────────┴────────────┐
         │ <= 4                    │ > 4
         ▼                         ▼
┌─────────────────┐      ┌─────────────────┐
│ Search extension│      │ Normalize phone │
│ asterisk_ext    │      │ (last 10 digits)│
└────────┬────────┘      └────────┬────────┘
         │                        │
         ▼                        ▼
┌─────────────────┐      ┌─────────────────┐
│ Found?          │      │ Search phone    │
│                 │      │ OR work_phone   │
└───────┬─────────┘      └────────┬────────┘
        │                         │
   Yes  │  No                     ▼
   ─────┼──────────▶    ┌─────────────────┐
        │               │ Found?          │
        ▼               └───────┬─────────┘
   Return user                  │
                           Yes  │  No
                           ─────┼───────────▶ null
                                │
                                ▼
                           Return user
```

## Cron Schedule

```
┌───────────────────────────────────────────────────────────────────┐
│                        CRON SCHEDULE                              │
├───────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐  │
│  │ */1 * * * *   sync-calls.php                                │  │
│  │               └─ Every minute                               │  │
│  │               └─ Collects CDR, uploads if enabled           │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐  │
│  │ 0 3 * * *     sync-users.php                                │  │
│  │               └─ Daily at 3:00 AM                           │  │
│  │               └─ Runs only if sync_users_enabled='1'        │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐  │
│  │ 5 3 * * *     sync-salons.php                               │  │
│  │               └─ Daily at 3:05 AM                           │  │
│  │               └─ Always runs                                │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                                                                   │
└───────────────────────────────────────────────────────────────────┘
```

## Call Status State Machine

```
                    ┌──────────────────┐
                    │                  │
                    │    CDR Record    │
                    │    (New Call)    │
                    │                  │
                    └────────┬─────────┘
                             │
                             │ HistoryParser
                             │ creates record
                             ▼
                    ┌──────────────────┐
                    │                  │
                    │     PENDING      │
                    │                  │
                    └────────┬─────────┘
                             │
              ┌──────────────┴──────────────┐
              │                             │
              │ CallUploader.uploadCall()   │
              │                             │
              ▼                             ▼
    ┌──────────────────┐          ┌──────────────────┐
    │                  │          │                  │
    │      SENT        │          │      ERROR       │
    │  crm_call_id set │          │  sync_error set  │
    │                  │          │  retry_count++   │
    └────────┬─────────┘          └────────┬─────────┘
             │                             │
             │ updateCall()                │ retry < 3?
             ▼                             │
    ┌──────────────────┐          ┌────────┴─────────┐
    │                  │          │ Yes              │ No
    │   SENT (updated) │          ▼                  ▼
    │                  │      Back to             Stay in
    └──────────────────┘      PENDING             ERROR
```

## API Payload Structure

### Create Call (POST /call)

```json
{
    "entry_id": "1742368258.4",
    "call_id": "mikopbx-1742368258.4_201",
    "from": "74952290001",
    "to": "201",
    "datetime": "2026-01-26 21:50:32",
    "datetime_end": "2026-01-26 21:52:15",
    "datetime_answer": "2026-01-26 21:50:45",
    "status": "answered",
    "direction": "incoming",
    "user_id": 42,
    "autosalon_id": 5,
    "record": "https://pbx.example.com/pbxcore/api/modules/ModuleAutoCRM/records?view=/storage/..."
}
```

### Field Mapping

| API Field | Source |
|-----------|--------|
| `entry_id` | CDR.uniqueid |
| `call_id` | `mikopbx-{uniqueid}_{extension}` |
| `from` | CDR.src (caller number) |
| `to` | CDR.dst (destination number) |
| `datetime` | CDR.start |
| `datetime_end` | CDR.end |
| `datetime_answer` | CDR.answer (from CDR.db query) |
| `status` | `billsec > 0 ? 'answered' : 'missed'` |
| `direction` | User in src → 'outgoing', else 'incoming' |
| `user_id` | AutoCrmUsers.crm_user_id |
| `autosalon_id` | AutoCrmUsers.salon OR settings.autosalon_id |
| `record` | Built from pbx_host + record_path |

## File Paths

| Path | Description |
|------|-------------|
| `/storage/usbdisk1/mikopbx/custom_modules/ModuleAutoCRM/` | Module directory |
| `/storage/usbdisk1/mikopbx/custom_modules/ModuleAutoCRM/db/module.db` | SQLite database |
| `/storage/usbdisk1/mikopbx/logs/ModuleAutoCRM/` | Log files |
| `/storage/usbdisk1/mikopbx/astlogs/asterisk/cdr.db` | CDR database |
| `/storage/usbdisk1/mikopbx/astlogs/asterisk/{date}/` | Recording files |
| `/usr/www/src/Core/Config/Globals.php` | MikoPBX bootstrap |

## Phalcon Version Compatibility

The module uses `MikoPBXVersion` helper for cross-version compatibility:

| Feature | Phalcon 4.x | Phalcon 5.x |
|---------|-------------|-------------|
| DI Container | `\Phalcon\Di::getDefault()` | `\Phalcon\Di\Di::getDefault()` |
| Logger | `\Phalcon\Logger` | `\Phalcon\Logger\Logger` |
| Validation | `\Phalcon\Validation` | `\Phalcon\Filter\Validation` |
| Uniqueness | `\Phalcon\Validation\Validator\Uniqueness` | `\Phalcon\Filter\Validation\Validator\Uniqueness` |
| Text Helper | `\Phalcon\Text` | `\MikoPBX\Common\Library\Text` |

## Security Considerations

1. **API Token Storage** - Token stored in database, never in code
2. **HTTPS Enforcement** - Recording URLs use configured protocol
3. **Range Requests** - Prevents large file downloads
4. **File Validation** - Only allowed formats (mp3, wav, webm)
5. **Local User Protection** - `is_local=1` users not deleted during sync
6. **No Data Deletion** - CDR and call records never deleted

## Error Handling

| Error Type | Handling |
|------------|----------|
| Network Error | Retry up to 3 times with backoff |
| API 4xx Error | No retry, log error |
| API 5xx Error | Retry with backoff |
| Missing User | Log warning, skip call |
| Missing Recording | Proceed without recording URL |
| Invalid API Response | Throw `AutoCrmApiException` |
