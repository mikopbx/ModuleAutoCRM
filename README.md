# ModuleAutoCRM for MikoPBX

Integration module for synchronizing MikoPBX call history with AutoCRM system.

## Overview

ModuleAutoCRM automatically collects call records from your MikoPBX system and uploads them to AutoCRM. The module tracks incoming and outgoing calls, matches them with CRM users, and provides recording playback URLs.

## Features

- **Automatic Call Synchronization** - Collects calls every minute from CDR and uploads to CRM
- **User Synchronization** - Daily sync of CRM users with their phone numbers and extensions
- **Autosalon Management** - Syncs autosalons from CRM for proper call binding
- **Recording Playback** - Provides secure URLs for call recording playback in CRM
- **Retry Logic** - Automatic retry for failed uploads (up to 3 attempts)
- **Web Interface** - Admin panel for configuration and monitoring

## Requirements

- MikoPBX version 2023.2.150 or higher
- PHP 7.4 or PHP 8.x
- Active AutoCRM account with API access

## Installation

1. Download the module ZIP file
2. Go to MikoPBX Admin Panel > Modules > Module Management
3. Click "Install from file" and select the ZIP
4. Enable the module after installation

## Configuration

### Basic Settings

Navigate to **Modules > AutoCRM** in your MikoPBX admin panel.

| Setting | Description |
|---------|-------------|
| **API URL** | AutoCRM API server address (e.g., `motorland.autocrm.ru`) |
| **API Token** | Bearer token for API authentication (obtain from AutoCRM) |
| **Default Autosalon** | Fallback autosalon for calls when user has no assigned salon |
| **PBX Protocol** | HTTP or HTTPS for recording URLs |
| **PBX Host** | Public hostname of your PBX server |

### Synchronization Options

| Option | Description |
|--------|-------------|
| **Enable User Sync** | Automatically sync users daily at 3:00 AM |
| **Enable Call Sync** | Upload calls to CRM (every minute) |

### Manual Sync

You can trigger synchronization manually:
- Click "Sync Users" to fetch users from CRM
- Click "Sync Salons" to fetch autosalons from CRM
- Individual calls can be uploaded by clicking their status in the Calls tab

## How It Works

### Call Collection

1. Every minute, the module scans MikoPBX CDR for new calls
2. Calls are grouped by `linkedid` (conversation ID)
3. Module identifies AutoCRM users involved in each call
4. Internal calls (both parties are extensions) are skipped
5. New call records are created with "pending" status

### Call Upload

1. For each pending call, the module:
   - Finds the CRM user by their extension
   - Determines call direction (incoming/outgoing)
   - Builds a public URL for the recording
   - Sends call data to AutoCRM API
2. Successful uploads are marked as "sent"
3. Failed uploads are retried up to 3 times

### User Matching

Users are matched by:
- Internal extension (e.g., "201")
- Mobile phone number
- Work phone number

Phone numbers are normalized (last 10 digits) for accurate matching.

## Tabs in Admin Panel

### Users Tab

Displays all synced CRM users with:
- Name and contact information
- Assigned autosalons
- Internal extension
- Active/inactive status

### Salons Tab

Shows all autosalons from CRM:
- Salon name
- Address
- Contact phone

### Calls Tab

Lists synchronized calls with:
- Date and time
- Direction (incoming/outgoing)
- Phone numbers
- Status (answered/missed)
- Sync status (pending/sent/error)

Click on sync status to manually upload or update a call.

## Recording Playback

The module provides secure recording URLs in the format:
```
https://{pbx_host}/pbxcore/api/modules/ModuleAutoCRM/records?view={recording_path}
```

Features:
- HTTP Range request support for seeking
- Supported formats: MP3, WAV, WebM
- Direct playback in browser

## Troubleshooting

### Calls Not Syncing

1. Check if "Enable Call Sync" is enabled in settings
2. Verify API token is correct
3. Check module logs at: `/storage/usbdisk1/mikopbx/logs/ModuleAutoCRM/`

### Users Not Found

1. Run manual user sync
2. Verify user has phone number or extension in CRM
3. Check if user's status is "active" in CRM

### Recording URLs Not Working

1. Verify "PBX Host" is the public address of your server
2. Check "PBX Protocol" matches your server configuration (HTTP/HTTPS)
3. Ensure the recording file exists on disk

## API Endpoints

The module exposes REST API endpoints:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/pbxcore/api/modules/ModuleAutoCRM/records` | GET | Playback recording |
| `/pbxcore/api/modules/ModuleAutoCRM/sync-users` | GET | Trigger user sync |
| `/pbxcore/api/modules/ModuleAutoCRM/sync-calls` | GET | Trigger call sync |
| `/pbxcore/api/modules/ModuleAutoCRM/sync-salons` | GET | Trigger salon sync |

## Cron Schedule

| Schedule | Task |
|----------|------|
| Every minute | Call synchronization |
| Daily 3:00 AM | User synchronization |
| Daily 3:05 AM | Autosalon synchronization |

## Support

- Version: 1.0.0
- Developer: MIKO LLC
- Minimum PBX Version: 2023.2.150

For issues and feature requests, please contact MIKO support.

## License

Copyright MIKO LLC. All rights reserved.
