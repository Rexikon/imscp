<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2018 Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */

namespace iMSCP;

use iMSCP\Update\Database;

try {
    chdir(dirname(__FILE__));
    require_once '../../frontend/library/include/application.php';
    $dbUpdater = new Database();

    if ($dbUpdater->getLastAppliedUpdate() > $dbUpdater->getLastUpdate()) {
        throw new \RuntimeException("A downgrade attempt has been detected. Downgrade to an older version isn't supported by the i-MSCP installer.");
    }

    if (!$dbUpdater->applyUpdates()) {
        throw new \RuntimeException($dbUpdater->getError());
    }

    buildLanguagesIndex();
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf("[ERROR] %s \n\nStack trace:\n%s\n", $e->getMessage(), $e->getTraceAsString()));
    exit(1);
}
