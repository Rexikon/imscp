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
use iMSCP\Functions\Mail;
use iMSCP\Functions\View;

require_once 'application.php';

Application::getInstance()->getAuthService()->checkIdentity(AuthenticationService::ADMIN_IDENTITY_TYPE);
Application::getInstance()->getEventManager()->trigger(Events::onAdminScriptStart);

if (isset($_POST['uaction']) && $_POST['uaction'] == 'apply') {
    $errorMessage = '';
    $activationEmailData['emailSubject'] = isset($_POST['subject1']) ? cleanInput($_POST['subject1']) : '';
    $activationEmailData['emailBody'] = isset($_POST['subject1']) ? cleanInput($_POST['message1']) : '';
    $passwordEmailData['emailSubject'] = isset($_POST['subject1']) ? cleanInput($_POST['subject2']) : '';
    $passwordEmailData['emailBody'] = isset($_POST['subject1']) ? cleanInput($_POST['message2']) : '';

    if (empty($activationEmailData['subject']) || empty($passwordEmailData['subject'])) {
        $errorMessage = tr('Please specify a message subject.');
    }

    if (empty($activationEmailData['emailBody']) || empty($passwordEmailData['emailBody'])) {
        $errorMessage = tr('Please specify a message content.');
    }

    if (!empty($errorMessage)) {
        View::setPageMessage($errorMessage, 'error');
    } else {
        Mail::setLostpasswordActivationEmail(null, $activationEmailData);
        Mail::setLostpasswordEmail(0, $passwordEmailData);
        View::setPageMessage(tr('Lost password email templates were updated.'), 'success');
        redirectTo('settings_lostpassword.php');
    }
} else {
    $userId = $identity = Application::getInstance()->getAuthService()->getIdentity()->getUserId();
    $activationEmailData = Mail::getLostpasswordActivationEmail($userId);
    $passwordEmailData = Mail::getLostpasswordEmail($userId);
}

$tpl = new TemplateEngine();
$tpl->define([
    'layout'       => 'shared/layouts/ui.tpl',
    'page'         => 'admin/settings_lostpassword.tpl',
    'page_message' => 'layout'
]);
$tpl->assign([
    'TR_PAGE_TITLE'               => tr('Admin / Settings / Lost Password Email'),
    'TR_LOSTPW_EMAIL'             => tr('Lost password email'),
    'TR_MESSAGE_TEMPLATE_INFO'    => tr('Message template info'),
    'TR_MESSAGE_TEMPLATE'         => tr('Message template'),
    'SUBJECT_VALUE1'              => toHtml($activationEmailData['subject']),
    'MESSAGE_VALUE1'              => toHtml($activationEmailData['message']),
    'SUBJECT_VALUE2'              => toHtml($passwordEmailData['subject']),
    'MESSAGE_VALUE2'              => toHtml($passwordEmailData['message']),
    'SENDER_EMAIL_VALUE'          => toHtml($activationEmailData['sender_email']),
    'SENDER_NAME_VALUE'           => toHtml($activationEmailData['sender_name']),
    'TR_ACTIVATION_EMAIL'         => tr('Activation email'),
    'TR_PASSWORD_EMAIL'           => tr('Password email'),
    'TR_USER_LOGIN_NAME'          => tr('User login (system) name'),
    'TR_USER_PASSWORD'            => tr('User password'),
    'TR_USER_REAL_NAME'           => tr('User (first and last) name'),
    'TR_LOSTPW_LINK'              => tr('Lost password link'),
    'TR_SUBJECT'                  => tr('Subject'),
    'TR_MESSAGE'                  => tr('Message'),
    'TR_SENDER_EMAIL'             => tr('Reply-To email'),
    'TR_SENDER_NAME'              => tr('Reply-To name'),
    'TR_APPLY_CHANGES'            => tr('Apply changes'),
    'TR_BASE_SERVER_VHOST_PREFIX' => tr('URL protocol'),
    'TR_BASE_SERVER_VHOST'        => tr('URL to this admin panel'),
    'TR_BASE_SERVER_VHOST_PORT'   => tr('URL port')
]);
View::generateNavigation($tpl);
View::generatePageMessages($tpl);
$tpl->parse('LAYOUT_CONTENT', 'page');
Application::getInstance()->getEventManager()->trigger(Events::onAdminScriptEnd, NULL, ['templateEngine' => $tpl]);
$tpl->prnt();
unsetMessages();
