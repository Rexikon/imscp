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
use iMSCP\Model\CpSuIdentityInterface;

require_once 'application.php';

Application::getInstance()->getAuthService()->checkIdentity(AuthenticationService::USER_IDENTITY_TYPE);
Application::getInstance()->getEventManager()->trigger(Events::onClientScriptStart);
Application::getInstance()->getAuthService()->su(Application::getInstance()->getRequest()->getQuery('id'));
$identity = Application::getInstance()->getAuthService()->getIdentity();

if (Application::getInstance()->getRequest()->getQuery('id')) {
    $log = sprintf("%s switched onto %s's interface", $identity->getSuUsername(), $identity->getUsername());
} elseif ($identity instanceof CpSuIdentityInterface) {
    $log = sprintf("%s switched back onto %s's interface", $identity->getSuUsername(), $identity->getUsername());
} else {
    $log = sprintf("%s switched back into its interface", $identity->getUsername());
}

writeLog($log, E_USER_NOTICE);
unsetMessages();
Application::getInstance()->getAuthService()->redirectToUserUi('users.php');
