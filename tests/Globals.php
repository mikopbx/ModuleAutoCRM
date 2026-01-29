<?php
/**
 * Placeholder for Globals.php
 *
 * On MikoPBX server this file should be a symlink to:
 * /usr/www/src/Core/Config/Globals.php
 *
 * Create symlink on server:
 * ln -sf /usr/www/src/Core/Config/Globals.php /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoCRM/tests/Globals.php
 */

// This will fail if running locally - tests must run on MikoPBX server
if (!file_exists('/usr/www/src/Core/Config/Globals.php')) {
    die("ERROR: Tests must be run on MikoPBX server. See CLAUDE.md for instructions.\n");
}

require_once '/usr/www/src/Core/Config/Globals.php';
