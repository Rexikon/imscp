<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2018 by Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace iMSCP;

use iMSCP\Authentication\AuthenticationService;
use iMSCP\Functions\View;

require_once 'application.php';

Application::getInstance()->getAuthService()->checkIdentity(AuthenticationService::ADMIN_IDENTITY_TYPE);
Application::getInstance()->getEventManager()->trigger(Events::onAdminScriptStart);

isset($_GET['domain_id']) or View::showBadRequestErrorPage();

$domainId = intval($_GET['domain_id']);
$stmt = execQuery('SELECT domain_admin_id, domain_status FROM domain WHERE domain_id = ?', [$domainId]);
$stmt->rowCount() or View::showBadRequestErrorPage();
$row = $stmt->fetch();

if ($row['domain_status'] == 'ok') {
    changeDomainStatus($row['domain_admin_id'], 'deactivate');
} elseif ($row['domain_status'] == 'disabled') {
    changeDomainStatus($row['domain_admin_id'], 'activate');
} else {
    View::showBadRequestErrorPage();
}

redirectTo('users.php');
